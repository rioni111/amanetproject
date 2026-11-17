<?php

namespace App\Http\Requests\Signing;

use App\Support\SigningConsent;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shape of a signature submission (administrator and public). Structural limits only: the detailed stroke,
 * name and placement validation happens in SignatureAppearance / SignaturePlacement, which know the page sizes.
 */
trait SignaturePayloadRules
{
    public const MAX_PAYLOAD_BYTES = 262144; // 256 KB of JSON is far above any genuine signature

    /** @return array<string, mixed> */
    protected function signatureRules(): array
    {
        return [
            'method' => ['required', 'in:drawn,typed'],
            'strokes' => ['nullable', 'required_if:method,drawn', 'prohibited_if:method,typed', 'array', 'max:60'],
            'typed_name' => ['nullable', 'required_if:method,typed', 'prohibited_if:method,drawn', 'string', 'max:80'],
            'page' => ['required', 'integer', 'min:1', 'max:5000'],
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'width' => ['required', 'numeric', 'between:0.01,1'],
            'consent' => ['accepted'],
            'consent_version' => ['required', 'string', 'in:'.SigningConsent::VERSION],
            // Never accepted from the signer: the server determines identity, document, version and time.
            ...array_fill_keys([
                'signer_name', 'signer_email', 'signer_type', 'signer_user_id', 'signed_at', 'signing_request_id', 'document_id',
                'source_document_version_id', 'signed_document_version_id', 'status', 'ip_address', 'consent_text', 'sha256',
            ], ['prohibited']),
        ];
    }

    /** @return array<string, string> */
    protected function signatureMessages(): array
    {
        return [
            'method.required' => 'Choose how you want to sign (draw or type).',
            'strokes.required_if' => 'Draw your signature.',
            'typed_name.required_if' => 'Type your full name as your signature.',
            'page.required' => 'Choose where the signature goes.',
            'consent.accepted' => 'Confirm the signing statement to sign.',
            'consent_version.in' => 'The signing statement has changed. Reload the page and review it again.',
            '*.prohibited' => 'This field is determined by the system and cannot be sent.',
        ];
    }

    protected function assertPayloadSize(): void
    {
        if (strlen((string) $this->getContent()) > self::MAX_PAYLOAD_BYTES) {
            throw new HttpException(413, 'The signature is too large. Clear it and sign again.');
        }
    }

    /** @return array<string, mixed> the service input */
    public function signatureInput(): array
    {
        return [
            'method' => $this->validated('method'),
            'strokes' => $this->validated('strokes'),
            'typed_name' => $this->validated('typed_name'),
            'page' => (int) $this->validated('page'),
            'x' => (float) $this->validated('x'),
            'y' => (float) $this->validated('y'),
            'width' => (float) $this->validated('width'),
            'consent' => $this->boolean('consent'),
            'consent_version' => $this->validated('consent_version'),
            'confirmed_name' => $this->validated('confirmed_name'),
        ];
    }
}
