#pragma once

#include "motion.h"

// Measures this unit's resting noise while the motorcycle stands still and turns it into the
// level-3 motion thresholds. Started from the dashboard; see CALIBRATION_* in config.h.
struct CalibrationResult {
    uint16_t samples;
    float noiseJerk;     // largest sample-to-sample change seen, m/s^2
    float noiseShove;    // largest averaged shove seen, m/s^2
    float noiseRumble;   // largest averaged rumble seen, m/s^2
    float joltThreshold;
    float pushAccel;
    float pushRumble;
};

// Loop side: re-zeroes the resting orientation, then starts recording. Blocks for about 0.5 s.
void calibrationStart();
// Motion task side: while active, every sample goes here and the threat classifier is skipped.
bool calibrationActive();
void calibrationFeed(const MotionSample& sample);
// Loop side: true once, when the measurement has finished and the new thresholds are in force.
bool calibrationFinished(CalibrationResult& out);
