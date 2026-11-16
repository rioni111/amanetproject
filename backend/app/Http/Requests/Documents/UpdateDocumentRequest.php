<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Metadata only. Files are changed by uploading a new version; status by archive/restore. */
class UpdateDocumentRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::enum(DocumentCategory::class)],
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'file' => ['prohibited'],
            ...$this->prohibitedServerFields(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file.prohibited' => 'Upload a new version to change the file.'];
    }
}
