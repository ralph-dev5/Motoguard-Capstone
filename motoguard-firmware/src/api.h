#pragma once

#include "calibration.h"
#include "gps.h"
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
};

HeartbeatResult apiHeartbeat(const char* state, float batteryVolts, const GpsFix& fix, bool ownerNearby);

// Minimal "still here" ping behind the dashboard's on/off badge. No body, no response parsing
// and no logging on success: it runs once a second, so anything else would flood the serial line.
// syncRequested: the dashboard changed something (arm/disarm) that the next heartbeat fetches.
bool apiPing(bool& syncRequested);
bool apiSendLocation(const GpsFix& fix);
// level is null for alerts that are not a motion episode; evidence is null likewise.
bool apiSendAlert(const char* type, const char* level, const GpsFix& fix, bool smsSent,
                  const ThreatReport* evidence);
bool apiSendCalibration(const CalibrationResult& result);

enum class PairResult { Paired, Rejected, Unreachable };
// Trades a dashboard pairing code for this device's token (stored on success).
PairResult apiPair(const String& code);
