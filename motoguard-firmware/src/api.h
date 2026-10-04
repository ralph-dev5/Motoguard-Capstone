#pragma once

#include "calibration.h"
#include "gps.h"
#include "outbox.h"
#include "threat.h"

struct HeartbeatResult {
    bool ok;
    bool armed;
    bool calibrate;       // the owner asked for a calibration that has not been reported yet
    bool hasOwnerBeacon;  // the server sent the owner's Bluetooth ID (empty when none is set)
    String ownerBeacon;
    bool hasBackupWifi;   // the server said which phone hotspot to use (empty name when none)
    String backupSsid;
    String backupPass;
    bool rejected;        // the server no longer accepts this board's token
    bool hasOwnerPhone;   // the server sent the number to text (empty when none is set)
    String ownerPhone;
};

HeartbeatResult apiHeartbeat(const char* state, float batteryVolts, const GpsFix& fix, bool ownerNearby);

// Minimal "still here" ping behind the dashboard's on/off badge. No body, no response parsing
// and no logging on success: it runs once a second, so anything else would flood the serial line.
// syncRequested: the dashboard changed something (arm/disarm) that the next heartbeat fetches.
bool apiPing(bool& syncRequested);
bool apiSendLocation(const GpsFix& fix);
// Points recorded while offline, oldest first. Returns the HTTP status (zero or less: not reached).
int apiSendLocations(const LocationRecord* points, int count);
// late: the alert happened earlier and is being delivered now, so its time travels with it.
// Returns the HTTP status (zero or less: the server was not reached).
int apiSendAlert(const AlertRecord& alert, bool late);
bool apiSendCalibration(const CalibrationResult& result);

enum class EnrollResult {
    Enrolled,     // the server handed over a token (saved)
    NotAdded,     // nobody has added this board's ID on the dashboard yet
    Refused,      // the server could not verify this board (DEVICE_ENROLL_SECRET differs)
    Unreachable,  // no network or no answer: worth another try
};
// Asks the server for this board's token using the ID built into its chip.
EnrollResult apiEnroll();
