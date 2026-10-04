#include "motion.h"

#include "config.h"
#include "thresholds.h"

#include <Adafruit_MPU6050.h>
#include <Wire.h>

// Both sensors can run at once, because they answer different questions. The SW-420 is a spring
// switch: it notices a knock or a tap, which the accelerometer averages away. The MPU tells
// movement apart from tilt, which a vibration switch cannot do at all. Running them together is
// what makes a threat level meaningful - a bike being knocked is not a bike being carried off.
static const bool useMpu =
    (MOTION_SENSOR == MOTION_SENSOR_MPU6050 || MOTION_SENSOR == MOTION_SENSOR_BOTH);
static const bool useSw420 =
    (MOTION_SENSOR == MOTION_SENSOR_SW420 || MOTION_SENSOR == MOTION_SENSOR_BOTH);

// Shared between the sampling task and loop(): keeps a recalibration from interleaving with a
// sample on the I2C bus.
static SemaphoreHandle_t sensorLock = nullptr;
static void (*sampleHook)(const MotionSample&) = nullptr;

// ---------------------------------------------------------------- SW-420 vibration switch ----

// Its DO pin flickers while shaken; the polarity differs between boards, so every change is
// counted instead of reading a level.
static bool sw420Ready = false;
// Detaching an interrupt that was never attached makes the GPIO driver log an error every probe.
static bool sw420Attached = false;
static volatile uint32_t pendingPulses = 0;
static unsigned long windowStartMs = 0;
static int activeSamples = 0;
static uint32_t windowPulses = 0;

static void IRAM_ATTR onVibration() {
    pendingPulses++;
}

// An unpowered or unplugged module leaves the pin floating, and a floating pin fires tens of
// thousands of edges a second - indistinguishable from violent shaking unless we check. Same
// probe gps.cpp uses on the GPS line: a pin that follows whichever internal resistor is applied
// has nothing external holding it.
static bool sw420LineDriven(bool log) {
    pinMode(PIN_VIBRATION, INPUT_PULLDOWN);
    delay(2);
    bool followedDown = digitalRead(PIN_VIBRATION) == LOW;
    pinMode(PIN_VIBRATION, INPUT_PULLUP);
    delay(2);
    bool followedUp = digitalRead(PIN_VIBRATION) == HIGH;

    if (log) {
        Serial.printf("[motion] SW-420 on GPIO%d: with pulldown=%s, with pullup=%s -> %s\n",
                      PIN_VIBRATION, followedDown ? "LOW" : "HIGH", followedUp ? "HIGH" : "LOW",
                      (followedDown && followedUp) ? "FLOATING" : "DRIVEN");
    }

    return !(followedDown && followedUp);
}

static bool sw420Begin() {
    if (!sw420LineDriven(true)) {
        // Park it at a defined level so the idle pin cannot generate interrupts at all.
        pinMode(PIN_VIBRATION, INPUT_PULLDOWN);
        Serial.println("[motion] SW-420 has no power or its DO is unplugged - skipping it. "
                       "Check VCC on 3V3 (never 5V) and the module's red LED.");
        return false;
    }

    pinMode(PIN_VIBRATION, INPUT);
    attachInterrupt(digitalPinToInterrupt(PIN_VIBRATION), onVibration, CHANGE);
    sw420Attached = true;
    Serial.printf("[motion] SW-420 vibration sensor on GPIO%d\n", PIN_VIBRATION);
    return true;
}

static void sw420Reset() {
    noInterrupts();
    pendingPulses = 0;
    interrupts();

    windowStartMs = 0;
    activeSamples = 0;
    windowPulses = 0;
}

// A jumper that works loose while running turns the pin into an antenna, and with a single
// touch enough to alarm, that noise alarmed nonstop. The boot-time probe alone never saw it, so
// the line is re-probed every few seconds: a dead sensor is ignored, and picked up again once it
// is plugged back in. Two floating probes in a row are required, because a probe that lands in
// the middle of a real knock can momentarily read like one.
static int sw420FloatingProbes = 0;

static void sw420Recheck() {
    if (sw420Attached) {
        detachInterrupt(digitalPinToInterrupt(PIN_VIBRATION));
        sw420Attached = false;
    }
    bool driven = sw420LineDriven(false);
    sw420FloatingProbes = driven ? 0 : sw420FloatingProbes + 1;

    if (driven) {
        pinMode(PIN_VIBRATION, INPUT);
        sw420Reset();                   // the probe itself toggled the pin
        attachInterrupt(digitalPinToInterrupt(PIN_VIBRATION), onVibration, CHANGE);
        sw420Attached = true;
        if (!sw420Ready) {
            Serial.println("[motion] SW-420 connected again - using it");
            sw420Ready = true;
        }
    } else if (sw420Ready && sw420FloatingProbes < 2) {
        pinMode(PIN_VIBRATION, INPUT);
        sw420Reset();
        attachInterrupt(digitalPinToInterrupt(PIN_VIBRATION), onVibration, CHANGE);
        sw420Attached = true;
    } else {
        pinMode(PIN_VIBRATION, INPUT_PULLDOWN);
        sw420Reset();
        if (sw420Ready) {
            Serial.println("[motion] SW-420 lost power or its DO wire - ignoring it until it is back. "
                           "Check VCC on 3V3, GND, and DO on GPIO19.");
            sw420Ready = false;
        }
    }
}

// Returns true when the window has collected enough shaking to count as an event.
static bool sw420Sample(unsigned long now) {
    noInterrupts();
    uint32_t pulses = pendingPulses;
    pendingPulses = 0;
    interrupts();

    // A spring switch bounces a few hundred times a second at most. Thousands of edges inside one
    // sample is electrical noise on a line that stopped being driven - a wire works loose far more
    // often than a bike gets shaken this hard, and believing it means alerting forever.
    if (pulses > VIBRATION_MAX_PULSES_PER_SAMPLE) {
        Serial.printf("[motion] %u edges in one %d ms sample is noise, not vibration - "
                      "check the SW-420's power and its DO wire\n",
                      pulses, MOTION_SAMPLE_INTERVAL_MS);
        sw420Reset();
        return false;
    }

    if (windowStartMs != 0 && now - windowStartMs > VIBRATION_WINDOW_MS) {
        // Near miss. Logged because a sensor that is wired but too deaf looks exactly like one
        // that is not wired at all, and this is the only way to tell them apart.
        Serial.printf("[motion] Ignored %u changes in %d samples (needs %d)\n",
                      windowPulses, activeSamples, VIBRATION_ACTIVE_SAMPLES);
        sw420Reset();
    }

    if (pulses > 0) {
        if (windowStartMs == 0) {
            windowStartMs = now;
        }
        activeSamples++;
        windowPulses += pulses;
    }

    // A single knock lights up one or two samples; sustained shaking lights up several.
    if (activeSamples >= VIBRATION_ACTIVE_SAMPLES) {
        sw420Reset();
        return true;
    }
    return false;
}

// ------------------------------------------------------------------------ MPU accelerometer ----

static Adafruit_MPU6050 mpu;
static bool mpuReady = false;

// Set when the Adafruit driver refuses the chip but the chip is demonstrably there. The clone
// dies sold as GY-521 (MPU-6500, 6880, 9250) are register-compatible for what this file needs -
// they only differ in the WHO_AM_I byte the driver insists on - so we drive them directly.
static bool rawMode = false;
static uint8_t mpuAddr = 0x68;

#define MPU_REG_CONFIG 0x1A
#define MPU_REG_ACCEL_CONFIG 0x1C
#define MPU_REG_ACCEL_XOUT_H 0x3B
#define MPU_REG_PWR_MGMT_1 0x6B
#define MPU_REG_WHO_AM_I 0x75
// Counts per g at the +-4 g setting both paths use, so the two agree on what a reading means.
#define MPU_LSB_PER_G 8192.0f

static float baseX = 0, baseY = 0, baseZ = 9.81f;
static float prevX = 0, prevY = 0, prevZ = 9.81f;
static float gravX = 0, gravY = 0, gravZ = 9.81f;   // smoothed gravity: the bike's actual lean
// Per 50 ms sample: 0.2 settles on a real lean in about half a second while a tap barely moves it.
#define TILT_SMOOTHING 0.2f
// A sample counts toward tilt only if its total is within this fraction of 1 g.
#define TILT_STEADY_FRACTION 0.15f
static int tiltCount = 0;
static int steepTiltCount = 0;

// Push detection. A bike being pushed or wheeled away gives no sharp jolt: it is a shove held in
// one direction, then the steady rumble of the wheels rolling. Gravity is tracked a second, much
// slower way here so a shove lasting a second or two is not absorbed into "gravity" before it
// can be measured, and both signals are averaged so a single tap cannot pass for either.
static float slowGravX = 0, slowGravY = 0, slowGravZ = 9.81f;
static float shoveX = 0, shoveY = 0, shoveZ = 0;   // averaged acceleration beyond gravity
static float rumble = 0;                            // averaged sample-to-sample vibration
#define SLOW_GRAVITY_SMOOTHING 0.02f   // ~2.5 s to follow a change: slower than any shove
// A hard tap can leave the reading offset by most of 1 m/s^2 while the unit then sits perfectly
// still, and a 2.5 s gravity left that showing as a shove (and, upward, as a lift) for seconds.
// Nothing still is being pushed, so once the jitter says "still" gravity catches up in ~0.5 s.
#define SLOW_GRAVITY_SMOOTHING_STILL 0.1f
#define STILL_JITTER 0.12f
#define SHOVE_SMOOTHING 0.15f          // ~0.3 s: a tap lasts one sample and averages away
#define RUMBLE_SMOOTHING 0.1f          // ~0.5 s
#define PUSH_SAMPLE_CAP 2.0f           // m/s^2: most any one sample may add to either average

// Handling jitter: like rumble, but each sample capped low, so a single hard hit (jerk 20-50)
// adds almost nothing while a hand or rolling wheels (0.3-0.6 every sample) fill it up.
static float jitter = 0;
#define JITTER_SMOOTHING 0.1f
#define JITTER_SAMPLE_CAP 0.6f

static float magnitude(float x, float y, float z) {
    return sqrt(x * x + y * y + z * z);
}

static bool regWrite(uint8_t reg, uint8_t value) {
    Wire.beginTransmission(mpuAddr);
    Wire.write(reg);
    Wire.write(value);
    return Wire.endTransmission() == 0;
}

static bool regRead(uint8_t reg, uint8_t *buf, size_t len) {
    Wire.beginTransmission(mpuAddr);
    Wire.write(reg);
    if (Wire.endTransmission(false) != 0) {
        return false;
    }
    if (Wire.requestFrom((int) mpuAddr, (int) len) != (int) len) {
        return false;
    }
    for (size_t i = 0; i < len; i++) {
        buf[i] = Wire.read();
    }
    return true;
}

static bool rawBegin() {
    // Out of reset the chip sleeps; clearing PWR_MGMT_1 wakes it and selects the internal clock.
    if (!regWrite(MPU_REG_PWR_MGMT_1, 0x00)) {
        return false;
    }
    delay(100);
    regWrite(MPU_REG_CONFIG, 0x04);         // DLPF ~21 Hz, matching setFilterBandwidth() below
    regWrite(MPU_REG_ACCEL_CONFIG, 0x08);   // AFS_SEL = 1, +-4 g
    delay(10);

    uint8_t probe[6];
    return regRead(MPU_REG_ACCEL_XOUT_H, probe, sizeof(probe));
}

// Only runs when the chip does not answer. "Not found" on its own cannot tell a wire that was
// never connected from a module sitting at the other address, and those need opposite fixes.
static void i2cDiagnose() {
    Serial.println("[i2cdiag] ---- start ----");

    // The breakout carries its own pull-ups, so a connected line stays HIGH even against the
    // ESP32's internal pulldown. A line with nothing on the far end loses and reads LOW.
    const int pins[2] = {PIN_I2C_SDA, PIN_I2C_SCL};
    const char *names[2] = {"SDA", "SCL"};
    for (int i = 0; i < 2; i++) {
        pinMode(pins[i], INPUT_PULLDOWN);
        delay(2);
        Serial.printf("[i2cdiag] %s on GPIO%d -> %s\n", names[i], pins[i],
                      digitalRead(pins[i]) == HIGH ? "pulled up (module is on this wire)"
                                                   : "FLOATING (nothing on this wire)");
    }

    Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
    int found = 0;
    for (uint8_t addr = 1; addr < 127; addr++) {
        Wire.beginTransmission(addr);
        if (Wire.endTransmission() == 0) {
            Serial.printf("[i2cdiag] device answering at 0x%02X\n", addr);
            found++;
        }
    }
    if (found == 0) {
        Serial.println("[i2cdiag] nothing on the bus at all");
    }
    Serial.println("[i2cdiag] ---- end ----");
}

static bool mpuBegin() {
    Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);

    if (mpu.begin(MPU6050_I2CADDR_DEFAULT)) {
        mpu.setAccelerometerRange(MPU6050_RANGE_4_G);
        mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
        return true;
    }

    // AD0 tied high moves the chip to 0x69. Nothing outside the board distinguishes the two,
    // so try it before giving up rather than making the wiring look broken.
    if (mpu.begin(0x69)) {
        mpuAddr = 0x69;
        mpu.setAccelerometerRange(MPU6050_RANGE_4_G);
        mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
        Serial.println("[motion] MPU6050 found at 0x69 (AD0 is tied high)");
        return true;
    }

    // The driver only rejects a chip that answered; deciding between "absent" and "present but
    // not the exact part Adafruit expects" needs the WHO_AM_I byte.
    for (uint8_t candidate = 0x68; candidate <= 0x69; candidate++) {
        mpuAddr = candidate;
        uint8_t who = 0;
        if (regRead(MPU_REG_WHO_AM_I, &who, 1)) {
            Serial.printf("[motion] chip at 0x%02X reports WHO_AM_I 0x%02X\n", candidate, who);
            if (rawBegin()) {
                rawMode = true;
                Serial.println("[motion] driving it directly - the Adafruit driver only accepts "
                               "WHO_AM_I 0x68, this clone reports something else");
                return true;
            }
        }
    }

    Serial.println("[motion] MPU6050 not found, check the SDA/SCL wiring");
    i2cDiagnose();
    return false;
}

// Both paths hand back m/s^2 on the same axes, so nothing downstream needs to know which ran.
static void readAccel(float &x, float &y, float &z) {
    if (rawMode) {
        uint8_t b[6];
        if (!regRead(MPU_REG_ACCEL_XOUT_H, b, sizeof(b))) {
            x = y = z = 0;
            return;
        }
        int16_t rx = (int16_t) ((b[0] << 8) | b[1]);
        int16_t ry = (int16_t) ((b[2] << 8) | b[3]);
        int16_t rz = (int16_t) ((b[4] << 8) | b[5]);
        x = rx / MPU_LSB_PER_G * SENSORS_GRAVITY_EARTH;
        y = ry / MPU_LSB_PER_G * SENSORS_GRAVITY_EARTH;
        z = rz / MPU_LSB_PER_G * SENSORS_GRAVITY_EARTH;
        return;
    }

    sensors_event_t accel, gyro, temp;
    mpu.getEvent(&accel, &gyro, &temp);
    x = accel.acceleration.x;
    y = accel.acceleration.y;
    z = accel.acceleration.z;
}

static void mpuRecalibrate() {
    const int samples = 50;
    float sumX = 0, sumY = 0, sumZ = 0;

    for (int i = 0; i < samples; i++) {
        float ax, ay, az;
        readAccel(ax, ay, az);
        sumX += ax;
        sumY += ay;
        sumZ += az;
        delay(10);
    }

    baseX = prevX = gravX = slowGravX = sumX / samples;
    baseY = prevY = gravY = slowGravY = sumY / samples;
    baseZ = prevZ = gravZ = slowGravZ = sumZ / samples;
    shoveX = shoveY = shoveZ = 0;
    rumble = 0;
    jitter = 0;
    tiltCount = 0;
    steepTiltCount = 0;

    Serial.printf("[motion] Calibrated baseline (%.2f, %.2f, %.2f)\n", baseX, baseY, baseZ);
}

// Fills in what the accelerometer saw in this sample: a jolt, and whether a held tilt just began.
static void mpuSample(MotionSample& out) {
    float x, y, z;
    readAccel(x, y, z);

    // Sample-to-sample change: a motorcycle left tilted but still counts as quiet.
    float jerk = magnitude(x - prevX, y - prevY, z - prevZ);
    prevX = x;
    prevY = y;
    prevZ = z;

    // Tilt comes from gravity alone. A tap spikes the accelerometer for a moment and, taken raw,
    // one tap read as a 158 deg tilt - every tap looked like the bike being tipped over. Samples
    // whose total is not close to 1 g are motion, not orientation, so they are left out, and the
    // rest are smoothed so the angle follows the bike's lean rather than its vibration.
    float g = magnitude(baseX, baseY, baseZ);
    bool steady = fabs(magnitude(x, y, z) - g) < TILT_STEADY_FRACTION * g;
    if (steady) {
        gravX += TILT_SMOOTHING * (x - gravX);
        gravY += TILT_SMOOTHING * (y - gravY);
        gravZ += TILT_SMOOTHING * (z - gravZ);
    }

    float magnitudes = magnitude(gravX, gravY, gravZ) * g;
    float cosine = magnitudes > 0 ? (gravX * baseX + gravY * baseY + gravZ * baseZ) / magnitudes : 1.0f;
    float tilt = acos(constrain(cosine, -1.0f, 1.0f)) * 180.0f / PI;

    // A shaking sample neither builds nor breaks a held tilt.
    if (steady) {
        tiltCount = tilt > MOTION_TILT_THRESHOLD_DEG ? tiltCount + 1 : 0;
        steepTiltCount = tilt > THREAT_THEFT_TILT_DEG ? steepTiltCount + 1 : 0;
    }

    out.tiltDeg = tilt;

    jitter += JITTER_SMOOTHING * (min(jerk, JITTER_SAMPLE_CAP) - jitter);
    float slowSmoothing = jitter < STILL_JITTER ? SLOW_GRAVITY_SMOOTHING_STILL : SLOW_GRAVITY_SMOOTHING;
    slowGravX += slowSmoothing * (x - slowGravX);
    slowGravY += slowSmoothing * (y - slowGravY);
    slowGravZ += slowSmoothing * (z - slowGravZ);
    // Capped per sample: pushing a bike is gentle (well under the cap), while a hard tap can spike
    // to 10-30 m/s^2 for one sample and would otherwise hold the averages up for a second.
    shoveX += SHOVE_SMOOTHING * (constrain(x - slowGravX, -PUSH_SAMPLE_CAP, PUSH_SAMPLE_CAP) - shoveX);
    shoveY += SHOVE_SMOOTHING * (constrain(y - slowGravY, -PUSH_SAMPLE_CAP, PUSH_SAMPLE_CAP) - shoveY);
    shoveZ += SHOVE_SMOOTHING * (constrain(z - slowGravZ, -PUSH_SAMPLE_CAP, PUSH_SAMPLE_CAP) - shoveZ);
    rumble += RUMBLE_SMOOTHING * (min(jerk, (float) PUSH_SAMPLE_CAP) - rumble);

    out.shove = magnitude(shoveX, shoveY, shoveZ);
    out.rumble = rumble;

    out.jitter = jitter;
    out.handled = jitter > MOTION_HANDLING_JITTER;
    // Pushing always vibrates: a shove with no jitter behind it is a tap's leftover offset.
    out.moving = jitter > thresholdPushRumble() || (out.shove > thresholdPushAccel() && out.handled);
    // A touch, bump or the first instant of a lift shows up as a jump between two samples.
    out.jerk = jerk;
    out.jolt = jerk > thresholdJerk();
    // Held tilts report once, on the sample that crosses the count. A bike knocked over and left
    // lying would otherwise report every 50 ms and look like nonstop tampering.
    out.tilted = tiltCount == MOTION_CONSECUTIVE_SAMPLES;
    out.steepTilt = steepTiltCount == MOTION_CONSECUTIVE_SAMPLES;
}

// ----------------------------------------------------------------------------- public API ----

bool motionBegin() {
    sensorLock = xSemaphoreCreateMutex();
    if (useSw420) {
        sw420Ready = sw420Begin();
    }
    if (useMpu) {
        mpuReady = mpuBegin();
    }

    // Either sensor alone is still a working alarm, so losing one must not disable the other.
    if (sw420Ready && mpuReady) {
        Serial.println("[motion] both sensors live: SW-420 for knocks, MPU for movement and tilt");
    } else if (!sw420Ready && !mpuReady) {
        Serial.println("[motion] no motion sensor available");
    }

    return sw420Ready || mpuReady;
}

void motionCalibrate() {
    xSemaphoreTake(sensorLock, portMAX_DELAY);
    if (sw420Ready) {
        sw420Reset();
    }
    if (mpuReady) {
        mpuRecalibrate();
    }
    xSemaphoreGive(sensorLock);
}

static MotionSample sampleOnce() {
    MotionSample sample = {};

    if (sw420Ready) {
        sample.knock = sw420Sample(millis());
    }
    if (mpuReady) {
        mpuSample(sample);
    }
    return sample;
}

// Sampling lives in its own task because loop() spends most of its time blocked on HTTP: a ping
// can hold it for a second or more, so sampling from loop() ran at ~1 Hz instead of 20 Hz. That
// made the SW-420's "N samples inside 1 s" rule unreachable and stretched the MPU's consecutive
// sample rule into seconds - a quick touch or lift slipped straight through.
#define SW420_RECHECK_MS 3000
static unsigned long lastSw420CheckMs = 0;

static void motionTask(void*) {
    TickType_t wake = xTaskGetTickCount();
    for (;;) {
        vTaskDelayUntil(&wake, pdMS_TO_TICKS(MOTION_SAMPLE_INTERVAL_MS));

        xSemaphoreTake(sensorLock, portMAX_DELAY);
        if (useSw420 && millis() - lastSw420CheckMs >= SW420_RECHECK_MS) {
            lastSw420CheckMs = millis();
            sw420Recheck();
        }
        MotionSample sample = sampleOnce();
        xSemaphoreGive(sensorLock);

        // Every sample, quiet ones included: the classifier closes episodes on silence.
        if (sampleHook) {
            sampleHook(sample);
        }
    }
}

void motionStart(void (*onSample)(const MotionSample&)) {
    sampleHook = onSample;
    // Same core as loop() and one priority above it, so sampling preempts loop() but never
    // competes with the WiFi stack on core 0.
    xTaskCreatePinnedToCore(motionTask, "motion", 4096, nullptr, 2, nullptr, 1);
}
