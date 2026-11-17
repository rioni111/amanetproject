<?php

namespace App\Http\Requests\Signing;

use App\Enums\SignerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSigningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $client = $this->input('signer_type') === SignerType::Client->value;

        return [
            'document_id' => ['required', 'integer', 'min:1'],
            'document_version_id' => ['required', 'integer', 'min:1'],
            'signer_type' => ['required', Rule::enum(SignerType::class)],
            // Exactly ONE signer: name/e-mail describe the client signer; an administrator signer is always the
            // authenticated user, so these fields are refused for admin requests.
            'signer_name' => [$client ? 'required' : 'prohibited', 'nullable', 'string', 'min:2', 'max:200', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .,'&-]*$/u"],
            'signer_email' => [$client ? 'nullable' : 'prohibited', 'nullable', 'email:rfc', 'max:255'],
            'message' => [$client ? 'nullable' : 'prohibited', 'nullable', 'string', 'max:2000'],
            'issue_link' => ['sometimes', 'boolean'],
            'link_expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('lexora.secure_links.max_expiry_days', 30)],
            'signers' => ['prohibited'], // one signer per PDF
            ...array_fill_keys([
                'matter_id', 'client_id', 'signer_user_id', 'status', 'sent_at', 'viewed_at', 'started_at', 'signed_at',
                'signed_document_version_id', 'delivery_channel', 'delivery_status', 'created_by', 'token', 'token_hash', 'uuid', 'id',
            ], ['prohibited']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'signer_name.required' => "Enter the client signer's full name.",
            'signer_name.regex' => 'Use letters, spaces and common name punctuation only.',
            'signers.prohibited' => 'Only one signer can be designated per PDF.',
            '*.prohibited' => 'This field is determined by the system and cannot be set.',
        ];
    }
}
