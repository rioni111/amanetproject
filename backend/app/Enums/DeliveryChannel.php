<?php

namespace App\Enums;

/** How a signing invitation reached the signer. Phase 5: manual only; Phase 6 adds email delivery. */
enum DeliveryChannel: string
{
    use HasValues;

    case Manual = 'manual';
    case Email = 'email'; // reserved for Phase 6
}
