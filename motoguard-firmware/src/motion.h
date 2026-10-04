#pragma once

#include <Arduino.h>

// What the sensors saw in one sample. Several can be true at once - a shove rattles the SW-420
// and jolts the accelerometer in the same 50 ms - and the classifier wants all of it.
struct MotionSample {
    bool knock;      // SW-420: vibration (touch, knock, shaking)
    bool jolt;       // MPU: sudden change between two samples (bump, push, lift)
    bool tilted;     // MPU: a tilt past MOTION_TILT_THRESHOLD_DEG was just held long enough
    bool steepTilt;  // MPU: a tilt past THREAT_THEFT_TILT_DEG was just held long enough
    float tiltDeg;   // MPU: degrees away from the parked orientation right now
    bool moving;     // MPU: being pushed or rolled right now (shove or rumble past its threshold)
    float shove;     // MPU: averaged acceleration beyond gravity, m/s^2
    float rumble;    // MPU: averaged sample-to-sample vibration, m/s^2
    float jerk;      // MPU: raw change since the previous sample, m/s^2 (what a jolt is judged on)
    bool handled;    // MPU: sustained jitter, as when held in a hand, carried or pushed (one hit is not enough)
    float jitter;    // MPU: averaged sample-to-sample change with single spikes capped, m/s^2
};

bool motionBegin();
void motionCalibrate();
// Samples in a background task and hands every sample to onSample, in that task. It must be quick.
void motionStart(void (*onSample)(const MotionSample&));
