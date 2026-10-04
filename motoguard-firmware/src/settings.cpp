#include "settings.h"

#include <Preferences.h>

#include "config.h"

static ServerSettings current;
static String pairCode;

// Dashboard pairing codes are six characters; device tokens are dozens.
static const size_t PAIR_CODE_MAX_LEN = 10;

void settingsBegin() {
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

    current.host = prefs.getString("host", API_HOST);
    current.port = prefs.getUShort("port", API_PORT);
    current.token = prefs.getString("token", DEVICE_TOKEN);
    pairCode = prefs.getString("pair", "");
    prefs.end();

    if (pairCode.length() > 0) {
        Serial.printf("[settings] Pairing code %s waiting to be used\n", pairCode.c_str());
    }

    Serial.printf("[settings] Server %s:%u\n", current.host.c_str(), current.port);
}

const ServerSettings& settingsServer() {
    return current;
}

void settingsSave(const String& host, uint16_t port, const String& tokenOrCode) {
    String value = tokenOrCode;
    value.trim();

    // Left empty on the setup page: keep the address the device already has (or will discover).
    String newHost = host;
    newHost.trim();
    if (newHost.length() > 0) {
        current.host = newHost;
    }
    current.port = port > 0 ? port : API_PORT;
    if (value.length() > PAIR_CODE_MAX_LEN) {
        current.token = value;
        pairCode = "";
    } else if (value.length() > 0) {
        pairCode = value;
    }

    Preferences prefs;
    prefs.begin("server", false);
    prefs.putString("host", current.host);
    prefs.putUShort("port", current.port);
    prefs.putString("token", current.token);
    prefs.putString("pair", pairCode);
    prefs.putULong("ver", SETTINGS_VERSION);
    prefs.end();

    Serial.printf("[settings] Saved server %s:%u%s\n", current.host.c_str(), current.port,
                  pairCode.length() > 0 ? " with a pairing code" : "");
}

const String& settingsPairCode() {
    return pairCode;
}

static void storeToken(const String& token) {
    Preferences prefs;
    prefs.begin("server", false);
    prefs.putString("token", token);
    prefs.putString("pair", "");
    prefs.end();
}

void settingsPaired(const String& token) {
    current.token = token;
    pairCode = "";
    storeToken(token);
    Serial.println("[settings] Paired: token received and saved");
}

void settingsDropPairCode() {
    pairCode = "";
    storeToken(current.token);
}
