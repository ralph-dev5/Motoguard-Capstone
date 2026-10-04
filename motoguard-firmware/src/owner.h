#pragma once

#include <Arduino.h>

// Bluetooth owner detection. The ESP32 scans for the owner's key: an iBeacon UUID broadcast by the
// owner's phone (phones rotate their own Bluetooth address every few minutes, so the address is
// useless as an identity), or the fixed address of a Bluetooth key-fob tag. While the key is heard,
// main.cpp holds motion alarms off.
void ownerBegin();
// Accepts a UUID ("5f3c9a2e-...") or an address ("aa:bb:cc:dd:ee:ff"); anything else, including an
// empty string, turns detection off.
void ownerSetKey(const String& key);
// Heard within OWNER_ABSENT_MS.
bool ownerNearby();
int ownerRssi();
