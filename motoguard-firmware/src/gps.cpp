#include "gps.h"

#include <TinyGPS++.h>

#include "config.h"

static TinyGPSPlus gps;
static HardwareSerial gpsSerial(1);

// Field 3 of GSV is "satellites in view". TinyGPS++ does not parse GSV itself, and without it the
// only satellite count available comes from GGA, which stays at zero until a fix already exists -
// useless for telling a deaf antenna from one that simply needs more time.
//
// GSV is emitted per constellation, and this board's receiver is multi-GNSS: it sends $GNGGA and
// $GNRMC, not the $GP.. a real NEO-6M sends. Watching "GPGSV" alone therefore missed GLONASS
// entirely and reported 0 in view while the antenna was hearing satellites perfectly well.
static TinyGPSCustom gpsSatsInView(gps, "GPGSV", 3);
static TinyGPSCustom glonassSatsInView(gps, "GLGSV", 3);
static TinyGPSCustom fusedSatsInView(gps, "GNGSV", 3);
// BeiDou and Galileo: this board's receiver reports BeiDou as $BDGSV (older firmware) or $GBGSV.
static TinyGPSCustom beidouSatsInView(gps, "BDGSV", 3);
static TinyGPSCustom beidouSatsInView2(gps, "GBGSV", 3);
static TinyGPSCustom galileoSatsInView(gps, "GAGSV", 3);

static int satellitesInViewTotal() {
    int perConstellation = 0;
    for (TinyGPSCustom* c : {&gpsSatsInView, &glonassSatsInView, &beidouSatsInView, &beidouSatsInView2, &galileoSatsInView}) {
        if (c->isValid()) {
            perConstellation += atoi(c->value());
        }
    }

    // A receiver emits either per-constellation GSV or one fused GNGSV, not both, so taking the
    // larger of the two totals covers either style without double counting.
    int fused = fusedSatsInView.isValid() ? atoi(fusedSatsInView.value()) : 0;
    return perConstellation > fused ? perConstellation : fused;
}

// TEMPORARY diagnostic. Answers, in order: is anything driving the RX line at all, does any
// common baud produce bytes on GPIO26, and is the module actually wired to GPIO27 instead.
// Remove this and its call in setup() once the GPS is talking.
static unsigned long probePin(int rxPin, unsigned long baud, String& sample) {
    HardwareSerial probe(1);
    probe.setRxBufferSize(2048);
    probe.begin(baud, SERIAL_8N1, rxPin, -1);

    unsigned long start = millis();
    unsigned long count = 0;
    sample = "";

    while (millis() - start < 1200) {
        while (probe.available() > 0) {
            int c = probe.read();
            count++;
            if (sample.length() < 48 && c >= 32 && c < 127) {
                sample += (char) c;
            }
        }
    }

    probe.end();
    return count;
}

void gpsDiagnose() {
    Serial.println("[gpsdiag] ---- start ----");

    // An idle UART TX sits HIGH. With an internal pulldown fighting it, HIGH means something is
    // actively driving the line; LOW means nothing is connected, unpowered, or the wire is broken.
    pinMode(PIN_GPS_RX, INPUT_PULLDOWN);
    delay(50);
    int pulledDown = digitalRead(PIN_GPS_RX);
    pinMode(PIN_GPS_RX, INPUT_PULLUP);
    delay(50);
    int pulledUp = digitalRead(PIN_GPS_RX);

    Serial.printf("[gpsdiag] GPIO%d with pulldown=%s, with pullup=%s -> %s\n",
                  PIN_GPS_RX,
                  pulledDown ? "HIGH" : "LOW",
                  pulledUp ? "HIGH" : "LOW",
                  pulledDown ? "line is DRIVEN (module TX present)"
                             : "line is FLOATING (nothing driving GPIO26)");

    const unsigned long bauds[] = {9600, 4800, 38400, 57600, 115200};
    String sample;

    for (unsigned int i = 0; i < sizeof(bauds) / sizeof(bauds[0]); i++) {
        unsigned long n = probePin(PIN_GPS_RX, bauds[i], sample);
        Serial.printf("[gpsdiag] GPIO%d @ %lu -> %lu bytes  %s\n",
                      PIN_GPS_RX, bauds[i], n, sample.c_str());
    }

    // In case TX and RX are reversed relative to the labels on the module.
    unsigned long other = probePin(PIN_GPS_TX, 9600, sample);
    Serial.printf("[gpsdiag] GPIO%d @ 9600 -> %lu bytes  %s\n", PIN_GPS_TX, other, sample.c_str());

    Serial.println("[gpsdiag] ---- end ----");
}

void gpsBegin() {
    // Must be called before begin(). The buffer has to cover the longest the loop can go without
    // draining, which is a blocking HTTP call, not one pass of the loop - see GPS_RX_BUFFER_BYTES.
    // Undersizing it does not merely truncate a sentence: the overflow handler flushes the buffer,
    // so the receiver looks deaf (0 satellites in view, no fix) while the wiring is perfect.
    gpsSerial.setRxBufferSize(GPS_RX_BUFFER_BYTES);
    gpsSerial.begin(GPS_BAUD, SERIAL_8N1, PIN_GPS_RX, PIN_GPS_TX);
}

void gpsUpdate() {
    // Bounded by both a byte count and a time budget. The unbounded version could spin for as
    // long as data kept arriving, which starves everything after it in the loop.
    unsigned long start = millis();
    int budget = GPS_READ_BUDGET_BYTES;

    while (gpsSerial.available() > 0 && budget-- > 0 && millis() - start < GPS_READ_BUDGET_MS) {
        gps.encode(gpsSerial.read());
    }
}

GpsHealth gpsHealth() {
    GpsHealth health = {};
    health.bytes = gps.charsProcessed();
    health.sentencesWithFix = gps.sentencesWithFix();
    health.checksumErrors = gps.failedChecksum();
    health.satellitesInUse = gps.satellites.isValid() ? (int) gps.satellites.value() : 0;
    health.satellitesInView = satellitesInViewTotal();
    // Satellites used for the fix are by definition in view, so never report fewer than that
    // (the dashboard showed 0 in view next to a 10-satellite fix when a constellation went unparsed).
    if (health.satellitesInView < health.satellitesInUse) {
        health.satellitesInView = health.satellitesInUse;
    }
    health.hdop = gps.hdop.isValid() ? (float) gps.hdop.hdop() : 0.0f;
    health.hasFix = gps.location.isValid() && gps.location.age() < 10000;
    return health;
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
    fix.hdop = gps.hdop.isValid() ? (float) gps.hdop.hdop() : 0.0f;

    // An HDOP of 0 means the receiver never sent one, which is not the same as a perfect solution,
    // so it must not pass the gate.
    fix.accurate = fix.satellites >= GPS_MIN_SATELLITES
                   && fix.hdop > 0.0f
                   && fix.hdop <= GPS_MAX_HDOP;

    if (gps.date.isValid() && gps.time.isValid() && gps.date.year() >= 2024) {
        snprintf(fix.timestamp, sizeof(fix.timestamp), "%04d-%02d-%02dT%02d:%02d:%02dZ",
                 gps.date.year(), gps.date.month(), gps.date.day(),
                 gps.time.hour(), gps.time.minute(), gps.time.second());
    }

    return fix;
}
