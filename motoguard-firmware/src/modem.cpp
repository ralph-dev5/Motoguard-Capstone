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

#include <SSLClient.h>
#include <vector>

#ifndef CELL_FALLBACK_MS
#define CELL_FALLBACK_MS 20000   // no WiFi for this long: carry the data over 4G instead
#endif
#ifndef CELL_RETRY_MS
#define CELL_RETRY_MS 30000      // gap between attempts to start mobile data
#endif

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
static unsigned long offlineUntilMs = 0;   // see netSimulateOffline
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
        connected = joinNetwork(primarySsid, primaryPass, 15000, false);
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

void netSimulateOffline(unsigned long durationMs) {
    offlineUntilMs = durationMs > 0 ? millis() + durationMs : 0;
}

bool netSimulatingOffline() {
    if (offlineUntilMs != 0 && (long) (millis() - offlineUntilMs) >= 0) {
        offlineUntilMs = 0;
        Serial.println("[test] Back online");
    }
    return offlineUntilMs != 0;
}

// Test aid: WiFi is switched off for a while but mobile data is still allowed, to watch the 4G
// fallback take over and hand back without walking away from the router.
static unsigned long noWifiUntilMs = 0;

void netSimulateNoWifi(unsigned long durationMs) {
    noWifiUntilMs = durationMs > 0 ? millis() + durationMs : 0;
    if (durationMs > 0) {
        WiFi.disconnect();
    } else {
        WiFi.reconnect();
    }
}

static bool wifiEnsureConnected() {
    unsigned long now = millis();
    if (noWifiUntilMs != 0) {
        if ((long) (now - noWifiUntilMs) < 0) {
            if (disconnectedSinceMs == 0) {
                disconnectedSinceMs = now;
            }
            return false;
        }
        noWifiUntilMs = 0;
        Serial.println("[test] WiFi allowed again");
        WiFi.reconnect();
    }
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

// ---- 4G fallback: the A7670G that sends the texts also carries the data when no WiFi is in reach.
// Everything is plain AT commands on the SMS line (see CellTcpClient below).

// A plain TCP connection over 4G, through the modem's CIP service (link 0). TLS can't be
// left to the modem: the A7670G's own handshake fails with Render (and Cloudflare) though it works
// with Google, so the ESP32 does TLS on top of this (SSLClient, below). TinyGSM's client can't be
// used either: before opening it sends AT+CTCPKA, which the A7670G rejects, and then gives up.
// (The CCH service is no good for plain TCP here: it opens, then refuses to send.)
// Connections in a row that sent a request but got nothing back; see cellEnsureConnected().
static int cellSilentConnections = 0;

class CellTcpClient : public Client {
public:
    int connect(IPAddress ip, uint16_t port) override {
        return connect(ip.toString().c_str(), port);
    }

    int connect(const char* host, uint16_t port) override {
        stop();
        ask("AT+CIPCLOSE=0", "+CIPCLOSE: 0,", 3000);   // left open by a previous session
        // "Received data waits until asked for" only takes effect while the data service is closed;
        // otherwise replies are pushed out unasked and lost. So close it, set that, and reopen.
        if (ask("AT+NETOPEN?", "OK", 2000).indexOf("+NETOPEN: 1") >= 0) {
            ask("AT+NETCLOSE", "+NETCLOSE:", 10000);
        }
        ask("AT+CIPRXGET=1", "OK", 2000);
        if (ask("AT+NETOPEN", "+NETOPEN:", 15000).indexOf("+NETOPEN: 0") < 0) {
            Serial.println("[net] 4G: the modem's data service did not start");
            return 0;
        }
        String cmd = String("AT+CIPOPEN=0,\"TCP\",\"") + host + "\"," + port;
        String reply = ask(cmd.c_str(), "+CIPOPEN: 0,", 30000);
        open = reply.indexOf("+CIPOPEN: 0,0") >= 0;
        if (!open) {
            Serial.printf("[net] 4G: could not open %s:%u\n", host, port);
        }
        return open ? 1 : 0;
    }

    size_t write(uint8_t b) override {
        return write(&b, 1);
    }

    // HttpClient writes a request in many small pieces; they are sent as one, just before the reply
    // is read, which saves a modem round trip per header line.
    size_t write(const uint8_t* buf, size_t size) override {
        if (!open) {
            return 0;
        }
        tx.insert(tx.end(), buf, buf + size);
        return size;
    }

    // TLS waits until available() covers a whole record (a certificate one is several KB), so data
    // already held must not stop the modem being asked for more; it only limits how often.
    int available() override {
        size_t have = rx.size() - rxPos;
        if (!open || !sendPending()) {
            return have;
        }
        if (have == 0 || millis() - lastFetchMs >= 100) {
            fetch();
        }
        return rx.size() - rxPos;
    }

    int read() override {
        if (available() <= 0) {
            return -1;
        }
        return (uint8_t) rx[rxPos++];
    }

    int read(uint8_t* buf, size_t size) override {
        size_t n = 0;
        while (n < size && available() > 0) {
            buf[n++] = (uint8_t) rx[rxPos++];
        }
        return n;
    }

    int peek() override {
        return available() > 0 ? (uint8_t) rx[rxPos] : -1;
    }

    void flush() override {
        sendPending();
    }

    void stop() override {
        if (sentBytes > 0 || gotBytes > 0) {
            // One line per connection: shows whether the server answered at all.
            Serial.printf("[4g] connection closed: sent %u B, received %u B\n", sentBytes, gotBytes);
        }
        if (sentBytes > 0) {
            cellSilentConnections = gotBytes > 0 ? 0 : cellSilentConnections + 1;
        }
        sentBytes = gotBytes = 0;
        if (open) {
            ask("AT+CIPCLOSE=0", "+CIPCLOSE: 0,", 5000);
        }
        open = false;
        tx.clear();
        rx.clear();
        rxPos = 0;
    }

    uint8_t connected() override {
        return open || rxPos < rx.size();
    }

    operator bool() override {
        return connected();
    }

private:
    // Sends cmd and collects the reply until `until` (or ERROR) shows up. A closed connection can be
    // announced in any reply, so every one is checked for it.
    String ask(const char* cmd, const char* until, unsigned long waitMs) {
        Stream& line = smsSerial();
        drain();
        line.print(cmd);
        line.print("\r\n");
        return collect(until, waitMs);
    }

    // Clears leftovers before a command, still noticing if the server closed the connection meanwhile.
    void drain() {
        Stream& line = smsSerial();
        String old;
        while (line.available()) {
            old += (char) line.read();
        }
        if (old.indexOf("+IPCLOSE: 0,") >= 0 || old.indexOf("ATREADY") >= 0) {
            open = false;
        }
#ifdef CELL_DEBUG
        if (old.length() > 0) {
            old.replace("\r", "");
            old.replace("\n", " | ");
            Serial.printf("[4g] unasked: %s\n", old.substring(0, 160).c_str());
        }
#endif
    }

    String collect(const char* until, unsigned long waitMs) {
        Stream& line = smsSerial();
        String out;
        unsigned long start = millis();
        while (millis() - start < waitMs) {
            while (line.available()) {
                out += (char) line.read();
            }
            if (out.indexOf(until) >= 0 || out.indexOf("ERROR") >= 0) {
                break;
            }
            delay(2);
        }
        // *ATREADY / "not supported": the modem restarted (a supply dip) and the connection is gone.
        if (out.indexOf("+IPCLOSE: 0,") >= 0 || out.indexOf("+CIPCLOSE: 0,") >= 0 ||
            out.indexOf("ATREADY") >= 0 || out.indexOf("operation not supported") >= 0) {
            open = false;
        }
#ifdef CELL_DEBUG
        String shown = out;
        shown.replace("\r", "");
        shown.replace("\n", " | ");
        Serial.printf("[4g] wanted %s after %lu ms: %s\n", until, millis() - start, shown.substring(0, 160).c_str());
#endif
        return out;
    }

    bool sendPending() {
        while (!tx.empty() && open) {
            size_t n = tx.size() < 1024 ? tx.size() : 1024;
            String cmd = "AT+CIPSEND=0," + String(n);
            if (ask(cmd.c_str(), ">", 5000).indexOf('>') < 0) {
                Serial.println("[net] 4G: the modem would not take the request");
                stop();
                return false;
            }
            // The modem drops the first bytes if they follow its ">" at once; the debug build only
            // worked because its logging left this gap.
            delay(20);
            smsSerial().write(tx.data(), n);
            if (collect("+CIPSEND: 0,", 10000).indexOf("+CIPSEND: 0,") < 0) {
                stop();
                return false;
            }
            tx.erase(tx.begin(), tx.begin() + n);
            sentBytes += n;
        }
        return true;
    }

    // Moves whatever the modem has received into rx: "+CIPRXGET: 4,0,<waiting>" says how much,
    // then "+CIPRXGET: 2,0,<n>,<left>" is followed by exactly n bytes.
    void fetch() {
        lastFetchMs = millis();
        String len = ask("AT+CIPRXGET=4,0", "OK", 2000);
        int at = len.indexOf("+CIPRXGET: 4,0,");
        int waiting = at >= 0 ? len.substring(at + 15).toInt() : 0;
        if (waiting <= 0) {
            if (rxPos >= rx.size()) {
                delay(20);   // HttpClient polls in a tight loop; don't flood the modem
            }
            return;
        }
        if (rxPos > 0) {
            rx.erase(rx.begin(), rx.begin() + rxPos);
            rxPos = 0;
        }
        while (waiting > 0 && open) {
            int got = fetchChunk(waiting < 1024 ? waiting : 1024);
            if (got <= 0) {
                return;
            }
            waiting -= got;
        }
    }

    // One AT+CIPRXGET=2 read of up to `want` bytes; returns how many arrived.
    int fetchChunk(int want) {
        String cmd = "AT+CIPRXGET=2,0," + String(want);
        Stream& line = smsSerial();
        drain();
        line.print(cmd);
        line.print("\r\n");

        // Byte by byte: the data follows the header at once, and must not be swallowed with it.
        String head;
        unsigned long start = millis();
        while (millis() - start < 5000 && !head.endsWith("+CIPRXGET: 2,0,")) {
            int c = line.read();
            if (c < 0) {
                delay(1);
                continue;
            }
            head += (char) c;
            if (head.indexOf("ERROR") >= 0) {
                return 0;
            }
            if (head.length() > 200) {
                head.remove(0, 100);
            }
        }
        if (!head.endsWith("+CIPRXGET: 2,0,")) {
            return 0;
        }
        String count;
        start = millis();
        while (millis() - start < 2000) {
            int c = line.read();
            if (c < 0) {
                continue;
            }
            if (c == '\n') {
                break;
            }
            count += (char) c;
        }
        int n = count.toInt();
        int got = 0;
        start = millis();
        while (got < n && millis() - start < 5000) {
            int c = line.read();
            if (c >= 0) {
                rx.push_back((uint8_t) c);
                got++;
                gotBytes++;
            }
        }
        collect("OK", 2000);
        return got;
    }

    bool open = false;
    std::vector<uint8_t> tx;   // TLS records are binary: no String, it stops at a zero byte
    std::vector<uint8_t> rx;
    size_t rxPos = 0;
    unsigned long lastFetchMs = 0;
    unsigned sentBytes = 0;
    unsigned gotBytes = 0;
};

static CellTcpClient& cellTcp() {
    static CellTcpClient tcp;
    return tcp;
}

// HTTPS over 4G: TLS on the ESP32 over the modem's TCP link. Certificates are not checked, as on WiFi.
static SSLClient& cellClient() {
    static SSLClient tls(&cellTcp());
    static bool ready = false;
    if (!ready) {
        tls.setInsecure();
        tls.setHandshakeTimeout(30);   // seconds: every piece of the handshake is a modem round trip
        tls.setTimeout(30000);         // milliseconds
        ready = true;
    }
    return tls;
}

static bool onCell = false;
static unsigned long lastCellTryMs = 0;

// Data uses the LTE data context (1). A context the modem has held for hours can open TCP
// connections that then never deliver a reply; switching it off and on again (a fresh one from
// the network) cures that. TinyGSM's gprsConnect is not used: it detaches from LTE entirely
// (AT+CGATT=0), which takes the modem off the network for a while.
static bool cellDataActive() {
    return smsCommand("AT+CGACT?", 3000).indexOf("+CGACT: 1,1") >= 0;
}

static bool cellDataUp() {
    smsCommand("AT+NETCLOSE", 10000);
    smsCommand(String("AT+CGDCONT=1,\"IP\",\"") + SIM_APN + "\"", 3000);
    smsCommand("AT+CGACT=0,1", 15000);
    smsCommand("AT+CGACT=1,1", 30000);
    return cellDataActive();
}

static bool cellEnsureConnected() {
    unsigned long now = millis();
    if (onCell) {
        // Checked at most every 10 s: every request comes through here.
        static unsigned long lastCheckMs = 0;
        static bool lastActive = true;
        if (now - lastCheckMs >= 10000) {
            lastCheckMs = now;
            lastActive = cellDataActive();
        }
        if (lastActive && cellSilentConnections >= 2) {
            // Two connections in a row went out but nothing came back: refresh the data link.
            Serial.println("[net] 4G link not answering - reconnecting mobile data");
            cellClient().stop();
            cellSilentConnections = 0;
            lastActive = cellDataUp();
        }
        if (lastActive) {
            return true;
        }
        Serial.println("[net] 4G mobile data dropped");
        cellClient().stop();
        onCell = false;
    }

    // Give the WiFi a while first, so a short dropout at home doesn't start using mobile data.
    if (disconnectedSinceMs == 0 || now - disconnectedSinceMs < CELL_FALLBACK_MS) {
        return false;
    }
    if (lastCellTryMs != 0 && now - lastCellTryMs < CELL_RETRY_MS) {
        return false;
    }
    lastCellTryMs = now;
    // Bringing up data blocks the loop; only try once the modem is on the network, when it is quick.
    if (!smsReady()) {
        Serial.println("[net] No WiFi, and the A7670G is not on the mobile network yet");
        return false;
    }

    Serial.println("[net] No WiFi - starting 4G mobile data");
    if (!cellDataUp()) {
        Serial.printf("[net] 4G mobile data failed, trying again in %d s\n", CELL_RETRY_MS / 1000);
        return false;
    }
    secureClient.stop();
    client.stop();
    onCell = true;
    Serial.println("[net] On 4G mobile data until WiFi is back");
    return true;
}

bool netOnCellular() {
    return onCell;
}

bool netEnsureConnected() {
    if (netSimulatingOffline()) {
        return false;
    }
    if (wifiEnsureConnected()) {
        if (onCell) {
            // WiFi is free and faster: hand back, and close the modem's connection.
            Serial.println("[net] WiFi is back - 4G mobile data off");
            cellClient().stop();
            onCell = false;
            lastCellTryMs = 0;
        }
        return true;
    }
    return cellEnsureConnected();
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
    if (netSimulatingOffline()) {
        return String();
    }
    if (onCell) {
        return String("4G mobile data");
    }
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
    if (netSimulatingOffline()) {
        return false;
    }
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
    if (onCell) {
        return cellClient();
    }
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

bool netOnCellular() {
    return true;
}

String netNetworkName() {
    return modem.isGprsConnected() ? String("cellular") : String();
}

bool netDiscoverPoll(String&, uint16_t&) {
    return false;
}

void netSimulateOffline(unsigned long) {}

void netSimulateNoWifi(unsigned long) {}

bool netSimulatingOffline() {
    return false;
}

void netUpdate() {
    // Nothing to service: OTA is only available in the WiFi build.
}

#endif
