#include <Arduino.h>
#include <Preferences.h>

#include "api.h"
#include "buzzer.h"
#include "calibration.h"
#include "config.h"
#include "gps.h"
#include "modem.h"
#include "motion.h"
#include "outbox.h"
#include "owner.h"
#include "thresholds.h"
#include "settings.h"
#include "sms.h"
#include "threat.h"

enum class State { Disarmed, Armed, Alert };

static State state = State::Armed;
static Preferences prefs;
static bool motionReady = false;

static unsigned long lastHeartbeatMs = 0;
static unsigned long lastPingMs = 0;
static int pingFailures = 0;
static unsigned long serverLostSinceMs = 0;
static String lastNetwork;
static unsigned long lastGpsStatusMs = 0;
static unsigned long lastLocationMs = 0;
static unsigned long lastBatteryCheckMs = 0;
static unsigned long lastSmsMs = 0;
static bool smsEverSent = false;
static bool lowBatteryReported = false;
static bool powerCutReported = false;
static float batteryVolts = 0;

static const char* stateName(State s) {
    switch (s) {
        case State::Disarmed: return "DISARMED";
        case State::Armed: return "ARMED";
        case State::Alert: return "ALERT";
    }
    return "?";
}

// Lowercase form the device API expects in the heartbeat payload.
static const char* stateApiName(State s) {
    switch (s) {
        case State::Disarmed: return "disarmed";
        case State::Armed: return "armed";
        case State::Alert: return "alert";
    }
    return "armed";
}

static void setState(State next) {
    if (state == next) {
        return;
    }
    Serial.printf("[state] %s -> %s\n", stateName(state), stateName(next));
    state = next;
    threatSetArmed(next != State::Disarmed);
    if (next != State::Alert) {
        buzzerStop();
    }
    if (next == State::Alert) {
        lastLocationMs = 0;             // start tracking at the fast interval right away
    }

    if (next == State::Armed && motionReady) {
        motionCalibrate();
    }
}

// While a calibration runs the bike must be still, so its samples measure noise instead of
// feeding the classifier (which would alarm at the owner's own hand walking away).
static void onMotionSample(const MotionSample& sample) {
    if (calibrationActive()) {
        calibrationFeed(sample);
        return;
    }
    threatOnSample(sample);
}

// A finished calibration waits here until the server has it, so a dropped request is retried
// instead of leaving the dashboard showing "waiting" forever.
static CalibrationResult pendingCalibration;
static bool calibrationUnsent = false;
static unsigned long lastCalibrationSendMs = 0;

static void serviceCalibration(unsigned long now) {
    CalibrationResult result;
    if (calibrationFinished(result)) {
        prefs.putFloat("calJerk", result.joltThreshold);
        prefs.putFloat("calPush", result.pushAccel);
        prefs.putFloat("calRumble", result.pushRumble);
        pendingCalibration = result;
        calibrationUnsent = true;
        lastCalibrationSendMs = 0;
    }
    if (calibrationUnsent && (lastCalibrationSendMs == 0 || now - lastCalibrationSendMs >= 10000)) {
        lastCalibrationSendMs = now;
        calibrationUnsent = !apiSendCalibration(pendingCalibration);
    }
}

static void applyArmed(bool armed) {
    if (prefs.getBool("armed", true) != armed) {
        prefs.putBool("armed", armed);
    }

    if (!armed) {
        setState(State::Disarmed);
    } else if (state == State::Disarmed && !ownerNearby()) {
        setState(State::Armed);
    }
}

// The number to text, as set on the dashboard, kept so alerts can be sent with no connection.
static String ownerPhone;

static void applyOwnerPhone(const String& phone) {
    if (phone == ownerPhone) {
        return;
    }
    ownerPhone = phone;
    prefs.putString("ownerPhone", phone);
    Serial.printf("[sms] Owner number %s\n", phone.length() > 0 ? "updated from the dashboard" : "removed");
}

static void applyOwnerBeacon(const String& key) {
    if (prefs.getString("ownerKey", "") == key) {
        return;
    }
    prefs.putString("ownerKey", key);
    ownerSetKey(key);
}

// While the owner's phone is in Bluetooth range the device stands down: no motion alarms, and an
// alarm already sounding stops. It is not the dashboard's arm switch - that stays as the owner set
// it - so walking away re-arms on its own.
static bool ownerWasNearby = false;

static void checkOwner() {
    bool nearby = ownerNearby();
    if (nearby == ownerWasNearby) {
        return;
    }
    ownerWasNearby = nearby;
    lastHeartbeatMs = 0;  // tell the dashboard now rather than at the next 30 s heartbeat

    if (nearby) {
        Serial.printf("[owner] owner nearby (RSSI %d) - alarms held off\n", ownerRssi());
        if (state != State::Disarmed) {
            setState(State::Disarmed);
            buzzerBeep(1);
        }
    } else {
        Serial.println("[owner] owner gone - re-arming");
        if (prefs.getBool("armed", true) && state == State::Disarmed) {
            setState(State::Armed);
            buzzerBeep(2);
        }
    }
}

// Only a theft attempt (or a non-motion alert such as a power cut) texts the owner; minor and
// suspicious episodes are for the dashboard, which is how classification cuts false alarms.
static void raiseAlert(const char* type, ThreatLevel level, const String& reason,
                       const ThreatReport* evidence = nullptr) {
    GpsFix fix = gpsFix();
    bool ownerNotified = false;
    bool textOwner = level == ThreatLevel::None || level == ThreatLevel::TheftAttempt;

    if (!textOwner) {
        // Nothing to send, and reporting false keeps the server from texting either.
    } else if (smsEverSent && millis() - lastSmsMs < SMS_COOLDOWN_MS) {
        // The owner was texted moments ago; report it as handled so the server doesn't text again.
        ownerNotified = true;
    } else {
        String message = "MotoGuard+ ALERT: " + reason + ". ";
        message += fix.valid
            ? "https://maps.google.com/?q=" + String(fix.lat, 6) + "," + String(fix.lng, 6)
            : String("GPS location not available yet.");

        if (ownerPhone.length() == 0) {
            Serial.println("[sms] No text sent: add the owner's phone number on the dashboard (device page, Details)");
        } else {
            ownerNotified = netSendSms(ownerPhone.c_str(), message);
        }
        if (ownerNotified) {
            smsEverSent = true;
            lastSmsMs = millis();
        }
    }

    AlertRecord record = {};
    strlcpy(record.type, type, sizeof(record.type));
    const char* levelName = threatLevelApiName(level);
    strlcpy(record.level, levelName ? levelName : "", sizeof(record.level));
    record.hasFix = fix.valid;
    record.lat = fix.lat;
    record.lng = fix.lng;
    record.smsSent = ownerNotified;
    if (evidence) {
        record.hasEvidence = true;
        record.knocks = evidence->knocks;
        record.jolts = evidence->jolts;
        record.maxTiltDeg = evidence->maxTiltDeg;
        record.durationMs = evidence->durationMs;
    }
    record.atMs = millis();
    record.bootId = outboxBootId();
    strlcpy(record.gpsTime, fix.timestamp, sizeof(record.gpsTime));

    // Delivered now if the server can be reached; otherwise kept and delivered when it can.
    // A 422 means the server will never accept it, so there is no point keeping that one.
    bool online = netNetworkName().length() > 0 && settingsServer().token.length() > 0;
    int status = online ? apiSendAlert(record, false) : 0;
    if (!(status >= 200 && status < 300) && status != 422) {
        outboxAddAlert(record);
        Serial.printf("[outbox] No connection: alert kept for later (%d waiting)\n", outboxAlertCount());
    }
}

static void reportThreat(const ThreatReport& report) {
    Serial.printf("[alarm] %s: %s (knocks %u, jolts %u, max tilt %.1f deg, %lu ms)\n",
                  threatLevelApiName(report.level), report.type, report.knocks, report.jolts,
                  (double) report.maxTiltDeg, (unsigned long) report.durationMs);

    // The SMS says what actually happened, so the owner knows how urgent it is before opening the map.
    const char* what = strcmp(report.type, "movement") == 0 ? "moved or tampered with" : "touched or bumped";

    char reason[96];
    switch (report.level) {
        case ThreatLevel::TheftAttempt:
            snprintf(reason, sizeof(reason), "Possible theft attempt - your motorcycle is being %s", what);
            break;
        case ThreatLevel::Suspicious:
            snprintf(reason, sizeof(reason), "Suspicious activity - your motorcycle was %s", what);
            break;
        default:
            snprintf(reason, sizeof(reason), "Minor - your motorcycle was %s", what);
            break;
    }
    raiseAlert(report.type, report.level, reason, &report);
}

static float readBatteryVolts() {
#if BATTERY_MONITOR_ENABLED
    return (analogReadMilliVolts(PIN_BATTERY_ADC) / 1000.0f) * BATTERY_DIVIDER_RATIO;
#else
    return 0;
#endif
}

static void checkBattery(unsigned long now) {
#if BATTERY_MONITOR_ENABLED
    if (lastBatteryCheckMs != 0 && now - lastBatteryCheckMs < 5000) {
        return;
    }
    lastBatteryCheckMs = now;
    batteryVolts = readBatteryVolts();

    if (batteryVolts < POWER_CUT_VOLTS) {
        if (!powerCutReported && state != State::Disarmed) {
            powerCutReported = true;
            buzzerBeep(BUZZER_ALERT_BEEPS);
            raiseAlert("power_cut", ThreatLevel::TheftAttempt, "Motorcycle battery was disconnected");
        }
        return;
    }
    powerCutReported = false;

    if (batteryVolts < LOW_BATTERY_VOLTS && !lowBatteryReported) {
        lowBatteryReported = true;
        raiseAlert("low_battery", ThreatLevel::None, "Motorcycle battery is low (" + String(batteryVolts, 1) + " V)");
    } else if (batteryVolts > LOW_BATTERY_VOLTS + 0.3f) {
        lowBatteryReported = false;
    }
#endif
}

void setup() {
    Serial.begin(115200);
    delay(200);
    Serial.println("\nMotoGuard+ starting");

    Serial.println("[threat] movement classification: minor / suspicious / theft attempt");
    buzzerBegin();
    threatBegin();

    prefs.begin("motoguard", false);
    bool armed = prefs.getBool("armed", true);
    if (prefs.isKey("calJerk")) {
        // Clamped again on load, so tightening the limits in config.h applies to a unit that was
        // calibrated before the change without calibrating it again.
        thresholdsSet(
            constrain(prefs.getFloat("calJerk", MOTION_JERK_THRESHOLD), CALIBRATION_MIN_JERK, CALIBRATION_MAX_JERK),
            constrain(prefs.getFloat("calPush", MOTION_PUSH_ACCEL), CALIBRATION_MIN_PUSH_ACCEL, CALIBRATION_MAX_PUSH_ACCEL),
            constrain(prefs.getFloat("calRumble", MOTION_PUSH_RUMBLE), CALIBRATION_MIN_PUSH_RUMBLE, CALIBRATION_MAX_PUSH_RUMBLE));
        Serial.printf("[motion] calibrated thresholds: jolt %.2f, push %.2f / %.2f m/s^2\n",
                      (double) thresholdJerk(), (double) thresholdPushAccel(),
                      (double) thresholdPushRumble());
    }

    gpsBegin();

    motionReady = motionBegin();

    settingsBegin();
    outboxBegin();
    ownerPhone = prefs.getString("ownerPhone", "");
    smsBegin();
    netBegin();

    ownerBegin();
    ownerSetKey(prefs.getString("ownerKey", ""));

    state = armed ? State::Armed : State::Disarmed;
    threatSetArmed(armed);
    if (motionReady) {
        // Calibrate even when disarmed: the task starts sampling now, and a zero baseline would
        // read as a huge tilt the moment the owner arms it.
        motionCalibrate();
        motionStart(onMotionSample);
    }
    Serial.printf("[state] %s\n", stateName(state));
}


/**
 * Periodic GPS health line. The module is otherwise completely silent, so without this a
 * backwards TX/RX pair and a cold start under a roof produce exactly the same symptom: nothing.
 * Bytes arriving proves the serial link; satellites without a fix means sky, not wiring.
 */
static void reportGps(unsigned long now) {
    if (lastGpsStatusMs != 0 && now - lastGpsStatusMs < GPS_STATUS_INTERVAL_MS) {
        return;
    }
    lastGpsStatusMs = now;

    GpsHealth health = gpsHealth();

    if (health.hasFix) {
        GpsFix fix = gpsFix();
        Serial.printf("[gps] %s %.6f, %.6f - %d of %d satellites, HDOP %.1f\n",
                      fix.accurate ? "fix" : "rough fix",
                      fix.lat, fix.lng, health.satellitesInUse, health.satellitesInView,
                      (double) fix.hdop);
        if (!fix.accurate) {
            Serial.printf("      not trusted yet: needs %d satellites and HDOP under %.1f\n",
                          GPS_MIN_SATELLITES, (double) GPS_MAX_HDOP);
        }
        return;
    }

    if (health.bytes == 0) {
        Serial.println("[gps] no data - check TX->GPIO26, RX->GPIO27 and 9600 baud");
        return;
    }

    if (health.satellitesInView == 0) {
        Serial.printf("[gps] no lock - 0 satellites in view, %lu bytes, %lu bad checksums, HDOP %.1f\n"
                      "      the receiver is talking but has not locked on: it needs a clear view of the sky.\n"
                      "      note that some modules never send GSV, so 0 in view can also mean 'not reported'\n",
                      health.bytes, health.checksumErrors, (double) health.hdop);
        return;
    }

    Serial.printf("[gps] acquiring - %d satellites in view, needs %d to fix (%lu bytes, HDOP %.1f)\n",
                  health.satellitesInView, GPS_MIN_SATELLITES, health.bytes, (double) health.hdop);
}

/**
 * A board with no token - or one the server stopped accepting - asks for a token using the ID
 * built into its chip. It keeps asking every 10 s until its owner has added that ID on the
 * dashboard, so the owner never types a code or a token.
 */
static bool enrollNeeded = false;

static void serviceEnrollment(unsigned long now) {
    static unsigned long lastTryMs = 0;
    static bool waitingSaid = false;

    bool noToken = settingsServer().token.length() == 0;
    if (!(noToken || enrollNeeded) || (lastTryMs != 0 && now - lastTryMs < 10000)) {
        return;
    }
    lastTryMs = now;

    switch (apiEnroll()) {
        case EnrollResult::Enrolled:
            Serial.printf("[enroll] Enrolled as %s\n", settingsDeviceId().c_str());
            enrollNeeded = false;
            waitingSaid = false;
            pingFailures = 0;
            lastPingMs = 0;
            lastHeartbeatMs = 0;
            buzzerBeep(2);
            break;
        case EnrollResult::NotAdded:
            if (!waitingSaid) {
                Serial.printf("[enroll] %s is not on any account yet. On the dashboard: Devices > Add device, and type this ID.\n",
                              settingsDeviceId().c_str());
                waitingSaid = true;
            }
            pingFailures = 0;   // the server answered, so it is reachable
            break;
        case EnrollResult::Refused:
            Serial.println("[enroll] The server could not verify this board (DEVICE_ENROLL_SECRET differs from the server's)");
            pingFailures = 0;
            break;
        case EnrollResult::Unreachable:
            // Counts like failed pings, so the search for a server on the local network starts.
            pingFailures += 3;
            break;
    }
}

/**
 * Delivers what was recorded while offline, oldest first and a little per pass, so a long
 * backlog never holds up the pings or the alarm.
 */
static void serviceOutbox(unsigned long now) {
    static unsigned long lastTryMs = 0;
    if (outboxAlertCount() == 0 && outboxLocationCount() == 0) {
        return;
    }
    if (now - lastTryMs < OUTBOX_RETRY_MS || pingFailures > 0
        || netNetworkName().length() == 0 || settingsServer().token.length() == 0) {
        return;
    }
    lastTryMs = now;

    AlertRecord alert;
    if (outboxPeekAlert(alert)) {
        int status = apiSendAlert(alert, true);
        if ((status >= 200 && status < 300) || status == 422) {
            outboxDropAlert();
            Serial.printf("[outbox] Delivered an alert recorded offline (%d left)\n", outboxAlertCount());
        }
        return;
    }

    static LocationRecord batch[OUTBOX_LOCATION_BATCH];
    int count = outboxPeekLocations(batch, OUTBOX_LOCATION_BATCH);
    int status = apiSendLocations(batch, count);
    if ((status >= 200 && status < 300) || status == 422) {
        outboxDropLocations(count);
        Serial.printf("[outbox] Delivered %d GPS point(s) recorded offline (%d left)\n", count, outboxLocationCount());
    }
}

void loop() {
    unsigned long now = millis();
    serviceCalibration(now);
    serviceEnrollment(now);
    smsUpdate(now);

    netUpdate();
    gpsUpdate();
    reportGps(now);

    if (motionReady) {
        // The classifier runs in the motion task and has already sounded the buzzer; this side
        // only does the slow part - the network - and mirrors the level into the device state.
        ThreatReport report;
        while (threatNextReport(report)) {
            reportThreat(report);
        }

        if (state != State::Disarmed) {
            setState(threatLevel() >= ThreatLevel::Suspicious ? State::Alert : State::Armed);
        }
    }

    checkOwner();
    checkBattery(now);

    // A server on the local network announces itself every 2 s. It is used only while the home
    // server (normally the online one) cannot be reached - no internet at the venue, or a laptop
    // demo - and only in memory, so the board goes back home as soon as home answers again.
    String announcedHost;
    uint16_t announcedPort = 0;
    if (netDiscoverPoll(announcedHost, announcedPort) && pingFailures >= 3) {
        const ServerSettings& active = settingsServer();
        if (announcedHost != active.host || announcedPort != active.port) {
            Serial.printf("[discover] %s:%u is not answering - using the server on this network, %s:%u\n",
                          active.host.c_str(), active.port, announcedHost.c_str(), announcedPort);
            settingsUseTemporary(announcedHost, announcedPort);
        } else {
            Serial.println("[discover] Server is announcing again - reporting in now");
        }
        pingFailures = 0;
        serverLostSinceMs = 0;
        lastPingMs = 0;
        lastHeartbeatMs = 0;
    }

    static unsigned long lastHomeTryMs = 0;
    if (settingsOnTemporary() && now - lastHomeTryMs >= HOME_RETRY_MS) {
        lastHomeTryMs = now;
        ServerSettings standIn = settingsServer();
        settingsUseHome();
        bool ignored = false;
        if (apiPing(ignored)) {
            Serial.println("[discover] Home server is answering again - back to it");
            pingFailures = 0;
            lastHeartbeatMs = 0;
        } else {
            settingsUseTemporary(standIn.host, standIn.port);
        }
    }

    bool enrolled = settingsServer().token.length() > 0;
    unsigned long pingInterval = settingsServer().port == 443 ? PRESENCE_PING_INTERVAL_TLS_MS : PRESENCE_PING_INTERVAL_MS;

    // Presence first: the badge depends on this landing on time, and it is far cheaper than the
    // heartbeat below, which writes to a database in another region.
    if (enrolled && (lastPingMs == 0 || now - lastPingMs >= pingInterval)) {
        lastPingMs = now;
        bool syncRequested = false;
        pingFailures = apiPing(syncRequested) ? 0 : pingFailures + 1;
        // Armed or disarmed on the dashboard: fetch it now instead of at the next 30 s heartbeat.
        // Throttled, so a heartbeat that fails cannot turn every 1 s ping into another one.
        if (syncRequested && now - lastHeartbeatMs >= 3000) {
            lastHeartbeatMs = 0;
        }

        // Joined a different network, or the server stopped answering (the PC changed IP): listen
        // for the server's announcement and follow it. Throttled, because listening blocks the loop.
        String network = netNetworkName();
        bool newNetwork = network.length() > 0 && network != lastNetwork;
        if (network.length() > 0) {
            lastNetwork = network;
        }
        if (newNetwork) {
            Serial.printf("[discover] Listening for the server on UDP %d\n", DISCOVERY_PORT);
        }

        // Connected, but the server is on another network (the laptop moved to the phone hotspot
        // while home WiFi is still in range): after a while, go and look for it on the other one.
        if (pingFailures == 0) {
            serverLostSinceMs = 0;
        } else if (serverLostSinceMs == 0) {
            serverLostSinceMs = now;
        } else if (now - serverLostSinceMs >= SERVER_LOST_SWITCH_MS) {
            serverLostSinceMs = now;
            if (network.length() > 0) {
                Serial.println("[net] Server unreachable on this network, trying the other one");
                netSwitchNetwork();
            }
        }
        // The ping blocks for as long as the server takes to answer, and the UART has been filling
        // the whole time. Drain it here rather than waiting for the next pass, so the heartbeat
        // below reports the position the receiver has now and not the one from before the stall.
        gpsUpdate();
    }

    if (enrolled && (lastHeartbeatMs == 0 || now - lastHeartbeatMs >= HEARTBEAT_INTERVAL_MS)) {
        lastHeartbeatMs = now;
        HeartbeatResult heartbeat = apiHeartbeat(stateApiName(state), batteryVolts, gpsFix(), ownerNearby());
        if (heartbeat.rejected) {
            enrollNeeded = true;
        }
        if (heartbeat.ok) {
            if (heartbeat.hasBackupWifi) {
                netSetBackupWifi(heartbeat.backupSsid, heartbeat.backupPass);
            }
            if (heartbeat.hasOwnerBeacon) {
                applyOwnerBeacon(heartbeat.ownerBeacon);
            }
            if (heartbeat.hasOwnerPhone) {
                applyOwnerPhone(heartbeat.ownerPhone);
            }
            applyArmed(heartbeat.armed);
            if (heartbeat.calibrate && motionReady && !calibrationActive() && !calibrationUnsent) {
                calibrationStart();
            }
        }
    }

    GpsFix fix = gpsFix();
    unsigned long locationInterval = (state == State::Alert || fix.speedKmh > 5)
        ? LOCATION_INTERVAL_MOVING_MS
        : LOCATION_INTERVAL_PARKED_MS;

    if (fix.valid && (lastLocationMs == 0 || now - lastLocationMs >= locationInterval)) {
        lastLocationMs = now;
        // Offline, the point is kept so the route on the dashboard has no gap afterwards.
        bool online = enrolled && pingFailures == 0 && netNetworkName().length() > 0;
        if (!online || !apiSendLocation(fix)) {
            outboxAddLocation(fix);
        }
    }

    serviceOutbox(now);
}
