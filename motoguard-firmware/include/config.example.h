#pragma once

// Copy this file to config.h (not committed to git) and fill in your values.

// ---- Laravel server ----
// Host only, no "http://". For cellular testing use your ngrok host, e.g. "abcd-1234.ngrok-free.app" with port 80.
// For WiFi testing on the same network use your PC's LAN IP and run: php artisan serve --host=0.0.0.0
// WiFi builds: these are only defaults, the setup portal can change them.
#define API_HOST "192.168.1.10"
#define API_PORT 8000
#define DEVICE_TOKEN "paste-the-token-from-the-devices-page"

// Bump this whenever you change the three values above and want them to win over what the
// setup portal saved. On the first boot after a bump the firmware re-seeds its stored copy
// from this file; leave it alone and portal edits survive reflashing. WiFi credentials are
// kept by WiFiManager separately and are never touched.
#define SETTINGS_VERSION 1

// ---- Owner ----
#define OWNER_PHONE "+639171234567"

// ---- Cellular (ask your carrier for the APN) ----
#define SIM_APN "internet"
#define SIM_APN_USER ""
#define SIM_APN_PASS ""
#define MODEM_BAUD 115200      // SIM800L modules often need 9600

// ---- WiFi setup portal (esp32dev-wifi environment only) ----
// With no saved WiFi, or when BOOT is pressed within 3 s of power-on, the ESP32 opens this hotspot.
#define SETUP_AP_NAME "MotoGuard-Setup"
#define SETUP_AP_PASS "motoguard"      // at least 8 characters
#define SETUP_PORTAL_TIMEOUT_S 180     // only once WiFi is saved; first setup waits indefinitely
#define OTA_HOSTNAME "motoguard"     // flash over WiFi: motoguard.local
#define OTA_PASSWORD "motoguard"     // password espota must present
#define PIN_SETUP_BUTTON 0             // BOOT button
#define DISCOVERY_PORT 47100           // UDP port the server announces itself on (php artisan motoguard:announce)
#define WIFI_SWITCH_MS 30000           // offline this long: try the other network (home WiFi <-> phone hotspot).
                                       // 30 s: an iPhone hotspot can take 15-20 s to let a new device in
#define SERVER_LOST_SWITCH_MS 45000    // connected but the server unreachable this long: try the other network too

// ---- Motion sensor ----
#define MOTION_SENSOR_MPU6050 1
#define MOTION_SENSOR_SW420 2
#define MOTION_SENSOR_BOTH 3          // read both: SW-420 for knocks, MPU for movement and tilt
#define MOTION_SENSOR MOTION_SENSOR_BOTH

// ---- Wiring (ESP32 GPIO) ----
#define PIN_VIBRATION 19       // SW-420 DO (power the sensor from 3V3, not 5V)
#define PIN_MODEM_RX 16        // ESP32 RX <- modem TX
#define PIN_MODEM_TX 17        // ESP32 TX -> modem RX
#define PIN_GPS_RX 26          // ESP32 RX <- GPS TX
#define PIN_GPS_TX 27          // ESP32 TX -> GPS RX
#define PIN_I2C_SDA 21         // MPU6050 SDA
#define PIN_I2C_SCL 22         // MPU6050 SCL
#define PIN_BUZZER 25          // buzzer module I/O (power the module from 3V3, not 5V)
// 1 for modules that sound when I/O is LOW ("Active Low", PNP transistor), 0 for bare buzzers
// or HIGH-triggered modules. Wrong value = buzzer screams constantly and goes quiet on alert.
#define BUZZER_ACTIVE_LOW 1
#define BUZZER_ALERT_BEEPS 10    // theft attempt: beeps per burst; restarts while tampering goes on
#define BUZZER_BEEP_MS 300       // length of each beep and of the gap after it
#define PIN_BATTERY_ADC 34     // motorcycle 12V through a voltage divider
#define GPS_BAUD 9600
// The receiver talks continuously at ~600-900 B/s whether or not it has a fix, but the loop only
// drains it between network calls, and a single blocking HTTP request stalls the loop for up to
// PRESENCE_PING_TIMEOUT_MS (and the heartbeat's own timeout on top). Whatever arrives during that
// stall has to fit in the UART ring buffer, because arduino-esp32 handles a FIFO overflow by
// flushing the WHOLE buffer: one overflow throws away every sentence queued behind it. Size this
// for the worst stall, not the average one - a 2 KB buffer covers barely two seconds.
#define GPS_RX_BUFFER_BYTES 16384
// Cap on how much GPS serial is drained per loop. This has to be able to empty a full buffer in
// one pass; leaving bytes behind after a stall just carries the backlog into the next overflow.
// GPS_READ_BUDGET_MS is the real guard against a miswired or floating RX line, which delivers
// framing noise as fast as the loop can read it.
#define GPS_READ_BUDGET_BYTES GPS_RX_BUFFER_BYTES
#define GPS_READ_BUDGET_MS 50
// How often to report GPS health. Without this the module is completely silent, so bad wiring
// and a slow first fix look identical.
#define GPS_STATUS_INTERVAL_MS 10000

// ---- GPS accuracy ----
// A position is only reported as accurate once the solution clears both of these. HDOP is the
// receiver's own error estimate: ~1 excellent, 2-5 usable, above 10 the fix can be tens of metres
// out. A first fix almost always arrives loose and tightens over the following minute, so trusting
// it immediately is what makes a parked bike appear to wander down the street.
#define GPS_MIN_SATELLITES 4
#define GPS_MAX_HDOP 5.0f

// ---- Battery monitoring ----
// Set to 0 if the voltage divider is not wired, otherwise every boot reports a power cut.
#define BATTERY_MONITOR_ENABLED 1
// Vbat = Vadc * ratio. A 100k (top) / 22k (bottom) divider gives (100 + 22) / 22 = 5.545
#define BATTERY_DIVIDER_RATIO 5.545f
#define LOW_BATTERY_VOLTS 11.6f
#define POWER_CUT_VOLTS 6.0f

// ---- Motion detection ----
#define MOTION_TILT_THRESHOLD_DEG 8.0f     // MPU6050: tilt held past this counts as suspicious
#define MOTION_CONSECUTIVE_SAMPLES 10      // MPU6050: a tilt must be held 10 x 50 ms = 0.5 s to count
// The jolt and push values below are the tested defaults. Calibration from the dashboard measures
// the unit's own resting noise and can only raise them (see CALIBRATION_* and src/calibration.cpp).
#define MOTION_JERK_THRESHOLD 1.5f         // MPU6050: m/s^2 change between two samples that counts as a tap. Measured: parked noise up to 0.26, gentle touch 0.5-1.1, tap 5-50. A tap rings under 0.1 s, well inside THREAT_HIT_GAP_MS
#define VIBRATION_WINDOW_MS 1000           // SW-420: look for shaking within this window
#define VIBRATION_ACTIVE_SAMPLES 1         // SW-420: 1 = a single touch or knock alarms. Fine-tune with the blue pot on the module
// Sanity ceiling. A spring switch cannot bounce faster than this; more edges in one sample means
// the line is floating (sensor unpowered or DO unplugged), which would otherwise alert forever.
#define VIBRATION_MAX_PULSES_PER_SAMPLE 500
#define MOTION_SAMPLE_INTERVAL_MS 50
#define ALERT_QUIET_RESET_MS 30000         // a suspicious / theft episode ends after this long without motion
#define ALERT_REPEAT_MS 15000              // during a theft attempt, a new alert is logged at most this often

// ---- Movement classification ----
// Three rules, so the owner can predict the result:
//   minor         1-2 separate taps or bumps                -> logged only, no buzzer, no SMS
//   suspicious    THREAT_SUSPICIOUS_HITS or more taps       -> warning beeps, dashboard alert
//                 (taps alone never go further, however many)
//   theft attempt pushed, lifted, carried or shaken without a break for THREAT_CONTINUOUS_MS
//                                                           -> full alarm, SMS, dashboard alert
#define THREAT_HIT_GAP_MS 600              // activity closer than this is the same tap. Measured: a tap rings under 0.1 s
#define THREAT_SUSPICIOUS_HITS 3           // separate taps in one episode
#define THREAT_CONTINUOUS_MS 5000          // this long of unbroken pushing, lifting or handling is a theft attempt
#define THREAT_RUN_GAP_MS 500              // a pause longer than this breaks the run; taps 1 s apart never add up
#define MOTION_HANDLING_JITTER 0.20f       // m/s^2 spike-capped jitter that means "being handled". Measured: parked 0.05-0.12,
                                           // held or pushed 0.4-0.65, a hard tap's tail under 0.2 within 0.5 s
#define MOTION_PUSH_ACCEL 0.30f            // m/s^2: shown by calibration. Measured: parked 0.00-0.02, slow slide 0.49
#define MOTION_PUSH_RUMBLE 0.30f           // m/s^2: shown by calibration. Measured: parked 0.05-0.08, slow slide 0.50-0.78
#define THREAT_THEFT_TILT_DEG 15.0f        // tilt reported with the alert as evidence

// Calibration, started from the dashboard: the device records its own resting noise for
// CALIBRATION_MS and sets the level-3 thresholds to that noise times CALIBRATION_MARGIN, clamped
// to the floor and ceiling below. The floor is the tested defaults above, so calibration only ever
// raises the thresholds for a noisier mounting (engine, loose bracket) and never undercuts them;
// the ceiling keeps a noisy mounting from switching detection off.
#define CALIBRATION_MS 15000
#define CALIBRATION_MARGIN 3.0f
#define CALIBRATION_MIN_JERK MOTION_JERK_THRESHOLD         // never below the tested defaults: a quiet sensor on a desk
                                                          // measured 0.18, and 3x that let one tap ring into several touches
#define CALIBRATION_MAX_JERK 8.0f
#define CALIBRATION_MIN_PUSH_ACCEL MOTION_PUSH_ACCEL
#define CALIBRATION_MAX_PUSH_ACCEL 2.0f
#define CALIBRATION_MIN_PUSH_RUMBLE MOTION_PUSH_RUMBLE
#define CALIBRATION_MAX_PUSH_RUMBLE 1.5f
#define THREAT_MINOR_QUIET_MS 10000        // a minor episode is closed and logged after this much quiet
#define THREAT_WARNING_BEEPS 3             // suspicious: short warning

#define MOTION_TRACE 1                     // 1 prints the motion signals 10x a second while anything moves, for tuning; 0 when done

// ---- Reporting ----
#define HEARTBEAT_INTERVAL_MS 30000
// Lightweight "still here" ping. Carries no telemetry and never touches the database server
// side, so it can run far more often than the heartbeat; this is what drives the on/off badge.
// The dashboard's window is set server side in config/presence.php (PRESENCE_OFFLINE_AFTER),
// currently 6 s because php artisan serve queues a ping behind any page render.
#define PRESENCE_PING_INTERVAL_MS 1000
// Must clear the server's worst normal response, not its best: php artisan serve is
// single-threaded and regularly takes ~1 s when a page render is in front of the ping. At 1200
// this timed out constantly, which showed up as the badge flapping and "[ping] failing (-3)".
#define PRESENCE_PING_TIMEOUT_MS 3000
#define LOCATION_INTERVAL_MOVING_MS 10000
#define LOCATION_INTERVAL_PARKED_MS 120000
#define SMS_COOLDOWN_MS 120000

// Bluetooth owner detection (src/owner.cpp). The owner's Bluetooth ID is set on the dashboard.
#define OWNER_ABSENT_MS 20000   // owner counts as gone this long after the last beacon heard
#define OWNER_MIN_RSSI -90      // ignore beacons weaker than this (dBm); raise toward -70 to need the phone closer
