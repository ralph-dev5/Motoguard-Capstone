#include <Arduino.h>
#include <Preferences.h>

#include "api.h"
#include "config.h"
#include "gps.h"
#include "modem.h"
#include "motion.h"

enum class State { Disarmed, Armed, Alert };

static State state = State::Armed;
static Preferences prefs;
static bool motionReady = false;

static unsigned long alertStartedMs = 0;
static unsigned long lastHeartbeatMs = 0;
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

static void setState(State next) {
    if (state == next) {
        return;
    }
    Serial.printf("[state] %s -> %s\n", stateName(state), stateName(next));
    state = next;
    digitalWrite(PIN_BUZZER, LOW);

    if (next == State::Armed && motionReady) {
        motionCalibrate();
    }
}

static void applyArmed(bool armed) {
    if (prefs.getBool("armed", true) != armed) {
        prefs.putBool("armed", armed);
    }

    if (!armed) {
        setState(State::Disarmed);
    } else if (state == State::Disarmed) {
        setState(State::Armed);
    }
}

static void raiseAlert(const char* type, const String& reason) {
    GpsFix fix = gpsFix();
    bool ownerNotified;

    if (smsEverSent && millis() - lastSmsMs < SMS_COOLDOWN_MS) {
        // The owner was texted moments ago; report it as handled so the server doesn't text again.
        ownerNotified = true;
    } else {
        String message = "MotoGuard+ ALERT: " + reason + ". ";
        message += fix.valid
            ? "https://maps.google.com/?q=" + String(fix.lat, 6) + "," + String(fix.lng, 6)
            : String("GPS location not available yet.");

        ownerNotified = netSendSms(OWNER_PHONE, message);
        if (ownerNotified) {
            smsEverSent = true;
            lastSmsMs = millis();
        }
    }

    MotionReading reading = motionLastReading();
    apiSendAlert(type, fix, ownerNotified, reading.accelDelta, reading.tiltDeg);
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
            raiseAlert("power_cut", "Motorcycle battery was disconnected");
        }
        return;
    }
    powerCutReported = false;

    if (batteryVolts < LOW_BATTERY_VOLTS && !lowBatteryReported) {
        lowBatteryReported = true;
        raiseAlert("low_battery", "Motorcycle battery is low (" + String(batteryVolts, 1) + " V)");
    } else if (batteryVolts > LOW_BATTERY_VOLTS + 0.3f) {
        lowBatteryReported = false;
    }
#endif
}

void setup() {
    Serial.begin(115200);
    delay(200);
    Serial.println("\nMotoGuard+ starting");

    pinMode(PIN_BUZZER, OUTPUT);
    digitalWrite(PIN_BUZZER, LOW);

    prefs.begin("motoguard", false);
    bool armed = prefs.getBool("armed", true);

    gpsBegin();

    motionReady = motionBegin();
    if (!motionReady) {
        Serial.println("[motion] MPU6050 not found, check the SDA/SCL wiring");
    }

    netBegin();

    state = armed ? State::Armed : State::Disarmed;
    if (armed && motionReady) {
        motionCalibrate();
    }
    Serial.printf("[state] %s\n", stateName(state));
}

void loop() {
    unsigned long now = millis();

    gpsUpdate();

    if (motionReady) {
        MotionEvent event = motionUpdate();

        if (state == State::Armed && event != MotionEvent::None) {
            setState(State::Alert);
            alertStartedMs = now;
            lastLocationMs = 0;

            if (event == MotionEvent::Tilt) {
                raiseAlert("tilt", "Your motorcycle was tilted or taken off its stand");
            } else {
                raiseAlert("movement", "Unauthorized movement detected");
            }
        }

        if (state == State::Alert && now - max(alertStartedMs, motionLastActivityMs()) > ALERT_QUIET_RESET_MS) {
            setState(State::Armed);
        }
    }

    if (state == State::Alert) {
        digitalWrite(PIN_BUZZER, (now / 500) % 2 == 0 ? HIGH : LOW);
    }

    checkBattery(now);

    if (lastHeartbeatMs == 0 || now - lastHeartbeatMs >= HEARTBEAT_INTERVAL_MS) {
        lastHeartbeatMs = now;
        HeartbeatResult heartbeat = apiHeartbeat(batteryVolts, gpsFix());
        if (heartbeat.ok) {
            applyArmed(heartbeat.armed);
        }
    }

    GpsFix fix = gpsFix();
    unsigned long locationInterval = (state == State::Alert || fix.speedKmh > 5)
        ? LOCATION_INTERVAL_MOVING_MS
        : LOCATION_INTERVAL_PARKED_MS;

    if (fix.valid && (lastLocationMs == 0 || now - lastLocationMs >= locationInterval)) {
        lastLocationMs = now;
        apiSendLocation(fix);
    }
}
