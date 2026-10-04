#include "api.h"

#include <ArduinoHttpClient.h>
#include <ArduinoJson.h>
#include <mbedtls/md.h>

#include "config.h"
#include "modem.h"
#include "settings.h"

static bool isSuccess(int status) {
    return status >= 200 && status < 300;
}

// Port 443 means the online server, which only speaks HTTPS. Anything else is a server on the
// local network (the laptop), reached over plain HTTP.
static bool usesTls(const ServerSettings& server) {
    return server.port == 443;
}

// A TLS connection is kept open and reused, so a connection to the previous server must be closed
// by hand when the address changes; otherwise the next request would go down the old one.
static String connectedTo;

static Client& clientFor(const ServerSettings& server) {
    String key = server.host + ":" + String(server.port);
    if (key != connectedTo) {
        netClient(true).stop();
        netClient(false).stop();
        connectedTo = key;
    }
    return netClient(usesTls(server));
}

// One POST. Returns the HTTP status (zero or negative: the server was never reached) and the
// response body in `text`. An empty payload sends no body.
static int request(const char* path, const String& payload, unsigned long timeoutMs, String& text,
                   bool withToken = true) {
    const ServerSettings& server = settingsServer();
    bool tls = usesTls(server);

    HttpClient http(clientFor(server), server.host, server.port);
    if (tls) {
        // A TLS handshake takes this chip a second or two - far too slow to repeat for every
        // ping - so the connection stays open between requests.
        http.connectionKeepAlive();
        if (timeoutMs < TLS_MIN_TIMEOUT_MS) {
            timeoutMs = TLS_MIN_TIMEOUT_MS;
        }
    }
    http.setHttpResponseTimeout(timeoutMs);
    http.beginRequest();
    http.post(path);
    if (payload.length() > 0) {
        http.sendHeader("Content-Type", "application/json");
    }
    http.sendHeader("Accept", "application/json");
    if (withToken) {
        http.sendHeader("Authorization", "Bearer " + server.token);
    }
    http.sendHeader("Content-Length", payload.length());
    http.beginBody();
    if (payload.length() > 0) {
        http.print(payload);
    }
    http.endRequest();

    int status = http.responseStatusCode();
    // Always read the whole reply: on a kept-open connection leftovers would be taken for the
    // start of the next response.
    text = status > 0 ? http.responseBody() : String();
    if (!tls || status <= 0) {
        http.stop();
    }
    return status;
}

static int postJson(const char* path, JsonDocument& body, JsonDocument* response) {
    if (!netEnsureConnected()) {
        Serial.printf("[api] POST %s skipped: no connection\n", path);
        return -1;
    }

    String payload;
    serializeJson(body, payload);

    String text;
    int status = request(path, payload, 15000, text);

    Serial.printf("[api] POST %s -> %d\n", path, status);
    if (status <= 0) {
        const ServerSettings& server = settingsServer();
        Serial.printf("[api] Could not reach %s:%u\n", server.host.c_str(), server.port);
    } else if (!isSuccess(status)) {
        Serial.println(text);
    } else if (response != nullptr) {
        deserializeJson(*response, text);
    }
    return status;
}

bool apiPing(bool& syncRequested) {
    syncRequested = false;
    if (!netEnsureConnected()) {
        return false;
    }

    String text;
    int status = request("/api/v1/device/ping", String(), PRESENCE_PING_TIMEOUT_MS, text);
    bool ok = isSuccess(status);
    if (ok) {
        syncRequested = text.indexOf("\"sync\":true") >= 0;
    }

    // Only complain when the state changes, so a server that is down does not fill the log.
    static bool lastOk = true;
    if (ok != lastOk) {
        Serial.printf("[ping] %s (status %d)\n", ok ? "recovered" : "failing", status);
        lastOk = ok;
    }

    return ok;
}

static void addFix(JsonObject obj, const GpsFix& fix) {
    if (fix.valid) {
        obj["lat"] = fix.lat;
        obj["lng"] = fix.lng;
    }
}

HeartbeatResult apiHeartbeat(const char* state, float batteryVolts, const GpsFix& fix, bool ownerNearby) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    // The dashboard latches on alerts, so it needs to hear when the device is calm again.
    root["state"] = state;
    if (batteryVolts > 0) {
        root["battery_voltage"] = round(batteryVolts * 100) / 100.0;
    }
    addFix(root, fix);

    // GPS health travels with every heartbeat so the dashboard can distinguish a receiver that
    // is not wired from one that is merely indoors. chars is sent even when zero: that value is
    // the whole point, it is what proves nothing is arriving on the serial line.
    GpsHealth health = gpsHealth();
    root["gps_chars"] = health.bytes;
    // In view, not in use: in-use stays 0 until a fix exists and so cannot tell the
    // dashboard whether the antenna is hearing anything.
    root["gps_satellites"] = health.satellitesInView;
    root["owner_nearby"] = ownerNearby;

    JsonDocument response;
    HeartbeatResult result = {false, true, false, false, String()};

    int status = postJson("/api/v1/device/heartbeat", body, &response);
    // The token is no longer accepted (the device was removed and added again): enroll afresh.
    result.rejected = status == 401 || status == 403;
    if (isSuccess(status)) {
        result.ok = true;
        result.armed = response["armed"] | true;
        result.calibrate = response["calibrate"] | false;
        // Present in every reply: an object when a hotspot is set, null when the owner removed it.
        if (response["backup_wifi"].is<JsonObject>()) {
            result.hasBackupWifi = true;
            result.backupSsid = response["backup_wifi"]["ssid"] | "";
            result.backupPass = response["backup_wifi"]["password"] | "";
        } else if (response["backup_wifi"].isNull() && response.containsKey("backup_wifi")) {
            result.hasBackupWifi = true;
        }
        if (response["owner_beacon"].is<const char*>()) {
            result.hasOwnerBeacon = true;
            result.ownerBeacon = response["owner_beacon"].as<const char*>();
        }
        if (response["owner_phone"].is<const char*>()) {
            result.hasOwnerPhone = true;
            result.ownerPhone = response["owner_phone"].as<const char*>();
        }
    }
    return result;
}

bool apiSendLocation(const GpsFix& fix) {
    if (!fix.valid) {
        return false;
    }

    JsonDocument body;
    JsonArray locations = body["locations"].to<JsonArray>();
    JsonObject location = locations.add<JsonObject>();
    location["lat"] = fix.lat;
    location["lng"] = fix.lng;
    location["speed_kmh"] = fix.speedKmh;
    location["heading"] = fix.heading;
    location["satellites"] = fix.satellites;
    if (fix.timestamp[0] != '\0') {
        location["recorded_at"] = fix.timestamp;
    }

    return isSuccess(postJson("/api/v1/device/locations", body, nullptr));
}

int apiSendLocations(const LocationRecord* points, int count) {
    JsonDocument body;
    JsonArray locations = body["locations"].to<JsonArray>();
    for (int i = 0; i < count; i++) {
        JsonObject location = locations.add<JsonObject>();
        location["lat"] = points[i].lat;
        location["lng"] = points[i].lng;
        location["speed_kmh"] = points[i].speedKmh;
        location["heading"] = points[i].heading;
        location["satellites"] = points[i].satellites;
        location["recorded_at"] = points[i].recordedAt;
    }

    return postJson("/api/v1/device/locations", body, nullptr);
}

int apiSendAlert(const AlertRecord& alert, bool late) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    root["type"] = alert.type;
    if (alert.level[0] != '\0') {
        root["level"] = alert.level;
    }
    root["sms_sent"] = alert.smsSent;
    if (alert.hasFix) {
        root["lat"] = alert.lat;
        root["lng"] = alert.lng;
    }

    // The evidence behind the classification, so the dashboard can show why it chose the level.
    if (alert.hasEvidence) {
        JsonObject payload = root["payload"].to<JsonObject>();
        payload["knocks"] = alert.knocks;
        payload["jolts"] = alert.jolts;
        payload["max_tilt_deg"] = roundf(alert.maxTiltDeg * 10) / 10;
        payload["duration_ms"] = alert.durationMs;
    }

    if (late) {
        // When it really happened: the GPS clock if it had one, otherwise how long ago, which is
        // only known if the board has not restarted since.
        if (alert.gpsTime[0] != '\0') {
            root["occurred_at"] = alert.gpsTime;
        } else if (alert.bootId == outboxBootId()) {
            root["age_s"] = (millis() - alert.atMs) / 1000;
        }
        root["recorded_offline"] = true;
    }

    return postJson("/api/v1/device/alerts", body, nullptr);
}

// Proof that this is genuine firmware: HMAC-SHA256 of the board's ID with the secret the server
// shares, as lower-case hex. Without it anyone who read an ID off a device could ask for its token.
static String enrollProof(const String& id) {
    const char* key = DEVICE_ENROLL_SECRET;
    unsigned char mac[32];
    mbedtls_md_hmac(mbedtls_md_info_from_type(MBEDTLS_MD_SHA256),
                    reinterpret_cast<const unsigned char*>(key), strlen(key),
                    reinterpret_cast<const unsigned char*>(id.c_str()), id.length(), mac);

    char hex[65];
    for (int i = 0; i < 32; i++) {
        snprintf(hex + 2 * i, 3, "%02x", mac[i]);
    }
    return String(hex);
}

EnrollResult apiEnroll() {
    if (!netEnsureConnected()) {
        return EnrollResult::Unreachable;
    }

    const String& id = settingsDeviceId();
    JsonDocument body;
    body["serial"] = id;
    body["proof"] = enrollProof(id);
    String payload;
    serializeJson(body, payload);

    String text;
    int status = request("/api/v1/device/enroll", payload, 15000, text, false);

    if (isSuccess(status)) {
        JsonDocument response;
        deserializeJson(response, text);
        if (response["token"].is<const char*>()) {
            settingsSaveToken(response["token"].as<String>());
            return EnrollResult::Enrolled;
        }
    }
    if (status == 404) {
        return EnrollResult::NotAdded;
    }
    if (status == 403) {
        return EnrollResult::Refused;
    }
    Serial.printf("[enroll] No answer from the server (status %d)\n", status);
    return EnrollResult::Unreachable;
}

bool apiSendCalibration(const CalibrationResult& result) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    root["samples"] = result.samples;
    root["noise_jerk"] = result.noiseJerk;
    root["noise_shove"] = result.noiseShove;
    root["noise_rumble"] = result.noiseRumble;
    root["jolt_threshold"] = result.joltThreshold;
    root["push_accel"] = result.pushAccel;
    root["push_rumble"] = result.pushRumble;

    return isSuccess(postJson("/api/v1/device/calibration", body, nullptr));
}
