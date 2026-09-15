#include "motion.h"

#include <Adafruit_MPU6050.h>
#include <Arduino.h>
#include <Wire.h>

#include "config.h"

static Adafruit_MPU6050 mpu;

static float baseX = 0, baseY = 0, baseZ = 9.81f;
static float prevX = 0, prevY = 0, prevZ = 9.81f;
static int movementCount = 0;
static int tiltCount = 0;
static unsigned long lastSampleMs = 0;
static unsigned long lastActivityMs = 0;
static MotionReading lastReading = {0, 0};

static float magnitude(float x, float y, float z) {
    return sqrt(x * x + y * y + z * z);
}

bool motionBegin() {
    Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);

    if (!mpu.begin()) {
        return false;
    }

    mpu.setAccelerometerRange(MPU6050_RANGE_4_G);
    mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
    return true;
}

void motionCalibrate() {
    const int samples = 50;
    float sumX = 0, sumY = 0, sumZ = 0;
    sensors_event_t accel, gyro, temp;

    for (int i = 0; i < samples; i++) {
        mpu.getEvent(&accel, &gyro, &temp);
        sumX += accel.acceleration.x;
        sumY += accel.acceleration.y;
        sumZ += accel.acceleration.z;
        delay(10);
    }

    baseX = prevX = sumX / samples;
    baseY = prevY = sumY / samples;
    baseZ = prevZ = sumZ / samples;
    movementCount = 0;
    tiltCount = 0;

    Serial.printf("[motion] Calibrated baseline (%.2f, %.2f, %.2f)\n", baseX, baseY, baseZ);
}

MotionEvent motionUpdate() {
    unsigned long now = millis();
    if (now - lastSampleMs < MOTION_SAMPLE_INTERVAL_MS) {
        return MotionEvent::None;
    }
    lastSampleMs = now;

    sensors_event_t accel, gyro, temp;
    mpu.getEvent(&accel, &gyro, &temp);
    float x = accel.acceleration.x;
    float y = accel.acceleration.y;
    float z = accel.acceleration.z;

    float delta = magnitude(x - baseX, y - baseY, z - baseZ);

    float magnitudes = magnitude(x, y, z) * magnitude(baseX, baseY, baseZ);
    float cosine = magnitudes > 0 ? (x * baseX + y * baseY + z * baseZ) / magnitudes : 1.0f;
    float tilt = acos(constrain(cosine, -1.0f, 1.0f)) * 180.0f / PI;

    // Sample-to-sample change: a motorcycle left tilted but still counts as quiet.
    float jerk = magnitude(x - prevX, y - prevY, z - prevZ);
    prevX = x;
    prevY = y;
    prevZ = z;

    lastReading = {delta, tilt};

    movementCount = delta > MOTION_ACCEL_THRESHOLD ? movementCount + 1 : 0;
    tiltCount = tilt > MOTION_TILT_THRESHOLD_DEG ? tiltCount + 1 : 0;

    if (jerk > MOTION_ACCEL_THRESHOLD) {
        lastActivityMs = now;
    }

    if (tiltCount >= MOTION_CONSECUTIVE_SAMPLES) {
        return MotionEvent::Tilt;
    }
    if (movementCount >= MOTION_CONSECUTIVE_SAMPLES) {
        return MotionEvent::Movement;
    }
    return MotionEvent::None;
}

MotionReading motionLastReading() {
    return lastReading;
}

unsigned long motionLastActivityMs() {
    return lastActivityMs;
}
