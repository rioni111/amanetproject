<?php

namespace App\Http\Requests\DocumentRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of the lifecycle endpoints (links, review decisions, cancel). Which fields are required depends
 * on the route: review decisions must name the submission they decide on, a correction needs a reason.
 */
class DocumentRequestActionRequest extends FormRequest
{
    use DocumentRequestRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('documentRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $decision = $this->routeIs('matters.document-requests.start-review', 'matters.document-requests.approve', 'matters.document-requests.request-correction');
        $correction = $this->routeIs('matters.document-requests.request-correction');

        return [
            'lock_version' => [$decision ? 'required' : 'nullable', 'integer', 'min:1'],
            'submission_id' => [$decision ? 'required' : 'prohibited', 'integer', 'min:1'],
            'reason' => [$correction ? 'required' : 'prohibited', 'string', 'min:10', 'max:2000', $this->notBlank()],
            'expires_in_days' => $this->expiryRules(),
            // Phase 6: e-mail the correction request to the client instead of revealing a link to copy.
            'send_email' => [$correction ? 'nullable' : 'prohibited', 'boolean'],
            ...$this->prohibitedServerFields(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Explain what the client needs to correct.',
            'reason.min' => 'Give the client a clear reason (at least 10 characters).',
            'reason.max' => 'Keep the reason under 2,000 characters.',
            'submission_id.required' => 'Reload the request and choose the submission to decide on.',
            '*.prohibited' => 'This field is managed by the system and cannot be set.',
        ];
    }
}
