#include "buzzer.h"

#include <Arduino.h>
#include <Ticker.h>

#include "config.h"

static Ticker beepTicker;

// Counts on/off halves of the current burst. Beeping runs from a timer, not from loop(): a ping
// blocks loop() for up to a second, and sampling a 1 s on/off pattern once a second always lands
// on the same half, so the buzzer never went quiet. The ticker runs for the whole uptime and only
// counts down, so starting or stopping a burst is a single int write from any task.
static volatile int beepHalvesLeft = 0;

// Active-low modules (PNP driver, "I/O" pin) sound when the pin is LOW, so "off" is HIGH.
static void buzzer(bool on) {
    digitalWrite(PIN_BUZZER, (on != (BUZZER_ACTIVE_LOW != 0)) ? HIGH : LOW);
}

static void beepTick() {
    int left = beepHalvesLeft;
    if (left <= 0) {
        buzzer(false);                  // idle: also repairs a stop that raced a tick
        return;
    }
    beepHalvesLeft = left - 1;
    buzzer((left - 1) % 2 == 1);
}

void buzzerBegin() {
    buzzer(false);                      // latch "off" first so an active-low module doesn't chirp at boot
    pinMode(PIN_BUZZER, OUTPUT);
    buzzer(false);
    beepTicker.attach_ms(BUZZER_BEEP_MS, beepTick);
}

void buzzerBeep(int count) {
    beepHalvesLeft = count * 2;
}

void buzzerStop() {
    beepHalvesLeft = 0;
    buzzer(false);
}
