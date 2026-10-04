#pragma once

#include <Arduino.h>

// Server address and device token. Defaults come from config.h; the WiFi setup portal can override them.
struct ServerSettings {
    String host;
    uint16_t port;
    String token;
};

void settingsBegin();
const ServerSettings& settingsServer();
// `tokenOrCode` from the setup page: empty keeps the current token, a short value is a pairing
// code from the dashboard (traded for a token once online), anything longer is the token itself.
void settingsSave(const String& host, uint16_t port, const String& tokenOrCode);
// Pairing code waiting to be traded for a token, or "" when there is none.
const String& settingsPairCode();
// The server accepted the code: store the token it returned and forget the code.
void settingsPaired(const String& token);
// The server rejected the code (wrong or expired): forget it so the device stops retrying.
void settingsDropPairCode();
