<?php

namespace App\Http\Requests\Signing;

use Illuminate\Foundation\Http\FormRequest;

/** Body of link / share / cancel actions on a signing request. */
class SigningActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('lexora.secure_links.max_expiry_days', 30)],
            ...array_fill_keys(['status', 'sent_at', 'delivery_channel', 'delivery_status', 'signed_at', 'expires_at', 'max_uses', 'token_hash'], ['prohibited']),
        ];
    }
}
