<?php

namespace App\Services\Communications;

use App\Enums\EmailCommunicationType;
use App\Mail\MailContent;
use App\Models\DocumentRequest;
use App\Models\SigningRequest;
use App\Support\LegalDate;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Wording of every client e-mail. Subjects never contain matter numbers, matter titles or document titles
 * (a subject is visible in previews and notifications); details stay inside the message and behind the
 * secure link. Correction reasons are NOT copied into e-mails — the client reads them on the secure page.
 * Internal notes are never available here.
 */
class MailComposer
{
    public function documentRequest(EmailCommunicationType $type, DocumentRequest $request, string $recipientName, #[\SensitiveParameter] string $url, DateTimeInterface $expiresAt, ?string $slot = null): MailContent
    {
        $firm = $this->firm();
        $due = $this->dueLine($request);
        $notes = [
            'This secure link is personal to you and expires on '.$this->when($expiresAt).'. No account or password is needed.',
            'Earlier links we sent you for this request stop working once this e-mail is sent.',
            'If you did not expect this e-mail, please do not use the link and contact us.',
        ];
        $details = array_values(array_filter([
            ['label' => 'Requested document', 'value' => $request->title],
            ['label' => 'Category', 'value' => $request->requested_category->label()],
            $due !== null ? ['label' => 'Due', 'value' => $due] : null,
        ]));

        return match ($type) {
            EmailCommunicationType::DocumentCorrection => new MailContent(
                subject: "Correction requested — {$firm}",
                preheader: 'Please upload a corrected document using your secure link.',
                headline: 'Please upload a corrected document',
                greeting: "Dear {$recipientName},",
                paragraphs: [
                    "Thank you for the document you sent. {$firm} has reviewed it and needs a corrected version.",
                    'Open the secure page to see what needs to change, then upload the corrected PDF. Your earlier upload stays on record.',
                ],
                details: $details,
                actionLabel: 'Review and upload the correction',
                actionUrl: $url,
                notes: $notes,
            ),
            EmailCommunicationType::DocumentRequestReminder => new MailContent(
                subject: "Reminder: document request — {$firm}",
                preheader: 'A requested document is still outstanding.',
                headline: $this->isOverdue($request) ? 'A requested document is overdue' : 'Reminder: a document is still needed',
                greeting: "Dear {$recipientName},",
                paragraphs: [
                    $request->status->value === 'correction_requested'
                        ? "{$firm} is still waiting for the corrected document described on your secure page."
                        : "{$firm} is still waiting for the document below.",
                    'You can upload it securely with the button below. If you have already sent it, you can ignore this reminder.',
                ],
                details: $details,
                actionLabel: 'Upload the document securely',
                actionUrl: $url,
                notes: $notes,
            ),
            default => new MailContent(
                subject: "Document request — {$firm}",
                preheader: 'Please upload a requested document using your secure link.',
                headline: 'Please upload a document',
                greeting: "Dear {$recipientName},",
                paragraphs: ["{$firm} asks you to provide the following document. You can upload it securely as a PDF with the button below."],
                details: $details,
                actionLabel: 'Upload the document securely',
                actionUrl: $url,
                notes: $notes,
                quote: $request->instructions !== '' ? $request->instructions : null,
                quoteLabel: 'Instructions',
            ),
        };
    }

    public function signing(EmailCommunicationType $type, SigningRequest $request, string $documentTitle, #[\SensitiveParameter] string $url, DateTimeInterface $expiresAt): MailContent
    {
        $firm = $this->firm();
        $notes = [
            'This secure signing link is personal to you and expires on '.$this->when($expiresAt).'. No account or password is needed.',
            'Earlier signing links we sent you for this document stop working once this e-mail is sent.',
            'This is an electronic signature recorded by the firm\'s workspace. If you did not expect this e-mail, do not use the link and contact us.',
        ];
        $details = [
            ['label' => 'Document', 'value' => $documentTitle],
            ['label' => 'Signer', 'value' => $request->signer_name],
        ];
        $reminder = $type === EmailCommunicationType::SigningReminder;

        return new MailContent(
            subject: $reminder ? "Reminder: signature requested — {$firm}" : "Signature requested — {$firm}",
            preheader: $reminder ? 'A document is still waiting for your signature.' : 'Please review and sign a document using your secure link.',
            headline: $reminder ? 'A document is still waiting for your signature' : 'Please review and sign a document',
            greeting: "Dear {$request->signer_name},",
            paragraphs: [
                $reminder
                    ? "{$firm} is still waiting for your electronic signature on the document below."
                    : "{$firm} asks you to review and electronically sign the document below.",
                'Open the secure page to read the PDF, then sign it by drawing or typing your signature. It takes about two minutes.',
            ],
            details: $details,
            actionLabel: 'Review and sign the document',
            actionUrl: $url,
            notes: $notes,
            quote: ! $reminder && filled($request->message) ? $request->message : null,
            quoteLabel: 'Message from the firm',
        );
    }

    public function test(string $recipientName): MailContent
    {
        $firm = $this->firm();

        return new MailContent(
            subject: "Test e-mail — {$firm}",
            preheader: 'Your Lexora Workspace e-mail settings work.',
            headline: 'E-mail sending works',
            greeting: "Hello {$recipientName},",
            paragraphs: ['This test message was sent from Lexora Workspace to your own address to confirm that the configured mail server accepts messages.'],
            notes: ['No action is needed. This message contains no client data and no secure link.'],
        );
    }

    /** The subject of a type (identical to the subject of the composed message). */
    public function subject(EmailCommunicationType $type): string
    {
        $firm = $this->firm();

        return match ($type) {
            EmailCommunicationType::DocumentRequest => "Document request — {$firm}",
            EmailCommunicationType::DocumentCorrection => "Correction requested — {$firm}",
            EmailCommunicationType::DocumentRequestReminder => "Reminder: document request — {$firm}",
            EmailCommunicationType::SigningInvitation => "Signature requested — {$firm}",
            EmailCommunicationType::SigningReminder => "Reminder: signature requested — {$firm}",
            EmailCommunicationType::Test => "Test e-mail — {$firm}",
        };
    }

    private function firm(): string
    {
        return (string) config('lexora.firm_name');
    }

    /** Firm-time-zone date and time, e.g. "24 Nov 2026, 14:30 (Europe/Budapest)". */
    public function when(DateTimeInterface $at): string
    {
        $tz = LegalDate::timezone();

        return Carbon::instance($at)->setTimezone($tz)->format('j M Y, H:i')." ({$tz})";
    }

    private function dueLine(DocumentRequest $request): ?string
    {
        if ($request->due_at === null) {
            return null;
        }
        $date = Carbon::instance($request->due_at)->setTimezone(LegalDate::timezone())->format('j M Y');

        return $this->isOverdue($request) ? "{$date} (overdue)" : $date;
    }

    private function isOverdue(DocumentRequest $request): bool
    {
        return $request->due_at !== null && $request->due_at->isPast();
    }
}
