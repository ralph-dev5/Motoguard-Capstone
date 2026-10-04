#pragma once

void buzzerBegin();
// Starts (or restarts) a burst of `count` beeps. Safe to call from any task.
void buzzerBeep(int count);
void buzzerStop();
