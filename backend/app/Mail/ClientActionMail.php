<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Every client-facing e-mail (document request, correction, reminders, signing invitation, test): one
 * responsive HTML layout plus a plain-text alternative, filled from MailContent.
 *
 * Sent SYNCHRONOUSLY by EmailDeliveryService and never queued: the secure action URL exists only in memory
 * while the message is built and submitted (ShouldQueue is deliberately not implemented).
 */
class ClientActionMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly MailContent $mailContent,
        public readonly string $deliveryUuid,
        public readonly bool $automatic = false,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('lexora.communications.reply_to');

        return new Envelope(
            subject: $this->mailContent->subject,
            replyTo: is_string($replyTo) && $replyTo !== '' ? [new Address($replyTo, (string) (config('lexora.communications.reply_to_name') ?? ''))] : [],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            // Correlates the message with its email_deliveries row (no secret: the uuid is not a credential).
            'X-Lexora-Delivery' => $this->deliveryUuid,
            // Reminders are automatic messages: ask auto-responders not to answer them (RFC 3834).
            'Auto-Submitted' => $this->automatic ? 'auto-generated' : null,
        ]));
    }

    public function content(): Content
    {
        $c = $this->mailContent;

        return new Content(
            view: 'mail.client-action',
            text: 'mail.client-action-text',
            with: [
                'c' => $c,
                'firmName' => (string) config('lexora.firm_name'),
                'contactEmail' => config('lexora.communications.firm_contact_email'),
                'postalAddress' => config('lexora.communications.firm_postal_address'),
            ],
        );
    }

    public function __debugInfo(): array
    {
        return ['delivery' => $this->deliveryUuid, 'content' => $this->mailContent];
    }
}
