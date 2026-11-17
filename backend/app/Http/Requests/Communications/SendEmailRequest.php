<?php

namespace App\Http\Requests\Communications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of "send e-mail" for a document request or a signing request. The recipient, subject, sender, status
 * and every other delivery value are decided by the server: sending them is rejected, never ignored, so an
 * API caller cannot redirect an invitation to an arbitrary address.
 */
class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('documentRequest') ?? $this->route('matter');

        return $this->user()?->can('update', $target) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // One per dialog opening: a repeated click / retried request returns the first result, no second e-mail.
            'idempotency_key' => ['nullable', 'uuid'],
            'link_expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('lexora.secure_links.max_expiry_days', 30)],
            ...array_fill_keys([
                'to', 'cc', 'bcc', 'recipient', 'recipient_email', 'recipient_name', 'email', 'from', 'reply_to', 'subject', 'body', 'html',
                'status', 'delivery_status', 'accepted_at', 'attempt_count', 'matter_id', 'client_id', 'document_request_id',
                'signing_request_id', 'token', 'token_hash', 'url', 'link', 'communication_type', 'initiated_by', 'reminder_slot', 'created_by',
            ], ['prohibited']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'This value is decided by the server and cannot be sent. E-mails always go to the request\'s client or designated signer.',
            'idempotency_key.uuid' => 'Reload the page and try again.',
        ];
    }
}
