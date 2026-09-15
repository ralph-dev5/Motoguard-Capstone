#include "api.h"

#include <ArduinoHttpClient.h>
#include <ArduinoJson.h>

#include "config.h"
#include "modem.h"

static int postJson(const char* path, JsonDocument& body, JsonDocument* response) {
    if (!netEnsureConnected()) {
        Serial.printf("[api] POST %s skipped: no connection\n", path);
        return -1;
    }

    String payload;
    serializeJson(body, payload);

    HttpClient http(netClient(), API_HOST, API_PORT);
    http.setHttpResponseTimeout(15000);
    http.beginRequest();
    http.post(path);
    http.sendHeader("Content-Type", "application/json");
    http.sendHeader("Accept", "application/json");
    http.sendHeader("Authorization", String("Bearer ") + DEVICE_TOKEN);
    http.sendHeader("ngrok-skip-browser-warning", "1");
    http.sendHeader("Content-Length", payload.length());
    http.beginBody();
    http.print(payload);
    http.endRequest();

    int status = http.responseStatusCode();
    String text = http.responseBody();
    http.stop();

    Serial.printf("[api] POST %s -> %d\n", path, status);
    if (status < 200 || status >= 300) {
        Serial.println(text);
    } else if (response != nullptr) {
        deserializeJson(*response, text);
    }
    return status;
}

static bool isSuccess(int status) {
    return status >= 200 && status < 300;
}

static void addFix(JsonObject obj, const GpsFix& fix) {
    if (fix.valid) {
        obj["lat"] = fix.lat;
        obj["lng"] = fix.lng;
    }
}

HeartbeatResult apiHeartbeat(float batteryVolts, const GpsFix& fix) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    if (batteryVolts > 0) {
        root["battery_voltage"] = round(batteryVolts * 100) / 100.0;
    }
    addFix(root, fix);

    JsonDocument response;
    HeartbeatResult result = {false, true};

    if (isSuccess(postJson("/api/v1/device/heartbeat", body, &response))) {
        result.ok = true;
        result.armed = response["armed"] | true;
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

bool apiSendAlert(const char* type, const GpsFix& fix, bool smsSent, float accelDelta, float tiltDeg) {
    JsonDocument body;
    JsonObject root = body.to<JsonObject>();
    root["type"] = type;
    root["sms_sent"] = smsSent;
    addFix(root, fix);

    JsonObject payload = root["payload"].to<JsonObject>();
    payload["accel_delta"] = accelDelta;
    payload["tilt_deg"] = tiltDeg;

    return isSuccess(postJson("/api/v1/device/alerts", body, nullptr));
}
