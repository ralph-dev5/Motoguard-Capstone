#pragma once

enum class MotionEvent { None, Movement, Tilt };

struct MotionReading {
    float accelDelta;  // m/s^2 away from the parked baseline
    float tiltDeg;     // degrees away from the parked orientation
};

bool motionBegin();
void motionCalibrate();
MotionEvent motionUpdate();
MotionReading motionLastReading();
unsigned long motionLastActivityMs();
