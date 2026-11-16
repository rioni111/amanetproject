<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentCategory;
use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Library and matter document lists: allow-listed filters and sorts only. */
class IndexDocumentsRequest extends FormRequest
{
    public const SORTABLE = ['updated_at', 'created_at', 'title', 'matter_number', 'category'];

    public function authorize(): bool
    {
        $matter = $this->route('matter');

        return $matter
            ? ($this->user()?->can('view', $matter) ?? false)
            : ($this->user()?->can('viewAny', Document::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'status' => ['nullable', Rule::in([...DocumentStatus::values(), 'all'])],
            'matter_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'uploaded_from' => ['nullable', 'date_format:Y-m-d'],
            'uploaded_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:uploaded_from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('lexora.pagination.max_per_page')],
        ];
    }
}
