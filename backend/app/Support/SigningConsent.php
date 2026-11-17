<?php

namespace App\Support;

/**
 * The versioned intent-to-sign statement. The exact text and its version are stored with every signature
 * record, so later wording changes never alter what a signer agreed to. Changing the text requires a new
 * VERSION. The statement records an application-level electronic signing action; it does not claim a
 * certificate-based (advanced/qualified) signature or independent identity verification.
 */
final class SigningConsent
{
    public const VERSION = '2026-11-v1';

    public const TEXT = 'I confirm that I am the person identified as the signer of this document and that I intend to '
        .'electronically sign it. I understand that my signature, the name I confirm, the date and time of signing and '
        .'technical details of this signing action will be recorded with the signed document.';
}
