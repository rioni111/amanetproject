<?php

namespace App\Enums;

/** Lifecycle of an e-mailed token's reserved hash (see the email_link_reservations migration). */
enum EmailLinkReservationStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Registered = 'registered';
    case Activated = 'activated';
    case Discarded = 'discarded';
    case Superseded = 'superseded';
}
