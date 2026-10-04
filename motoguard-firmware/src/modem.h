#pragma once

#include <Arduino.h>
#include <Client.h>

void netBegin();
void netUpdate();
bool netEnsureConnected();
// secure: the TLS client for the online server; otherwise the plain one for a server on the LAN.
Client& netClient(bool secure = false);
bool netSendSms(const char* number, const String& message);
// The owner's phone hotspot from the dashboard, used whenever the usual WiFi is out of reach.
void netSetBackupWifi(const String& ssid, const String& password);
// Listens up to timeoutMs for the server's announcement and adopts its address. True if heard.
// Non-blocking: true when a server announced itself on the local network on this pass, with the
// address it announced. Deciding whether to use it is the caller's job.
bool netDiscoverPoll(String& host, uint16_t& port);
// Name of the network currently in use ("" while offline), to notice a switch between networks.
String netNetworkName();
// Moves to the other saved network (usual WiFi <-> phone hotspot). False when there is no other.
bool netSwitchNetwork();
