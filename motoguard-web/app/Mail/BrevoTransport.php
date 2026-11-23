<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Brevo's HTTPS API instead of SMTP.
 *
 * Render's free plan blocks outgoing SMTP ports (25, 465, 587), so password reset and
 * verification emails are delivered over port 443 with an API key instead.
 */
class BrevoTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(protected string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $sender = $email->getFrom()[0];

        $payload = array_filter([
            'sender' => $this->formatAddress($sender),
            'to' => array_map($this->formatAddress(...), $email->getTo()),
            'cc' => array_map($this->formatAddress(...), $email->getCc()) ?: null,
            'bcc' => array_map($this->formatAddress(...), $email->getBcc()) ?: null,
            'replyTo' => ($email->getReplyTo()[0] ?? null) ? $this->formatAddress($email->getReplyTo()[0]) : null,
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        Http::withHeaders(['api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(15)
            ->post(self::ENDPOINT, $payload)
            ->throw();
    }

    /**
     * @return array{email: string, name?: string}
     */
    protected function formatAddress(Address $address): array
    {
        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName(),
        ]);
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }
}
