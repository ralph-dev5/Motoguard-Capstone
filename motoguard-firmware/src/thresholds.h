#pragma once

// The motion thresholds in force. They start as config.h's tested values and are replaced by
// calibration, which measures this unit's own resting noise (calibration.cpp). Set from loop(),
// read from the motion task; each is a single word, so no locking is needed.
void thresholdsSet(float jerk, float pushAccel, float pushRumble);
float thresholdJerk();         // m/s^2 change between two samples that counts as a jolt
float thresholdPushAccel();    // m/s^2 held shove that counts as being pushed
float thresholdPushRumble();   // m/s^2 steady vibration that counts as rolling
