#pragma once

#include <Arduino.h>

#include "motion.h"

// Ordered by severity, so levels compare with < and >.
enum class ThreatLevel : uint8_t { None, Minor, Suspicious, TheftAttempt };

// One classified motion episode, as sent to the server.
struct ThreatReport {
    ThreatLevel level;
    const char* type;      // "touch" for taps and bumps, "movement" once activity was continuous
    uint16_t knocks;       // SW-420 hits in the episode
    uint16_t jolts;        // MPU jolts in the episode
    float maxTiltDeg;      // furthest the bike leaned from its parked angle
    uint32_t durationMs;   // first hit to now
};

void threatBegin();
void threatSetArmed(bool armed);
// Motion task: called with every sample.
void threatOnSample(const MotionSample& sample);
// Level of the episode in progress, None when all is quiet.
ThreatLevel threatLevel();
// loop(): the next report waiting to be sent, oldest first.
bool threatNextReport(ThreatReport& out);
const char* threatLevelApiName(ThreatLevel level);
