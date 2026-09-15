#pragma once

#include <Arduino.h>

struct GpsFix {
    bool valid;
    double lat;
    double lng;
    float speedKmh;
    float heading;
    int satellites;
    char timestamp[25];  // ISO 8601 UTC, empty when the GPS has no date yet
};

void gpsBegin();
void gpsUpdate();
GpsFix gpsFix();
