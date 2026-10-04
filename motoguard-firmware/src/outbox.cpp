#include "outbox.h"

#include <Preferences.h>

#include "config.h"

static AlertRecord alerts[OUTBOX_MAX_ALERTS];
static int alertCount = 0;

// Ring buffer: when full, the oldest point makes room for the newest.
static LocationRecord locations[OUTBOX_MAX_LOCATIONS];
static int locationHead = 0;
static int locationCount = 0;

static uint32_t bootId = 0;

// Alerts are written to flash only when one is added or delivered - a handful of writes per
// outage - so the wear is negligible.
static void saveAlerts() {
    Preferences prefs;
    prefs.begin("outbox", false);
    prefs.putUChar("n", (uint8_t) alertCount);
    if (alertCount > 0) {
        prefs.putBytes("alerts", alerts, sizeof(AlertRecord) * alertCount);
    } else {
        prefs.remove("alerts");
    }
    prefs.end();
}

void outboxBegin() {
    bootId = esp_random();
    if (bootId == 0) {
        bootId = 1;
    }

    Preferences prefs;
    prefs.begin("outbox", true);
    int stored = prefs.getUChar("n", 0);
    size_t bytes = prefs.getBytesLength("alerts");
    // A size that does not match means the record layout changed in an update: start clean.
    if (stored > 0 && stored <= OUTBOX_MAX_ALERTS && bytes == sizeof(AlertRecord) * stored) {
        prefs.getBytes("alerts", alerts, bytes);
        alertCount = stored;
    }
    prefs.end();

    if (alertCount > 0) {
        Serial.printf("[outbox] %d alert(s) from before the restart are waiting to be delivered\n", alertCount);
    }
}

uint32_t outboxBootId() {
    return bootId;
}

void outboxAddAlert(const AlertRecord& alert) {
    if (alertCount == OUTBOX_MAX_ALERTS) {
        // Full: the oldest gives way, so the most recent events are the ones kept.
        memmove(&alerts[0], &alerts[1], sizeof(AlertRecord) * (OUTBOX_MAX_ALERTS - 1));
        alertCount--;
    }
    alerts[alertCount++] = alert;
    saveAlerts();
}

int outboxAlertCount() {
    return alertCount;
}

bool outboxPeekAlert(AlertRecord& out) {
    if (alertCount == 0) {
        return false;
    }
    out = alerts[0];
    return true;
}

void outboxDropAlert() {
    if (alertCount == 0) {
        return;
    }
    memmove(&alerts[0], &alerts[1], sizeof(AlertRecord) * (alertCount - 1));
    alertCount--;
    saveAlerts();
}

void outboxAddLocation(const GpsFix& fix) {
    // Without the GPS clock the server could not place the point on the route, so it is not kept.
    if (!fix.valid || fix.timestamp[0] == '\0') {
        return;
    }

    int slot = (locationHead + locationCount) % OUTBOX_MAX_LOCATIONS;
    if (locationCount == OUTBOX_MAX_LOCATIONS) {
        locationHead = (locationHead + 1) % OUTBOX_MAX_LOCATIONS;
    } else {
        locationCount++;
    }

    LocationRecord& point = locations[slot];
    point.lat = fix.lat;
    point.lng = fix.lng;
    point.speedKmh = fix.speedKmh;
    point.heading = fix.heading;
    point.satellites = (uint8_t) constrain(fix.satellites, 0, 99);
    strlcpy(point.recordedAt, fix.timestamp, sizeof(point.recordedAt));
}

int outboxLocationCount() {
    return locationCount;
}

int outboxPeekLocations(LocationRecord* out, int max) {
    int count = locationCount < max ? locationCount : max;
    for (int i = 0; i < count; i++) {
        out[i] = locations[(locationHead + i) % OUTBOX_MAX_LOCATIONS];
    }
    return count;
}

void outboxDropLocations(int count) {
    if (count > locationCount) {
        count = locationCount;
    }
    locationHead = (locationHead + count) % OUTBOX_MAX_LOCATIONS;
    locationCount -= count;
}
