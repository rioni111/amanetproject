<?php

namespace App\Http\Requests\Signing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An administrator signs: either an existing administrator signing request (…/admin-sign) or the current
 * version of a document directly (…/documents/{document}/sign, with document_version_id). The signer is
 * always the authenticated administrator; no identity field is accepted.
 */
class AdminSignatureRequest extends FormRequest
{
    use SignaturePayloadRules;

    public function authorize(): bool
    {
        $this->assertPayloadSize();

        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->signatureRules(),
            'document_version_id' => [$this->route('document') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'confirmed_name' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->signatureMessages();
    }
}
