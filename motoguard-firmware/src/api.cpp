#include "api.h"

#include <ArduinoHttpClient.h>
#include <ArduinoJson.h>

#include "config.h"
#include "modem.h"
#include "settings.h"

static int postJson(const char* path, JsonDocument& body, JsonDocument* response) {
    if (!netEnsureConnected()) {
        Serial.printf("[api] POST %s skipped: no connection\n", path);
        return -1;
    }

    String payload;
    serializeJson(body, payload);

    const ServerSettings& server = settingsServer();
    HttpClient http(netClient(), server.host, server.port);
    http.setHttpResponseTimeout(15000);
    http.beginRequest();
    http.post(path);
    http.sendHeader("Content-Type", "application/json");
    http.sendHeader("Accept", "application/json");
    http.sendHeader("Authorization", "Bearer " + server.token);
    http.sendHeader("ngrok-skip-browser-warning", "1");
    http.sendHeader("Content-Length", payload.length());
    http.beginBody();
    http.print(payload);
    http.endRequest();

    int status = http.responseStatusCode();
    String text = http.responseBody();
    http.stop();

    Serial.printf("[api] POST %s -> %d\n", path, status);
    if (status < 0) {
        Serial.printf("[api] Could not reach %s:%u (is the server running with --host=0.0.0.0?)\n",
                      server.host.c_str(), server.port);
    } else if (status < 200 || status >= 300) {
        Serial.println(text);
    } else if (response != nullptr) {
        deserializeJson(*response, text);
    }
    return status;
}

static bool isSuccess(int status) {
    return status >= 200 && status < 300;
}

bool apiPing(bool& syncRequested) {
    syncRequested = false;
    if (!netEnsureConnected()) {
        return false;
    }

    const ServerSettings& server = settingsServer();
    HttpClient http(netClient(), server.host, server.port);
    http.setHttpResponseTimeout(PRESENCE_PING_TIMEOUT_MS);
    http.beginRequest();
    http.post("/api/v1/device/ping");
    http.sendHeader("Accept", "application/json");
    http.sendHeader("Authorization", "Bearer " + server.token);
    http.sendHeader("ngrok-skip-browser-warning", "1");
    http.sendHeader("Content-Length", 0);
    http.beginBody();
    http.endRequest();

    int status = http.responseStatusCode();
    if (isSuccess(status)) {
        syncRequested = http.responseBody().indexOf("\"sync\":true") >= 0;
    }
    http.stop();

    // Only complain when the state changes, so a server that is down does not fill the log.
    static bool lastOk = true;
    bool ok = isSuccess(status);
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

    if (isSuccess(postJson("/api/v1/device/heartbeat", body, &response))) {
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

bool apiSendAlert(const char* type, const char* level, const GpsFix& fix, bool smsSent,
                  const ThreatReport* evidence) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    root["type"] = type;
    if (level) {
        root["level"] = level;
    }
    root["sms_sent"] = smsSent;
    addFix(root, fix);

    // The evidence behind the classification, so the dashboard can show why it chose the level.
    if (evidence) {
        JsonObject payload = root["payload"].to<JsonObject>();
        payload["knocks"] = evidence->knocks;
        payload["jolts"] = evidence->jolts;
        payload["max_tilt_deg"] = roundf(evidence->maxTiltDeg * 10) / 10;
        payload["duration_ms"] = evidence->durationMs;
    }

    return isSuccess(postJson("/api/v1/device/alerts", body, nullptr));
}

PairResult apiPair(const String& code) {
    JsonDocument body;
    body["code"] = code;
    JsonDocument response;

    int status = postJson("/api/v1/device/pair", body, &response);
    if (isSuccess(status) && response["token"].is<const char*>()) {
        settingsPaired(response["token"].as<String>());
        return PairResult::Paired;
    }
    // 422: wrong or expired code. Anything else (no network, server down, throttled) is worth a retry.
    return status == 422 ? PairResult::Rejected : PairResult::Unreachable;
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
