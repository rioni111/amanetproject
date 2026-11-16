<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentVersionRequest extends FormRequest
{
    use PdfUploadRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => $this->pdfFileRules(),
            'lock_version' => ['nullable', 'integer', 'min:1'],
            ...$this->prohibitedServerFields(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pdfFileMessages();
    }
}
