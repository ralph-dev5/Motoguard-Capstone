#include "breadcrumb.h"

#include <Arduino.h>
#include <esp_system.h>

static RTC_NOINIT_ATTR char lastStep[48];

void breadcrumb(const char* step) {
    strlcpy(lastStep, step, sizeof(lastStep));
}

void breadcrumbReport() {
    esp_reset_reason_t why = esp_reset_reason();
    if (why == ESP_RST_TASK_WDT || why == ESP_RST_INT_WDT || why == ESP_RST_WDT || why == ESP_RST_PANIC) {
        lastStep[sizeof(lastStep) - 1] = 0;
        Serial.printf("[boot] Restarted by the watchdog; the loop was stuck at: %s\n", lastStep);
    }
    lastStep[0] = 0;
}
