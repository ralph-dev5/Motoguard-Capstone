#include "calibration.h"

#include "buzzer.h"
#include "config.h"
#include "thresholds.h"

// Written by the motion task, read by loop() once active drops; each is a single word.
static volatile bool active = false;
static volatile unsigned long startMs = 0;
static volatile uint16_t samples = 0;
static volatile float maxJerk = 0;
static volatile float maxShove = 0;
static volatile float maxRumble = 0;

void calibrationStart() {
    // Re-zero first, so the tilt baseline matches how the bike stands now and the shove and
    // rumble averages start from rest rather than from whatever happened before.
    motionCalibrate();

    samples = 0;
    maxJerk = maxShove = maxRumble = 0;
    startMs = millis();
    active = true;

    buzzerBeep(1);
    Serial.printf("[calibrate] measuring resting noise for %u s - keep the motorcycle still\n",
                  (unsigned) (CALIBRATION_MS / 1000));
}

bool calibrationActive() {
    return active;
}

void calibrationFeed(const MotionSample& sample) {
    // The first samples after re-zeroing carry the settling of the smoothed averages, not noise.
    if (millis() - startMs < 1000) {
        return;
    }
    samples++;
    maxJerk = max((float) maxJerk, sample.jerk);
    maxShove = max((float) maxShove, sample.shove);
    maxRumble = max((float) maxRumble, sample.rumble);
}

bool calibrationFinished(CalibrationResult& out) {
    if (!active || millis() - startMs < CALIBRATION_MS) {
        return false;
    }

    out.samples = samples;
    out.noiseJerk = maxJerk;
    out.noiseShove = maxShove;
    out.noiseRumble = maxRumble;
    // The largest reading at rest is the worst the noise gets, so a margin above it means noise
    // alone can never cross the line.
    out.joltThreshold = constrain(maxJerk * CALIBRATION_MARGIN, CALIBRATION_MIN_JERK, CALIBRATION_MAX_JERK);
    out.pushAccel = constrain(maxShove * CALIBRATION_MARGIN, CALIBRATION_MIN_PUSH_ACCEL, CALIBRATION_MAX_PUSH_ACCEL);
    out.pushRumble = constrain(maxRumble * CALIBRATION_MARGIN, CALIBRATION_MIN_PUSH_RUMBLE, CALIBRATION_MAX_PUSH_RUMBLE);

    thresholdsSet(out.joltThreshold, out.pushAccel, out.pushRumble);
    active = false;

    buzzerBeep(2);
    Serial.printf("[calibrate] %u samples, resting noise jolt %.2f shove %.2f rumble %.2f -> "
                  "thresholds jolt %.2f push %.2f / %.2f m/s^2\n",
                  out.samples, (double) out.noiseJerk, (double) out.noiseShove, (double) out.noiseRumble,
                  (double) out.joltThreshold, (double) out.pushAccel, (double) out.pushRumble);
    return true;
}
