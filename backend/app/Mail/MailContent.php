<?php

namespace App\Mail;

/**
 * The text of one client e-mail, independent of its rendering. Every value is plain text and is escaped by
 * the templates (Blade {{ }}); nothing here is ever rendered as HTML. The action URL is the only place the
 * secure token appears, and this object never prints it in dumps.
 */
final readonly class MailContent
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{label: string, value: string}>  $details
     * @param  list<string>  $notes  small print under the button (expiry, "no account needed", …)
     */
    public function __construct(
        public string $subject,
        public string $preheader,
        public string $headline,
        public string $greeting,
        public array $paragraphs,
        public array $details = [],
        public ?string $actionLabel = null,
        #[\SensitiveParameter] public ?string $actionUrl = null,
        public array $notes = [],
        public ?string $quote = null,
        public ?string $quoteLabel = null,
    ) {}

    public function __debugInfo(): array
    {
        return ['subject' => $this->subject, 'actionUrl' => $this->actionUrl === null ? null : '[redacted]'];
    }
}
