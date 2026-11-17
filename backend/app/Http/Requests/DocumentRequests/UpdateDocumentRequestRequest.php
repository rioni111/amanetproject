<?php

namespace App\Http\Requests\DocumentRequests;

use App\Models\DocumentRequest;
use App\Support\LegalDate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequestRequest extends FormRequest
{
    use DocumentRequestRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('documentRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var DocumentRequest $request */
        $request = $this->route('documentRequest');

        return [
            ...$this->detailRules(true, LegalDate::toDate($request->due_at)),
            'lock_version' => ['required', 'integer', 'min:1'],
            ...$this->prohibitedServerFields(),
        ];
    }
}
