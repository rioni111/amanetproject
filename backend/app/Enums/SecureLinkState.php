<?php

namespace App\Enums;

/** Derived (never stored) state of a link, as shown to administrators. */
enum SecureLinkState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Used = 'used';
}
