#pragma once

#include <Arduino.h>

struct GpsFix {
    bool valid;
    double lat;
    double lng;
    float speedKmh;
    float heading;
    int satellites;
    // Horizontal dilution of precision, straight from GGA: the receiver's own estimate of how much
    // the satellite geometry is spreading the error. Roughly 1 is excellent, 2-5 usable, above 10
    // the position can be out by tens of metres. 0 means the receiver never reported it.
    float hdop;
    // valid means "there is a position at all"; accurate means it also clears GPS_MIN_SATELLITES
    // and GPS_MAX_HDOP. A first fix is normally valid long before it is accurate, and reporting the
    // two as one number is what makes a receiver look like it is lying about where it is.
    bool accurate;
    char timestamp[25];  // ISO 8601 UTC, empty when the GPS has no date yet
};

// Enough to tell "nothing is wired" from "wired, still acquiring": bytes proves the serial
// link, satellites proves the antenna is hearing something, checksum errors suggest a baud or
// wiring fault rather than a sky problem.
struct GpsHealth {
    unsigned long bytes;
    unsigned long sentencesWithFix;
    unsigned long checksumErrors;
    // Satellites the receiver can HEAR, parsed from GSV. This is the number that says whether the
    // antenna is working: it rises as soon as any signal is picked up, long before a fix.
    int satellitesInView;
    // Satellites USED in the position solution, from GGA. Always 0 until there is a fix, so it
    // cannot answer "is the antenna alive" - that was the flaw in reading this alone.
    int satellitesInUse;
    // Same HDOP as GpsFix, exposed here so the status line can show the solution tightening while
    // it is still too loose to trust.
    float hdop;
    bool hasFix;
};

// TEMPORARY: boot-time wiring/baud probe for the GPS UART. Remove once the module is talking.
void gpsDiagnose();

void gpsBegin();
void gpsUpdate();
GpsFix gpsFix();
GpsHealth gpsHealth();
