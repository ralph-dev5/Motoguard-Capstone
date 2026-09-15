#pragma once

// Copy this file to config.h (not committed to git) and fill in your values.

// ---- Laravel server ----
// Host only, no "http://". For cellular testing use your ngrok host, e.g. "abcd-1234.ngrok-free.app" with port 80.
// For WiFi testing on the same network use your PC's LAN IP and run: php artisan serve --host=0.0.0.0
#define API_HOST "192.168.1.10"
#define API_PORT 8000
#define DEVICE_TOKEN "paste-the-token-from-the-devices-page"

// ---- Owner ----
#define OWNER_PHONE "+639171234567"

// ---- Cellular (ask your carrier for the APN) ----
#define SIM_APN "internet"
#define SIM_APN_USER ""
#define SIM_APN_PASS ""
#define MODEM_BAUD 115200      // SIM800L modules often need 9600

// ---- WiFi (esp32dev-wifi environment only) ----
#define WIFI_SSID "your-wifi"
#define WIFI_PASS "your-wifi-password"

// ---- Wiring (ESP32 GPIO) ----
#define PIN_MODEM_RX 16        // ESP32 RX <- modem TX
#define PIN_MODEM_TX 17        // ESP32 TX -> modem RX
#define PIN_GPS_RX 26          // ESP32 RX <- GPS TX
#define PIN_GPS_TX 27          // ESP32 TX -> GPS RX
#define PIN_I2C_SDA 21         // MPU6050 SDA
#define PIN_I2C_SCL 22         // MPU6050 SCL
#define PIN_BUZZER 25
#define PIN_BATTERY_ADC 34     // motorcycle 12V through a voltage divider
#define GPS_BAUD 9600

// ---- Battery monitoring ----
// Set to 0 if the voltage divider is not wired, otherwise every boot reports a power cut.
#define BATTERY_MONITOR_ENABLED 1
// Vbat = Vadc * ratio. A 100k (top) / 22k (bottom) divider gives (100 + 22) / 22 = 5.545
#define BATTERY_DIVIDER_RATIO 5.545f
#define LOW_BATTERY_VOLTS 11.6f
#define POWER_CUT_VOLTS 6.0f

// ---- Motion detection ----
#define MOTION_ACCEL_THRESHOLD 1.2f        // m/s^2 away from the parked baseline
#define MOTION_TILT_THRESHOLD_DEG 12.0f
#define MOTION_CONSECUTIVE_SAMPLES 5
#define MOTION_SAMPLE_INTERVAL_MS 50
#define ALERT_QUIET_RESET_MS 60000         // back to ARMED after this long without motion

// ---- Reporting ----
#define HEARTBEAT_INTERVAL_MS 30000
#define LOCATION_INTERVAL_MOVING_MS 10000
#define LOCATION_INTERVAL_PARKED_MS 120000
#define SMS_COOLDOWN_MS 120000
