#pragma once

#include <Arduino.h>

#include "gps.h"

// What the device could not deliver while it had no connection, kept until it has one again.
// Alerts survive a restart (they are few and matter most); GPS points are kept in memory only.

struct AlertRecord {
    char type[16];
    char level[16];       // "" for alerts that are not a motion episode
    bool hasFix;
    double lat;
    double lng;
    bool smsSent;
    bool hasEvidence;
    uint16_t knocks;
    uint16_t jolts;
    float maxTiltDeg;
    uint32_t durationMs;
    uint32_t atMs;        // millis() when it happened
    uint32_t bootId;      // the power-up atMs belongs to: an age is only known within the same one
    char gpsTime[25];     // GPS clock (UTC) when it happened, "" if the GPS had no time
};

struct LocationRecord {
    double lat;
    double lng;
    float speedKmh;
    float heading;
    uint8_t satellites;
    char recordedAt[21];  // GPS clock (UTC), "2026-10-04T05:12:00Z"; points without one are not kept
};

void outboxBegin();
uint32_t outboxBootId();

void outboxAddAlert(const AlertRecord& alert);
int outboxAlertCount();
bool outboxPeekAlert(AlertRecord& out);
void outboxDropAlert();

void outboxAddLocation(const GpsFix& fix);
int outboxLocationCount();
// Copies up to max of the oldest points into out; returns how many.
int outboxPeekLocations(LocationRecord* out, int max);
void outboxDropLocations(int count);
