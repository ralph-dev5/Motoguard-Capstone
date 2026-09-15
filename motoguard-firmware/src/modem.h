#pragma once

#include <Arduino.h>
#include <Client.h>

void netBegin();
bool netEnsureConnected();
Client& netClient();
bool netSendSms(const char* number, const String& message);
