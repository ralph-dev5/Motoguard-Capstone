#pragma once

#include <Arduino.h>

// SMS through the SIM800L, alongside WiFi. WiFi carries the dashboard's data; the SIM800L only
// texts the owner, which is what still works when the motorcycle is far from any WiFi.

void smsBegin();
// Keeps the modem's status fresh (answers / SIM / network) and logs when it changes.
void smsUpdate(unsigned long now);
// True when a text can go out right now: modem answering, SIM ready, registered on the network.
bool smsReady();
// Blocks while the network accepts the message (a few seconds, up to about 30).
bool smsSend(const String& number, const String& message);
