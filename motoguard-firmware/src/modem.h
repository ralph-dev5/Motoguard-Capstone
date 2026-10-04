#pragma once

#include <Arduino.h>
#include <Client.h>

void netBegin();
void netUpdate();
bool netEnsureConnected();
Client& netClient();
bool netSendSms(const char* number, const String& message);
// The owner's phone hotspot from the dashboard, used whenever the usual WiFi is out of reach.
void netSetBackupWifi(const String& ssid, const String& password);
// Listens up to timeoutMs for the server's announcement and adopts its address. True if heard.
bool netDiscoverServer(unsigned long timeoutMs);
// Non-blocking: true when the server's announcement was heard on this pass; changed when it moved.
bool netDiscoverPoll(bool& changed);
// Name of the network currently in use ("" while offline), to notice a switch between networks.
String netNetworkName();
// Moves to the other saved network (usual WiFi <-> phone hotspot). False when there is no other.
bool netSwitchNetwork();
