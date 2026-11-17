<?php

namespace App\Services\Communications;

use App\Enums\EmailFailureCategory;

/** Result of one SMTP submission: accepted (with the Message-ID) or failed (with a safe category). */
final readonly class SendOutcome
{
    private function __construct(
        public bool $accepted,
        public ?string $messageId,
        public ?EmailFailureCategory $failure,
    ) {}

    public static function accepted(?string $messageId): self
    {
        return new self(true, $messageId, null);
    }

    public static function failed(EmailFailureCategory $category): self
    {
        return new self(false, null, $category);
    }
}
