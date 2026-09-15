<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\Alert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAlertSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public Alert $alert) {}

    public function handle(SmsGateway $sms): void
    {
        $alert = $this->alert->loadMissing('device');
        $phone = $alert->device->owner_phone;

        if ($alert->sms_sent || ! $phone) {
            return;
        }

        $sms->send($phone, $alert->smsMessage());

        $alert->update(['sms_sent' => true]);
    }
}
