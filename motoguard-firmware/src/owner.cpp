#include "owner.h"

#include <NimBLEDevice.h>
#include <NimBLEHIDDevice.h>
#include <mbedtls/md.h>

#include "config.h"
#include "settings.h"

// The unit presents itself as a Bluetooth remote with two buttons it never presses. Phones (iPhone
// included) list such a device in their Bluetooth settings and, once paired, reconnect to it on their
// own whenever it is in range - that reconnection is the "owner is here" signal. No app is needed,
// and phones' rotating Bluetooth addresses do not matter because the pairing keys identify the phone.

static volatile bool phoneLinked = false;
static volatile unsigned long phoneLeftMs = 0;
static volatile uint16_t phoneHandle = BLE_HS_CONN_HANDLE_NONE;

// Consumer-control report (volume up / down), so the phone does not treat it as a keyboard.
static const uint8_t REPORT_MAP[] = {
    0x05, 0x0C,        // Usage Page (Consumer)
    0x09, 0x01,        // Usage (Consumer Control)
    0xA1, 0x01,        // Collection (Application)
    0x85, 0x01,        //   Report ID 1
    0x15, 0x00,        //   Logical Minimum 0
    0x25, 0x01,        //   Logical Maximum 1
    0x75, 0x01,        //   Report Size 1
    0x95, 0x02,        //   Report Count 2
    0x09, 0xE9,        //   Usage (Volume Up)
    0x09, 0xEA,        //   Usage (Volume Down)
    0x81, 0x02,        //   Input (Data, Variable, Absolute)
    0x95, 0x06,        //   Report Count 6 (padding)
    0x81, 0x03,        //   Input (Constant)
    0xC0,              // End Collection
};

class PhoneCallbacks : public NimBLEServerCallbacks {
    void onConnect(NimBLEServer* server, ble_gap_conn_desc* desc) override {
        // Keep advertising so a second paired phone (or the same one after a drop) can still connect.
        NimBLEDevice::startAdvertising();
    }

    void onAuthenticationComplete(ble_gap_conn_desc* desc) override {
        // Only an encrypted link with a bonded phone counts: that phone was paired with the PIN.
        if (desc->sec_state.encrypted && desc->sec_state.bonded) {
            phoneHandle = desc->conn_handle;
            phoneLinked = true;
            Serial.println("[owner] paired phone connected");
        } else {
            Serial.println("[owner] pairing failed or refused (wrong PIN?)");
            NimBLEDevice::getServer()->disconnect(desc->conn_handle);
        }
    }

    void onDisconnect(NimBLEServer* server, ble_gap_conn_desc* desc) override {
        if (desc->conn_handle == phoneHandle) {
            // Stamp the time before clearing the link: loop() runs on the other core and must never
            // see "not linked" without the grace period, or a short drop would re-arm at once.
            phoneLeftMs = millis();
            phoneLinked = false;
            phoneHandle = BLE_HS_CONN_HANDLE_NONE;
            Serial.println("[owner] paired phone out of range");
        }
        NimBLEDevice::startAdvertising();
    }
};

uint32_t ownerPairPin() {
    const String msg = "pair:" + settingsDeviceId();
    const char* key = DEVICE_ENROLL_SECRET;
    unsigned char mac[32];
    mbedtls_md_hmac(mbedtls_md_info_from_type(MBEDTLS_MD_SHA256),
                    reinterpret_cast<const unsigned char*>(key), strlen(key),
                    reinterpret_cast<const unsigned char*>(msg.c_str()), msg.length(), mac);
    // First four bytes as a big-endian number, last six digits: the same as the server.
    uint32_t n = (uint32_t(mac[0]) << 24) | (uint32_t(mac[1]) << 16) | (uint32_t(mac[2]) << 8) | mac[3];
    return n % 1000000UL;
}

void ownerBegin() {
    NimBLEDevice::init(("MotoGuard " + settingsDeviceId()).c_str());
    NimBLEDevice::setSecurityAuth(true, true, true);              // bond, MITM protection, secure connections
    NimBLEDevice::setSecurityIOCap(BLE_HS_IO_DISPLAY_ONLY);       // the phone asks the owner to type the PIN
    NimBLEDevice::setSecurityPasskey(ownerPairPin());

    NimBLEServer* server = NimBLEDevice::createServer();
    server->setCallbacks(new PhoneCallbacks());

    NimBLEHIDDevice* hid = new NimBLEHIDDevice(server);
    hid->manufacturer("MotoGuard+");
    hid->pnp(0x02, 0xe502, 0xa111, 0x0210);
    hid->hidInfo(0x00, 0x01);
    hid->reportMap((uint8_t*) REPORT_MAP, sizeof(REPORT_MAP));
    hid->inputReport(1);
    hid->setBatteryLevel(100);
    hid->startServices();

    NimBLEAdvertising* adv = NimBLEDevice::getAdvertising();
    adv->setAppearance(0x03C0);   // generic HID device
    adv->addServiceUUID(hid->hidService()->getUUID());
    adv->setScanResponse(true);
    adv->start();
    Serial.printf("[owner] pair your phone: Bluetooth settings > \"MotoGuard %s\", PIN %06lu (%d phone(s) paired)\n",
                  settingsDeviceId().c_str(), (unsigned long) ownerPairPin(), NimBLEDevice::getNumBonds());
}

void ownerForgetPhones() {
    if (phoneHandle != BLE_HS_CONN_HANDLE_NONE) {
        NimBLEDevice::getServer()->disconnect(phoneHandle);
    }
    NimBLEDevice::deleteAllBonds();
    phoneLinked = false;
    phoneLeftMs = 0;
    Serial.println("[owner] all paired phones forgotten");
}

bool ownerNearby() {
    // A paired phone that just dropped gets a grace period, so a brief Bluetooth hiccup does not re-arm.
    unsigned long left = phoneLeftMs;
    return phoneLinked || (left != 0 && millis() - left < OWNER_ABSENT_MS);
}
