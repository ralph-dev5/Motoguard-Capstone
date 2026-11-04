#pragma once

#include <Arduino.h>

// SMS through the A7670G, alongside WiFi. WiFi carries the dashboard's data while it is in reach;
// the A7670G texts the owner, and also carries the data over 4G once WiFi is lost (see modem.cpp).

void smsBegin();
// The A7670G's serial line, shared with the 4G data link. The loop is single-threaded, so texts
// and data requests never interleave on it.
Stream& smsSerial();
// Keeps the modem's status fresh (answers / SIM / network) and logs when it changes.
void smsUpdate(unsigned long now);
// True when a text can go out right now: modem answering, SIM ready, registered on the network.
bool smsReady();
// Blocks while the network accepts the message (a few seconds, up to about 30).
bool smsSend(const String& number, const String& message);
// Bench tests: one raw AT command and its reply, and a readout of SIM, network, signal and supply.
String smsCommand(const String& cmd, unsigned long waitMs);
// Test aid: sends cmd and keeps everything the modem says for the whole waitMs, late replies included.
String smsListen(const String& cmd, unsigned long waitMs);
void smsDiagnose();
