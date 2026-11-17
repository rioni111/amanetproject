<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentCategory;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    use PdfUploadRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [Document::class, $this->route('matter')]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => $this->pdfFileRules(),
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            ...$this->prohibitedServerFields(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...$this->pdfFileMessages(), 'category.required' => 'Choose a category.'];
    }
}
