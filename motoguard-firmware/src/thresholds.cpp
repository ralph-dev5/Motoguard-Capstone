#include "thresholds.h"

#include "config.h"

static volatile float jerk = MOTION_JERK_THRESHOLD;
static volatile float pushAccel = MOTION_PUSH_ACCEL;
static volatile float pushRumble = MOTION_PUSH_RUMBLE;

void thresholdsSet(float nextJerk, float nextPushAccel, float nextPushRumble) {
    jerk = nextJerk;
    pushAccel = nextPushAccel;
    pushRumble = nextPushRumble;
}

float thresholdJerk() {
    return jerk;
}

float thresholdPushAccel() {
    return pushAccel;
}

float thresholdPushRumble() {
    return pushRumble;
}
