<?php

namespace App\Enums;

/** Why a link stopped working before it was used or expired. */
enum LinkRevocationReason: string
{
    use HasValues;

    case Revoked = 'revoked';                    // an administrator revoked it
    case Replaced = 'replaced';                  // a replacement link was issued
    case RequestApproved = 'request_approved';   // nothing more to upload
    case RequestCancelled = 'request_cancelled';
    case MatterArchived = 'matter_archived';

    /** Lifecycle closures may be explained to the link holder; manual revocations look like an unknown link. */
    public function isLifecycleClosure(): bool
    {
        return in_array($this, [self::RequestApproved, self::RequestCancelled, self::MatterArchived], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Revoked => 'Revoked',
            self::Replaced => 'Replaced by a new link',
            self::RequestApproved => 'Closed: request approved',
            self::RequestCancelled => 'Closed: request cancelled',
            self::MatterArchived => 'Closed: matter archived',
        };
    }
}
