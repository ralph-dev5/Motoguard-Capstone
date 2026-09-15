#include "modem.h"

#include "config.h"

#ifdef USE_WIFI

#include <WiFi.h>

static WiFiClient client;

void netBegin() {
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASS);

    Serial.print("[net] Connecting to WiFi");
    for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) {
        delay(250);
        Serial.print('.');
    }
    Serial.println(WiFi.status() == WL_CONNECTED ? " connected" : " failed");
}

bool netEnsureConnected() {
    if (WiFi.status() == WL_CONNECTED) {
        return true;
    }
    WiFi.reconnect();
    return false;
}

Client& netClient() {
    return client;
}

bool netSendSms(const char*, const String& message) {
    Serial.println("[sms] WiFi build has no modem, the server will text the owner: " + message);
    return false;
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

Client& netClient() {
    return client;
}

bool netSendSms(const char* number, const String& message) {
    bool sent = modem.sendSMS(number, message);
    Serial.println(sent ? "[sms] Sent" : "[sms] Failed");
    return sent;
}

#endif
