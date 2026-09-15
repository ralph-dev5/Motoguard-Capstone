<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;

class SemaphoreSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $senderName = null,
    ) {}

    public function send(string $to, string $message): void
    {
        Http::asForm()
            ->timeout(15)
            ->post('https://api.semaphore.co/api/v4/messages', array_filter([
                'apikey' => $this->apiKey,
                'number' => $to,
                'message' => $message,
                'sendername' => $this->senderName,
            ]))
            ->throw();
    }
}
