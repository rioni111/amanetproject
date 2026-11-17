<?php

namespace App\Http\Requests\Signing;

use Illuminate\Foundation\Http\FormRequest;

/** A client signs through a secure link (authorised by the link, resolved before this validation runs). */
class PublicSignatureRequest extends FormRequest
{
    use SignaturePayloadRules;

    public function authorize(): bool
    {
        $this->assertPayloadSize();

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->signatureRules(),
            'confirmed_name' => ['required', 'string', 'min:2', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...$this->signatureMessages(), 'confirmed_name.required' => 'Confirm your full name.'];
    }
}
