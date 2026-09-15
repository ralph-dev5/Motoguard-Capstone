<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info("[SMS to {$to}] {$message}");
    }
}
