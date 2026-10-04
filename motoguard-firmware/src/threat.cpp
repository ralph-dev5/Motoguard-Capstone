#include "threat.h"

#include "buzzer.h"
#include "config.h"
#include "thresholds.h"

// Movement classification, kept to three rules the owner can predict:
//
//   Minor          1-2 separate taps or bumps        -> logged only: no buzzer, no SMS
//   Suspicious     THREAT_SUSPICIOUS_HITS or more taps -> short warning beeps, dashboard alert
//                  (taps alone never go further: tapping a bike does not take it away)
//   Theft attempt  pushed, lifted, carried or shaken without a break for THREAT_CONTINUOUS_MS
//                                                    -> full alarm, SMS, dashboard alert
//
// A "tap" is a jolt from the accelerometer or a knock from the SW-420, merged: one tap rattles
// both in the same instant. "Continuous" means the unit keeps being touched or handled with no
// pause longer than THREAT_RUN_GAP_MS. Handling is the accelerometer's spike-capped jitter, so a
// single hard tap never counts as continuous however hard it is; a hand or rolling wheels do.
//
// A level only ever rises within an episode. An episode ends after a stretch of quiet, and only
// then can the next one start from Minor again.
//
// Everything here runs in the motion task except threatNextReport() and threatSetArmed(); the
// two sides only share the report queue and a few single-word values.

static volatile bool armed = true;
static volatile ThreatLevel level = ThreatLevel::None;
static QueueHandle_t reports = nullptr;

static unsigned long startMs = 0;
static unsigned long lastHitMs = 0;       // last sample that kept the episode going
static unsigned long lastCountedMs = 0;   // last tap that counted
static unsigned long runStartMs = 0;      // start of the current unbroken run of activity
static unsigned long lastActiveMs = 0;
static uint16_t hits = 0;
static uint16_t knocks = 0;
static uint16_t jolts = 0;
static float maxTiltDeg = 0;
static bool sawContinuous = false;
static unsigned long lastTheftReportMs = 0;
static unsigned long lastAlarmBeepMs = 0;

static const char* levelName(ThreatLevel l) {
    switch (l) {
        case ThreatLevel::Minor: return "MINOR";
        case ThreatLevel::Suspicious: return "SUSPICIOUS";
        case ThreatLevel::TheftAttempt: return "THEFT ATTEMPT";
        default: return "NONE";
    }
}

const char* threatLevelApiName(ThreatLevel l) {
    switch (l) {
        case ThreatLevel::Minor: return "minor";
        case ThreatLevel::Suspicious: return "suspicious";
        case ThreatLevel::TheftAttempt: return "theft_attempt";
        default: return nullptr;
    }
}

static void endEpisode() {
    level = ThreatLevel::None;
    hits = knocks = jolts = 0;
    maxTiltDeg = 0;
    sawContinuous = false;
}

static void queueReport(unsigned long now) {
    ThreatReport report;
    report.level = level;
    report.type = sawContinuous ? "movement" : "touch";
    report.knocks = knocks;
    report.jolts = jolts;
    report.maxTiltDeg = maxTiltDeg;
    report.durationMs = now - startMs;

    // Never block the sampling task on the network side; a full queue drops the report.
    xQueueSend(reports, &report, 0);
}

// Keeps the alarm going for as long as the tampering does. Restarting the burst on every 50 ms
// sample reset it before its first beep ever ended, which turned shaking into one long tone, so a
// running burst is only topped up once it has had time to beep.
static void soundAlarm(unsigned long now) {
    if (now - lastAlarmBeepMs >= 2 * BUZZER_BEEP_MS * 3) {
        lastAlarmBeepMs = now;
        buzzerBeep(BUZZER_ALERT_BEEPS);
    }
}

static ThreatLevel classify() {
    if (sawContinuous) {
        return ThreatLevel::TheftAttempt;
    }
    if (hits >= THREAT_SUSPICIOUS_HITS) {
        return ThreatLevel::Suspicious;
    }
    return ThreatLevel::Minor;
}

void threatBegin() {
    reports = xQueueCreate(6, sizeof(ThreatReport));
}

void threatSetArmed(bool on) {
    armed = on;
}

void threatOnSample(const MotionSample& sample) {
    unsigned long now = millis();
    bool touched = sample.knock || sample.jolt;
    bool active = touched || sample.handled;

#if MOTION_TRACE
    // Tuning aid, armed or not: the signals the rules use, ten times a second while anything is
    // happening. Between prints the strongest jerk and any SW-420 knock are kept, so a tap that
    // lasts one 50 ms sample is never missed by the 100 ms print.
    static unsigned long lastTraceMs = 0;
    static float peakJerk = 0;
    static bool sawKnock = false;
    peakJerk = max(peakJerk, sample.jerk);
    sawKnock = sawKnock || sample.knock;
    if ((active || peakJerk > 1.0f || sawKnock) && now - lastTraceMs >= 100) {
        lastTraceMs = now;
        Serial.printf("[trace] jerk %.2f%s jitter %.2f tilt %.1f%s\n", (double) peakJerk,
                      sawKnock ? " KNOCK" : "", (double) sample.jitter, (double) sample.tiltDeg,
                      sample.handled ? " handled" : "");
        peakJerk = 0;
        sawKnock = false;
    }
#endif

    if (!armed) {
        if (level != ThreatLevel::None) {
            endEpisode();
        }
        return;
    }

    // Parked noise differs between MPU clones, so it is printed while nothing is happening.
    static unsigned long lastIdleLogMs = 0;
    if (level == ThreatLevel::None && now - lastIdleLogMs >= 10000) {
        lastIdleLogMs = now;
        Serial.printf("[motion] idle: jitter %.2f (handled at %.2f), tilt %.1f deg\n",
                      (double) sample.jitter, (double) MOTION_HANDLING_JITTER, (double) sample.tiltDeg);
    }

    // Continuity: activity that keeps arriving with no gap longer than THREAT_RUN_GAP_MS is one
    // run. Separate taps a second apart break the run every time and are counted instead.
    if (active) {
        if (now - lastActiveMs > THREAT_RUN_GAP_MS) {
            runStartMs = now;
        }
        lastActiveMs = now;
    }
    bool continuous = active && now - runStartMs >= THREAT_CONTINUOUS_MS;
    bool counted = touched && now - lastCountedMs >= THREAT_HIT_GAP_MS;

    // Being handled keeps an episode open between taps, so a slow carry is not taken for quiet.
    if (active && level != ThreatLevel::None) {
        lastHitMs = now;
    }

    if (!counted && !continuous) {
        if (level == ThreatLevel::TheftAttempt && active) {
            soundAlarm(now);
        }
        if (level == ThreatLevel::None) {
            return;
        }
        unsigned long quiet = now - lastHitMs;
        if (level == ThreatLevel::Minor && quiet >= THREAT_MINOR_QUIET_MS) {
            Serial.printf("[threat] minor episode closed (%u taps) - logged, no alarm\n", hits);
            queueReport(now);
            endEpisode();
        } else if (level >= ThreatLevel::Suspicious && quiet >= ALERT_QUIET_RESET_MS) {
            Serial.println("[threat] quiet again - episode over");
            endEpisode();
        }
        return;
    }

    if (level == ThreatLevel::None) {
        startMs = now;
        level = ThreatLevel::Minor;
    }
    lastHitMs = now;
    if (counted) {
        lastCountedMs = now;
        hits++;
        knocks += sample.knock;
        jolts += sample.jolt;
    }
    bool continuousStarted = continuous && !sawContinuous;
    sawContinuous = sawContinuous || continuous;
    maxTiltDeg = max(maxTiltDeg, sample.tiltDeg);

    ThreatLevel before = level;
    ThreatLevel next = classify();
    if (next > level) {
        level = next;
    }

    // Log only what changed something, or continuous handling would print 20 lines a second.
    if (counted || continuousStarted || level != before) {
        Serial.printf("[threat] %s%s-> %s (tap #%u, jitter %.2f, tilt %.1f deg)\n",
                      counted ? "tap " : "", continuousStarted ? "CONTINUOUS " : "",
                      levelName(level), hits, (double) sample.jitter, (double) sample.tiltDeg);
    }

    if (level == ThreatLevel::Suspicious && before < ThreatLevel::Suspicious) {
        buzzerBeep(THREAT_WARNING_BEEPS);
        queueReport(now);
    } else if (level == ThreatLevel::TheftAttempt) {
        soundAlarm(now);
        if (before < ThreatLevel::TheftAttempt || now - lastTheftReportMs >= ALERT_REPEAT_MS) {
            lastTheftReportMs = now;
            queueReport(now);
        }
    }
}

ThreatLevel threatLevel() {
    return level;
}

bool threatNextReport(ThreatReport& out) {
    return reports != nullptr && xQueueReceive(reports, &out, 0) == pdTRUE;
}
