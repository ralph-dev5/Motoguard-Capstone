#pragma once

#include "gps.h"

struct HeartbeatResult {
    bool ok;
    bool armed;
};

HeartbeatResult apiHeartbeat(float batteryVolts, const GpsFix& fix);
bool apiSendLocation(const GpsFix& fix);
bool apiSendAlert(const char* type, const GpsFix& fix, bool smsSent, float accelDelta, float tiltDeg);
