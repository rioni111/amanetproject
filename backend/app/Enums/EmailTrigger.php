<?php

namespace App\Enums;

/** Who started a communication: an administrator (manual) or the reminder scheduler (automatic). */
enum EmailTrigger: string
{
    use HasValues;

    case Manual = 'manual';
    case Automatic = 'automatic';
}
