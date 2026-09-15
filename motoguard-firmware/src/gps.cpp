#include "gps.h"

#include <TinyGPS++.h>

#include "config.h"

static TinyGPSPlus gps;
static HardwareSerial gpsSerial(1);

void gpsBegin() {
    gpsSerial.begin(GPS_BAUD, SERIAL_8N1, PIN_GPS_RX, PIN_GPS_TX);
}

void gpsUpdate() {
    while (gpsSerial.available() > 0) {
        gps.encode(gpsSerial.read());
    }
}

GpsFix gpsFix() {
    GpsFix fix = {};
    fix.valid = gps.location.isValid() && gps.location.age() < 10000;

    if (!fix.valid) {
        return fix;
    }

    fix.lat = gps.location.lat();
    fix.lng = gps.location.lng();
    fix.speedKmh = gps.speed.isValid() ? gps.speed.kmph() : 0;
    fix.heading = gps.course.isValid() ? gps.course.deg() : 0;
    fix.satellites = gps.satellites.isValid() ? gps.satellites.value() : 0;

    if (gps.date.isValid() && gps.time.isValid() && gps.date.year() >= 2024) {
        snprintf(fix.timestamp, sizeof(fix.timestamp), "%04d-%02d-%02dT%02d:%02d:%02dZ",
                 gps.date.year(), gps.date.month(), gps.date.day(),
                 gps.time.hour(), gps.time.minute(), gps.time.second());
    }

    return fix;
}
