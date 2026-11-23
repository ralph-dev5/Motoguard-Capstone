<?php

namespace Tests\Feature;

use App\Mail\BrevoTransport;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BrevoMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'brevo',
            'mail.from.address' => 'alerts@motoguard.test',
            'mail.from.name' => 'MotoGuard+',
            'services.brevo.key' => 'test-brevo-key',
        ]);
        Mail::purge('brevo');
    }

    public function test_password_reset_email_is_sent_through_the_brevo_api(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['messageId' => '<abc@smtp-relay.brevo.com>'], 201)]);

        User::factory()->make(['email' => 'owner@example.com'])
            ->notifyNow(new ResetPassword('reset-token-123'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === BrevoTransport::ENDPOINT
                && $request->hasHeader('api-key', 'test-brevo-key')
                && $request['sender'] === ['email' => 'alerts@motoguard.test', 'name' => 'MotoGuard+']
                && $request['to'] === [['email' => 'owner@example.com']]
                && $request['subject'] === 'Reset your password'
                && str_contains($request['htmlContent'], 'reset-token-123')
                && str_contains($request['textContent'], 'reset-token-123');
        });
    }

    public function test_a_brevo_error_is_not_silently_swallowed(): void
    {
        Http::fake([BrevoTransport::ENDPOINT => Http::response(['message' => 'Key not found'], 401)]);

        $this->expectException(\Throwable::class);

        Mail::raw('Hello', fn ($message) => $message->to('owner@example.com')->subject('Test'));
    }

    public function test_the_brevo_mailer_is_registered(): void
    {
        Notification::fake();

        $this->assertSame('brevo+api', (string) Mail::mailer('brevo')->getSymfonyTransport());
    }
}
