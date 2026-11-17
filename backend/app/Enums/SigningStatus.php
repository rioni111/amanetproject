<?php

namespace App\Enums;

/**
 * Lifecycle of a signing request (one signer, one source PDF version).
 *
 *   ready ──mark shared──▶ sent ──document opened──▶ viewed ──signer starts──▶ started ──signature committed──▶ signed
 *     │  (a client may also open the link before the administrator marks it shared: ready → viewed)
 *     └──────────────────── cancel / matter or document archived / newer version ──────────────▶ cancelled
 *
 * - ready:  the request exists; no invitation has been confirmed as shared (a generated link is NOT a sent invitation).
 * - sent:   the administrator confirmed sharing the invitation (Phase 5: manual channel). Phase 6 email delivery will
 *           record its own delivery outcome in delivery_status; it never redefines viewed/started/signed.
 * - viewed: the signer's browser rendered the designated PDF through a valid link (explicit POST, not a page prefetch).
 * - started: the signer explicitly began the signing step.
 * - signed: the signed PDF, its immutable version, the signature record and the audit events were committed.
 * Administrator signing goes ready → signed (no invitation, view or start step).
 * Link expiry and revocation are properties of the link, not request statuses.
 */
enum SigningStatus: string
{
    use HasValues;

    case Ready = 'ready';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Started = 'started';
    case Signed = 'signed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready to send',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::Started => 'Started',
            self::Signed => 'Signed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Ready, self::Sent, self::Viewed, self::Started], true);
    }

    /** Position on the progress path; progress never moves backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::Ready => 0,
            self::Sent => 1,
            self::Viewed => 2,
            self::Started => 3,
            self::Signed => 4,
            self::Cancelled => 5,
        };
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Ready->value, self::Sent->value, self::Viewed->value, self::Started->value];
    }
}
