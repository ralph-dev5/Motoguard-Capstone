#pragma once

// The step the main loop is on, kept in memory that survives a software restart. After the
// watchdog restarts a stuck board, setup() prints it, which names where it was stuck.
void breadcrumb(const char* step);
// Prints the step recorded before a watchdog restart (nothing after a normal power-on).
void breadcrumbReport();
