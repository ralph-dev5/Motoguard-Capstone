#include "modem.h"

#include "config.h"

#ifdef USE_WIFI

#include <Preferences.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h>
#include <WiFiUdp.h>
#include <esp_wifi.h>

#include "settings.h"
#include "sms.h"

static WiFiClient client;
// For the online server. Certificates are not checked (setInsecure): the link is encrypted, and
// what the server trusts is the board's token, not the other way round.
static WiFiClientSecure secureClient;

// Two networks: the usual one (saved by the setup page) and the owner's phone hotspot (sent by
// the dashboard). Credentials are kept here rather than in the WiFi driver, so joining the
// hotspot never overwrites the usual network.
static String primarySsid, primaryPass;
static String backupSsid, backupPass;
static bool onBackup = false;
static unsigned long disconnectedSinceMs = 0;
static unsigned long lastSwitchMs = 0;

static void loadNetworks() {
    wifi_config_t conf;
    if (esp_wifi_get_config(WIFI_IF_STA, &conf) == ESP_OK && conf.sta.ssid[0] != 0) {
        primarySsid = String(reinterpret_cast<const char*>(conf.sta.ssid));
        primaryPass = String(reinterpret_cast<const char*>(conf.sta.password));
    }

    Preferences prefs;
    prefs.begin("net", true);
    backupSsid = prefs.getString("bkSsid", "");
    backupPass = prefs.getString("bkPass", "");
    prefs.end();
}

// Network names as typed on a PC rarely match a phone's exactly: an iPhone calls itself
// "Juan\u2019s iPhone" with a curly apostrophe, a keyboard types a straight one. Compared without
// those differences, and without case, so the owner does not have to get it character-perfect.
static String looseName(const String& ssid) {
    String out = ssid;
    out.replace("\xE2\x80\x99", "'");   // right single quotation mark
    out.replace("\xE2\x80\x98", "'");   // left single quotation mark
    out.trim();
    out.toLowerCase();
    return out;
}

// The name of a nearby network matching `ssid` loosely, or `ssid` itself when none does.
// Whether the last nearbyName() call actually saw the network it was asked about.
static bool nearbyFound = false;

static String nearbyName(const String& ssid) {
    int count = WiFi.scanNetworks();
    String found = ssid;
    int match = -1;
    for (int i = 0; i < count; i++) {
        if (WiFi.SSID(i) == ssid) {
            found = ssid;
            match = i;
            break;
        }
        if (looseName(WiFi.SSID(i)) == looseName(ssid)) {
            found = WiFi.SSID(i);
            match = i;
        }
    }
    if (match >= 0) {
        Serial.printf("[net] \"%s\" is nearby (channel %d, signal %d dBm)\n", found.c_str(),
                      WiFi.channel(match), WiFi.RSSI(match));
    } else {
        // The usual reasons, in order: an iPhone hides its hotspot unless the Personal Hotspot
        // screen is open, and broadcasts on 5 GHz (invisible to the ESP32) unless told not to.
        Serial.printf("[net] \"%s\" is NOT visible among %d networks. iPhone: keep the Personal Hotspot "
                      "screen open and turn on Maximize Compatibility (2.4 GHz)\n", ssid.c_str(), count);
    }
    nearbyFound = match >= 0;
    WiFi.scanDelete();
    return found;
}

// Why the last join attempt did not get through, in words the serial log reader can act on.
static const char* joinFailure() {
    switch (WiFi.status()) {
        case WL_NO_SSID_AVAIL: return "network not found";
        case WL_CONNECT_FAILED: return "rejected - wrong password?";
        case WL_CONNECTION_LOST: return "connection lost";
        case WL_DISCONNECTED: return "timed out waiting for the network to accept it";
        default: return "unknown";
    }
}

// Abandons any join still in progress. While the driver is busy connecting, a scan returns -2 and
// a new WiFi.begin() is refused ("sta is connecting", 0x3007), so the old attempt then fails as
// "connection lost" and the new network is never really tried.
static void stopJoining() {
    WiFi.disconnect(false, false);
    unsigned long start = millis();
    while (WiFi.status() == WL_CONNECTED && millis() - start < 1000) {
        delay(20);
    }
    delay(200);
}

// Joins a network without saving it, waiting up to timeoutMs.
// lookUp scans first so a loosely typed name (the phone hotspot) is matched to the real one. The
// usual network was saved by the setup page with its exact name, so it skips the ~2 s scan.
static bool joinNetwork(const String& wanted, const String& pass, unsigned long timeoutMs, bool lookUp = true) {
    if (wanted.length() == 0) {
        return false;
    }
    stopJoining();
    String ssid = lookUp ? nearbyName(wanted) : wanted;
    Serial.printf("[net] Joining \"%s\"...\n", ssid.c_str());
    WiFi.persistent(false);
    WiFi.begin(ssid.c_str(), pass.c_str());
    WiFi.persistent(true);

    unsigned long start = millis();
    while (millis() - start < timeoutMs) {
        if (WiFi.status() == WL_CONNECTED) {
            return true;
        }
        delay(100);
    }
    Serial.printf("[net] Could not join \"%s\": %s\n", ssid.c_str(), joinFailure());
    return false;
}


static bool setupButtonPressed() {
    // GPIO0 is the BOOT button. Holding it during reset enters the flasher, so it is read after boot instead.
    pinMode(PIN_SETUP_BUTTON, INPUT_PULLUP);
    Serial.println("[net] Press BOOT within 1.5 s to open the WiFi setup portal");

    unsigned long start = millis();
    while (millis() - start < 1500) {
        if (digitalRead(PIN_SETUP_BUTTON) == LOW) {
            return true;
        }
        delay(20);
    }
    return false;
}

void netBegin() {
    WiFi.mode(WIFI_STA);
    secureClient.setInsecure();
    secureClient.setHandshakeTimeout(10);  // seconds

    const ServerSettings& server = settingsServer();
    String port(server.port);

    WiFiManager wm;
    // Left empty on purpose: the device finds the server by itself (see netDiscoverPoll).
    WiFiManagerParameter hostParam("api_host", "Server IP (leave empty: found automatically)", "", 64);
    WiFiManagerParameter portParam("api_port", "Server port", port.c_str(), 6);
    // Read-only: the ID the owner types on the dashboard to add this device.
    String idHtml = "<p style='margin:14px 0 4px'>Device ID</p><p style='font:700 22px monospace;letter-spacing:2px;margin:0 0 6px'>"
        + settingsDeviceId() + "</p><p style='margin:0 0 14px'>Type this ID on the dashboard: Devices, then Add device.</p>";
    WiFiManagerParameter idParam(idHtml.c_str());
    wm.addParameter(&idParam);
    wm.addParameter(&hostParam);
    wm.addParameter(&portParam);

    bool saved = false;
    wm.setSaveConfigCallback([&saved] { saved = true; });
    wm.setSaveParamsCallback([&saved] { saved = true; });
    // First start: keep the portal open until someone configures it.
    // Saved WiFi that is out of range: give up after the timeout so the alarm keeps running.
    wm.setConfigPortalTimeout(wm.getWiFiIsSaved() ? SETUP_PORTAL_TIMEOUT_S : 0);

    Serial.printf("[net] Setup portal: join WiFi \"%s\" (password \"%s\") and open http://192.168.4.1\n",
                  SETUP_AP_NAME, SETUP_AP_PASS);

    loadNetworks();
    bool button = setupButtonPressed();
    bool connected = false;

    // Usual WiFi first, then the owner's phone hotspot, and only then the setup page: an owner
    // riding away from home should never land on a setup portal.
    if (!button) {
        connected = joinNetwork(primarySsid, primaryPass, 8000, false);
        if (!connected && joinNetwork(backupSsid, backupPass, 10000)) {
            connected = true;
            onBackup = true;
        }
    }
    if (!connected) {
        stopJoining();
        connected = button
            ? wm.startConfigPortal(SETUP_AP_NAME, SETUP_AP_PASS)
            : wm.autoConnect(SETUP_AP_NAME, SETUP_AP_PASS);
        if (connected && !onBackup) {
            primarySsid = WiFi.SSID();
            primaryPass = WiFi.psk();
        }
    }

    if (saved) {
        settingsSave(hostParam.getValue(), atoi(portParam.getValue()));
    }

    if (connected) {
        Serial.println("[net] WiFi connected, ESP32 IP " + WiFi.localIP().toString());
    } else {
        Serial.println("[net] WiFi not connected, will keep retrying");
    }
}

void netUpdate() {
    // Nothing to service.
}

bool netEnsureConnected() {
    unsigned long now = millis();
    if (WiFi.status() == WL_CONNECTED) {
        disconnectedSinceMs = 0;
        return true;
    }
    if (disconnectedSinceMs == 0) {
        disconnectedSinceMs = now;
        lastSwitchMs = now;
        WiFi.reconnect();
        return false;
    }

    // Still offline after a while: the network is probably out of range (riding away from home,
    // or back home with the phone hotspot off), so try the other one.
    if (now - lastSwitchMs >= WIFI_SWITCH_MS) {
        Serial.printf("[net] Could not join %s: %s\n", onBackup ? "the phone hotspot" : "the usual WiFi", joinFailure());
        lastSwitchMs = now;
        if (!netSwitchNetwork()) {
            WiFi.reconnect();
        }
    }
    return false;
}

bool netSwitchNetwork() {
    if (backupSsid.length() == 0) {
        return false;
    }
    // With no usual network saved, the hotspot is the only other choice.
    bool wasOnBackup = onBackup;
    onBackup = primarySsid.length() == 0 ? true : !onBackup;
    if (onBackup && WiFi.status() == WL_CONNECTED) {
        // Still connected to the usual WiFi and only the server is missing: leaving it for a phone
        // hotspot that is not even switched on just adds a minute of reconnecting for nothing.
        nearbyName(backupSsid);
        if (!nearbyFound) {
            onBackup = wasOnBackup;
            return false;
        }
    }
    stopJoining();
    String ssid = onBackup ? nearbyName(backupSsid) : primarySsid;
    Serial.printf("[net] Trying %s \"%s\"\n", onBackup ? "phone hotspot" : "usual WiFi", ssid.c_str());
    WiFi.persistent(false);
    WiFi.begin(ssid.c_str(), (onBackup ? backupPass : primaryPass).c_str());
    WiFi.persistent(true);
    lastSwitchMs = millis();
    return true;
}

String netNetworkName() {
    return WiFi.status() == WL_CONNECTED ? WiFi.SSID() : String();
}

void netSetBackupWifi(const String& ssid, const String& password) {
    if (ssid == backupSsid && password == backupPass) {
        return;
    }
    backupSsid = ssid;
    backupPass = password;

    Preferences prefs;
    prefs.begin("net", false);
    prefs.putString("bkSsid", ssid);
    prefs.putString("bkPass", password);
    prefs.end();
    Serial.printf("[net] Phone hotspot %s\n", ssid.length() > 0 ? ("set: \"" + ssid + "\"").c_str() : "removed");
}

// The announcement socket stays open for as long as WiFi is up, so hearing the server costs nothing
// and never blocks the loop: the moment the PC's servers start (or its IP changes), the next
// announcement - they come every 2 s - is picked up on the very next pass.
static WiFiUDP discoveryUdp;
static bool discoveryOpen = false;

bool netDiscoverPoll(String& host, uint16_t& port) {
    if (WiFi.status() != WL_CONNECTED) {
        if (discoveryOpen) {
            discoveryUdp.stop();
            discoveryOpen = false;
        }
        return false;
    }
    if (!discoveryOpen) {
        discoveryOpen = discoveryUdp.begin(DISCOVERY_PORT);
        if (!discoveryOpen) {
            return false;
        }
    }

    bool heard = false;
    while (discoveryUdp.parsePacket() > 0) {
        char buf[96] = {0};
        discoveryUdp.read(buf, sizeof(buf) - 1);
        // {"motoguard":1,"port":8000}
        const char* portAt = strstr(buf, "\"port\":");
        if (strstr(buf, "\"motoguard\"") == nullptr || portAt == nullptr) {
            continue;
        }
        port = atoi(portAt + 7);
        host = discoveryUdp.remoteIP().toString();
        heard = true;
    }
    return heard;
}

Client& netClient(bool secure) {
    if (secure) {
        return secureClient;
    }
    return client;
}

bool netSendSms(const char* number, const String& message) {
    return smsSend(number, message);
}

#else

#define TINY_GSM_RX_BUFFER 1024
#include <TinyGsmClient.h>

static HardwareSerial modemSerial(2);
static TinyGsm modem(modemSerial);
static TinyGsmClient client(modem);
static unsigned long lastConnectAttemptMs = 0;

void netBegin() {
    modemSerial.begin(MODEM_BAUD, SERIAL_8N1, PIN_MODEM_RX, PIN_MODEM_TX);
    delay(3000);

    Serial.println("[net] Initializing modem...");
    if (!modem.init()) {
        Serial.println("[net] Modem did not respond, restarting it");
        modem.restart();
    }
    Serial.println("[net] Modem: " + modem.getModemInfo());

    netEnsureConnected();
}

bool netEnsureConnected() {
    if (modem.isGprsConnected()) {
        return true;
    }
    if (lastConnectAttemptMs != 0 && millis() - lastConnectAttemptMs < 15000) {
        return false;
    }
    lastConnectAttemptMs = millis();

    Serial.println("[net] Connecting mobile data...");
    bool connected = modem.gprsConnect(SIM_APN, SIM_APN_USER, SIM_APN_PASS);
    Serial.println(connected ? "[net] Mobile data connected" : "[net] Mobile data failed");
    return connected;
}

Client& netClient(bool) {
    return client;
}

bool netSendSms(const char* number, const String& message) {
    bool sent = modem.sendSMS(number, message);
    Serial.println(sent ? "[sms] Sent" : "[sms] Failed");
    return sent;
}

// Mobile data reaches the server by its public address; no hotspot or LAN discovery involved.
void netSetBackupWifi(const String&, const String&) {}

bool netSwitchNetwork() {
    return false;
}

String netNetworkName() {
    return modem.isGprsConnected() ? String("cellular") : String();
}

bool netDiscoverPoll(String&, uint16_t&) {
    return false;
}

void netUpdate() {
    // Nothing to service: OTA is only available in the WiFi build.
}

#endif
