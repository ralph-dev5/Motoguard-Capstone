#include "settings.h"

#include <Preferences.h>
#include <esp_mac.h>

#include "config.h"

static ServerSettings current;   // what requests use
static String homeHost;          // the saved server, restored by settingsUseHome()
static uint16_t homePort = 0;
static bool onTemporary = false;
static String deviceId;

void settingsBegin() {
    // The chip's factory WiFi address is unique per board, so its last three bytes make an ID
    // nobody has to assign: the same board always reports the same one.
    uint8_t mac[6] = {0};
    esp_read_mac(mac, ESP_MAC_WIFI_STA);
    char id[12];
    snprintf(id, sizeof(id), "MG-%02X%02X%02X", mac[3], mac[4], mac[5]);
    deviceId = id;
    Serial.printf("[device] ID %s\n", deviceId.c_str());

    Preferences prefs;
    prefs.begin("server", false);

    // A stored value normally wins, so the setup portal survives a reflash. Bumping
    // SETTINGS_VERSION in config.h is how you say "the firmware's values are the right ones now".
    if (prefs.getULong("ver", 0) != SETTINGS_VERSION) {
        prefs.putString("host", API_HOST);
        prefs.putUShort("port", API_PORT);
        prefs.putString("token", DEVICE_TOKEN);
        prefs.putULong("ver", SETTINGS_VERSION);
        Serial.printf("[settings] Re-seeded from config.h (version %u)\n", (unsigned)SETTINGS_VERSION);
    }

    homeHost = prefs.getString("host", API_HOST);
    homePort = prefs.getUShort("port", API_PORT);
    current.host = homeHost;
    current.port = homePort;
    current.token = prefs.getString("token", DEVICE_TOKEN);
    prefs.end();

    Serial.printf("[settings] Server %s:%u, %s\n", current.host.c_str(), current.port,
                  current.token.length() > 0 ? "enrolled" : "not enrolled yet");
}

const ServerSettings& settingsServer() {
    return current;
}

const String& settingsDeviceId() {
    return deviceId;
}

void settingsSave(const String& host, uint16_t port) {
    // Left empty on the setup page: keep the address the device already has.
    String newHost = host;
    newHost.trim();
    if (newHost.length() > 0) {
        homeHost = newHost;
    }
    homePort = port > 0 ? port : API_PORT;
    settingsUseHome();

    Preferences prefs;
    prefs.begin("server", false);
    prefs.putString("host", homeHost);
    prefs.putUShort("port", homePort);
    prefs.putULong("ver", SETTINGS_VERSION);
    prefs.end();

    Serial.printf("[settings] Saved server %s:%u\n", homeHost.c_str(), homePort);
}

void settingsSaveToken(const String& token) {
    current.token = token;

    Preferences prefs;
    prefs.begin("server", false);
    prefs.putString("token", token);
    prefs.end();
}

void settingsUseTemporary(const String& host, uint16_t port) {
    current.host = host;
    current.port = port;
    onTemporary = true;
}

void settingsUseHome() {
    current.host = homeHost;
    current.port = homePort;
    onTemporary = false;
}

bool settingsOnTemporary() {
    return onTemporary;
}
