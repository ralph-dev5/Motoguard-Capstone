#pragma once

#include <Arduino.h>

// Server address and device token. Defaults come from config.h; the WiFi setup portal can override them.
struct ServerSettings {
    String host;
    uint16_t port;
    String token;
};

void settingsBegin();
// The server in use right now: the saved "home" server, or a temporary one found on the local network.
const ServerSettings& settingsServer();
// Saves a new home server from the setup page. An empty host keeps the current one.
void settingsSave(const String& host, uint16_t port);

// This board's own ID, built from its chip address: "MG-" and six hex digits. Never changes, needs
// no setup, and is what the owner types on the dashboard to add the device.
const String& settingsDeviceId();
// Stores the token the server handed out when the board enrolled. An empty token means "not enrolled".
void settingsSaveToken(const String& token);

// A server announced on the local network, used only while the home server cannot be reached
// (no internet at the venue, laptop demo). Kept in memory: a restart starts from home again.
void settingsUseTemporary(const String& host, uint16_t port);
void settingsUseHome();
bool settingsOnTemporary();
