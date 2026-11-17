<?php

namespace App\Http\Requests\DocumentRequests;

use App\Models\DocumentRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequestRequest extends FormRequest
{
    use DocumentRequestRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [DocumentRequest::class, $this->route('matter')]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->detailRules(false),
            'issue_link' => ['sometimes', 'boolean'],
            'link_expires_in_days' => $this->expiryRules(),
            ...$this->prohibitedServerFields(),
        ];
    }
}
