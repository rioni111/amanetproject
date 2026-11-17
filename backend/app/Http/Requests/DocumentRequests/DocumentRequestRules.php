<?php

namespace App\Http\Requests\DocumentRequests;

use App\Enums\DocumentCategory;
use App\Support\LegalDate;
use Closure;
use Illuminate\Validation\Rule;

/** Shared field rules for creating and editing client document requests. */
trait DocumentRequestRules
{
    /** @return array<string, mixed> */
    protected function detailRules(bool $partial, ?string $currentDueDate = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255', $this->notBlank()],
            'instructions' => [$required, 'string', 'max:5000', $this->notBlank()],
            'requested_category' => [$required, Rule::enum(DocumentCategory::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) use ($currentDueDate) {
                // A new due date cannot be in the past (firm time zone); an unchanged one may be.
                if (is_string($value) && $value !== $currentDueDate && $value < LegalDate::today()) {
                    $fail('The due date cannot be in the past.');
                }
            }],
        ];
    }

    protected function notBlank(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && trim($value) === '') {
                $fail('This field cannot be blank.');
            }
        };
    }

    /** @return array<string, mixed> */
    protected function expiryRules(): array
    {
        return ['nullable', 'integer', 'min:1', 'max:'.(int) config('lexora.secure_links.max_expiry_days', 30)];
    }

    /** Lifecycle, ownership and link fields are owned by the server and rejected, never ignored. */
    protected function prohibitedServerFields(): array
    {
        return array_fill_keys([
            'id', 'uuid', 'matter_id', 'client_id', 'document_id', 'document_version_id', 'approved_version_id',
            'origin', 'uploaded_by', 'created_by', 'approved_at', 'reviewed_by', 'review_started_by', 'cancelled_at',
            'cancelled_by', 'status', 'submissions_count', 'last_submitted_at', 'token', 'token_hash', 'successful_uses',
            'max_uses', 'consumed_at', 'revoked_at', 'expires_at', 'purpose',
        ], ['prohibited']);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Enter a title for the request.',
            'instructions.required' => 'Tell the client what to upload.',
            'requested_category.required' => 'Choose the document category.',
            'due_date.date_format' => 'Enter the due date as YYYY-MM-DD.',
            '*.prohibited' => 'This field is managed by the system and cannot be set.',
        ];
    }
}
