#pragma once

#include <Arduino.h>

// Bluetooth owner detection with no app. The owner pairs their phone once in its own Bluetooth
// settings ("MotoGuard MG-XXXXXX", PIN from ownerPairPin()); the unit shows up as a small remote
// control, so the phone reconnects by itself whenever it is in range with Bluetooth on. While a paired
// phone is connected, main.cpp holds motion alarms off.
void ownerBegin();
// The 6-digit PIN the phone asks for when pairing. Derived from the Device ID and the enroll secret
// the same way the server does, so the dashboard can show it; strangers cannot pair without it.
uint32_t ownerPairPin();
// Removes every paired phone (serial command "unpair").
void ownerForgetPhones();
// A paired phone is connected now, or dropped less than OWNER_ABSENT_MS ago.
bool ownerNearby();
