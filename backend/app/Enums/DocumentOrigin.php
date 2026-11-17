<?php

namespace App\Enums;

/** Who supplied a file. Always assigned by the server. Phase 3 creates admin uploads only; client
 *  uploads arrive through secure request links in Phase 4. */
enum DocumentOrigin: string
{
    use HasValues;

    case Admin = 'admin';
    case Client = 'client';
}
