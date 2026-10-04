#include "sms.h"

#include "config.h"

#ifdef USE_WIFI

// The WiFi build drives the SIM800L with plain AT commands instead of TinyGSM: only SMS is needed
// here, and TinyGSM's blocking network bring-up would stall the loop while the modem is absent.
static HardwareSerial sim(2);

static bool answering = false;
static bool simReady = false;
static bool registered = false;
static int signalQuality = -1;   // 0-31, 99 = unknown
static bool everReported = false;
static unsigned long lastRefreshMs = 0;

static String ask(const char* cmd, unsigned long waitMs = 1500) {
    while (sim.available()) {
        sim.read();
    }
    sim.println(cmd);

    String out;
    unsigned long start = millis();
    while (millis() - start < waitMs) {
        while (sim.available()) {
            out += (char) sim.read();
        }
        if (out.indexOf("OK\r\n") >= 0 || out.indexOf("ERROR") >= 0) {
            break;
        }
        delay(5);
    }
    return out;
}

static void refresh(bool verbose) {
    bool wasAnswering = answering, wasReady = simReady, wasRegistered = registered;

    answering = ask("AT", 800).indexOf("OK") >= 0;
    if (!answering) {
        simReady = registered = false;
        signalQuality = -1;
        if (wasAnswering || verbose) {
            Serial.println("[sms] SIM800L is not answering: no texts can be sent. Check its 5V 2A supply, "
                           "GND joined to the ESP32, TXD->GPIO16, RXD->GPIO17");
        }
        return;
    }

    if (!wasAnswering) {
        ask("ATE0");        // no echo, so replies are easy to read
        ask("AT+CMGF=1");   // text mode
    }

    simReady = ask("AT+CPIN?").indexOf("READY") >= 0;

    String reg = ask("AT+CREG?");
    registered = reg.indexOf(",1") >= 0 || reg.indexOf(",5") >= 0;   // home network or roaming

    String csq = ask("AT+CSQ");
    int at = csq.indexOf("+CSQ: ");
    signalQuality = at >= 0 ? csq.substring(at + 6).toInt() : -1;

    if (verbose || !everReported || wasAnswering != answering || wasReady != simReady || wasRegistered != registered) {
        everReported = true;
        if (!simReady) {
            Serial.println("[sms] SIM800L answers but sees no SIM: push the card fully in, contacts toward the board");
        } else if (!registered) {
            Serial.printf("[sms] SIM ready, not on the network yet (signal %d/31): check the antenna and that the SIM has 2G\n",
                          signalQuality);
        } else {
            Serial.printf("[sms] Ready to text (signal %d/31)\n", signalQuality);
        }
    }
}

void smsBegin() {
    sim.begin(SMS_MODEM_BAUD, SERIAL_8N1, PIN_MODEM_RX, PIN_MODEM_TX);
    delay(300);
    // The SIM800L picks its baud rate from the first "AT"s it hears.
    for (int i = 0; i < 4; i++) {
        sim.println("AT");
        delay(120);
    }
    refresh(true);
    lastRefreshMs = millis();
}

void smsUpdate(unsigned long now) {
    if (now - lastRefreshMs < SMS_STATUS_INTERVAL_MS) {
        return;
    }
    lastRefreshMs = now;
    refresh(false);
}

bool smsReady() {
    return answering && simReady && registered;
}

bool smsSend(const String& number, const String& message) {
    // The status may be up to half a minute old; an alert is worth a fresh look.
    if (!smsReady()) {
        refresh(false);
    }
    if (!smsReady()) {
        Serial.printf("[sms] Not sent: %s\n", !answering ? "the SIM800L is not answering"
                                              : !simReady ? "no SIM detected"
                                                          : "not registered on the network");
        return false;
    }

    ask("AT+CMGF=1");
    while (sim.available()) {
        sim.read();
    }
    sim.print("AT+CMGS=\"");
    sim.print(number);
    sim.println("\"");

    // The modem answers "> " when it is ready for the text.
    String reply;
    unsigned long start = millis();
    while (millis() - start < 5000 && reply.indexOf('>') < 0 && reply.indexOf("ERROR") < 0) {
        while (sim.available()) {
            reply += (char) sim.read();
        }
        delay(5);
    }
    if (reply.indexOf('>') < 0) {
        sim.write(27);   // ESC: abandon the message
        Serial.println("[sms] Not sent: the modem refused the number");
        return false;
    }

    sim.print(message);
    sim.write(26);       // Ctrl-Z: send

    reply = "";
    start = millis();
    while (millis() - start < 30000 && reply.indexOf("+CMGS:") < 0 && reply.indexOf("ERROR") < 0) {
        while (sim.available()) {
            reply += (char) sim.read();
        }
        delay(10);
    }

    bool sent = reply.indexOf("+CMGS:") >= 0;
    Serial.printf("[sms] %s to %s\n", sent ? "Sent" : "FAILED", number.c_str());
    return sent;
}

#else

// The mobile-data build sends texts through TinyGSM (see modem.cpp).
void smsBegin() {}
void smsUpdate(unsigned long) {}
bool smsReady() { return false; }
bool smsSend(const String&, const String&) { return false; }

#endif
