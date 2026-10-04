#include "owner.h"

#include <NimBLEDevice.h>

#include "config.h"

enum class KeyType : uint8_t { None, Beacon, Address };

// Written from loop(), read from the NimBLE host task. Keys change only when the owner edits them
// on the dashboard, so a short critical section around the copy is all the locking needed.
static portMUX_TYPE keyLock = portMUX_INITIALIZER_UNLOCKED;
static KeyType keyType = KeyType::None;
static uint8_t beaconUuid[16];
static char address[18];

static volatile unsigned long lastSeenMs = 0;
static volatile int lastRssi = 0;
static bool scanning = false;

static int hexValue(char c) {
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    if (c >= 'A' && c <= 'F') return c - 'A' + 10;
    return -1;
}

// 36-character UUID with dashes -> 16 bytes, in the order an iBeacon carries them.
static bool parseUuid(const String& key, uint8_t out[16]) {
    if (key.length() != 36) {
        return false;
    }
    int n = 0;
    for (size_t i = 0; i < key.length(); i += 2) {
        if (key[i] == '-') {
            i -= 1;
            continue;
        }
        int hi = hexValue(key[i]);
        int lo = i + 1 < key.length() ? hexValue(key[i + 1]) : -1;
        if (hi < 0 || lo < 0 || n >= 16) {
            return false;
        }
        out[n++] = (uint8_t) ((hi << 4) | lo);
    }
    return n == 16;
}

static bool isAddress(const String& key) {
    if (key.length() != 17) {
        return false;
    }
    for (int i = 0; i < 17; i++) {
        if (i % 3 == 2 ? key[i] != ':' : hexValue(key[i]) < 0) {
            return false;
        }
    }
    return true;
}

// An iBeacon is Apple manufacturer data: 4C 00, type 02, length 15, then the 16-byte UUID.
static bool isOwnerBeacon(const std::string& data) {
    return data.size() >= 25
        && (uint8_t) data[0] == 0x4C && (uint8_t) data[1] == 0x00
        && (uint8_t) data[2] == 0x02 && (uint8_t) data[3] == 0x15
        && memcmp(data.data() + 4, beaconUuid, 16) == 0;
}

class OwnerScanCallbacks : public NimBLEAdvertisedDeviceCallbacks {
    void onResult(NimBLEAdvertisedDevice* device) override {
        int rssi = device->getRSSI();
        if (rssi < OWNER_MIN_RSSI) {
            return;
        }

        bool match = false;
        portENTER_CRITICAL(&keyLock);
        KeyType type = keyType;
        portEXIT_CRITICAL(&keyLock);

        if (type == KeyType::Beacon && device->haveManufacturerData()) {
            match = isOwnerBeacon(device->getManufacturerData());
        } else if (type == KeyType::Address) {
            match = device->getAddress().toString() == std::string(address);
        }

        if (match) {
            lastSeenMs = millis();
            lastRssi = rssi;
        }
    }
};

static void startScan() {
    if (scanning) {
        return;
    }
    NimBLEScan* scan = NimBLEDevice::getScan();
    // Passive, and a 30 ms window in every 100 ms: the WiFi radio shares the antenna, and a
    // continuous scan starves it. A beacon advertising every 100-300 ms is still caught in seconds.
    scan->setActiveScan(false);
    scan->setInterval(160);
    scan->setWindow(48);
    scan->setDuplicateFilter(false);
    scan->setMaxResults(0);  // report through the callback only; never keep a growing result list
    scanning = scan->start(0, nullptr, false);
    Serial.printf("[owner] Bluetooth scan %s\n", scanning ? "running" : "FAILED to start");
}

static void stopScan() {
    if (scanning) {
        NimBLEDevice::getScan()->stop();
        scanning = false;
    }
}

void ownerBegin() {
    NimBLEDevice::init("");
    NimBLEDevice::getScan()->setAdvertisedDeviceCallbacks(new OwnerScanCallbacks(), true);
}

void ownerSetKey(const String& raw) {
    String key = raw;
    key.trim();
    key.toLowerCase();

    uint8_t uuid[16];
    KeyType type = parseUuid(key, uuid) ? KeyType::Beacon : isAddress(key) ? KeyType::Address : KeyType::None;

    portENTER_CRITICAL(&keyLock);
    keyType = type;
    memcpy(beaconUuid, uuid, sizeof(uuid));
    strlcpy(address, type == KeyType::Address ? key.c_str() : "", sizeof(address));
    portEXIT_CRITICAL(&keyLock);
    lastSeenMs = 0;

    if (type == KeyType::None) {
        stopScan();
        Serial.println(key.isEmpty() ? "[owner] no owner Bluetooth ID set - detection off"
                                     : "[owner] owner Bluetooth ID not recognised - detection off");
        return;
    }
    Serial.printf("[owner] watching for %s %s\n", type == KeyType::Beacon ? "beacon" : "tag", key.c_str());
    startScan();
}

bool ownerNearby() {
    unsigned long seen = lastSeenMs;
    return keyType != KeyType::None && seen != 0 && millis() - seen < OWNER_ABSENT_MS;
}

int ownerRssi() {
    return lastRssi;
}
