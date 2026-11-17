<?php

namespace App\Http\Requests\Matters;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMattersRequest extends FormRequest
{
    public const SORTABLE = ['updated_at', 'created_at', 'opened_at', 'due_at', 'matter_number', 'title', 'priority'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Matter::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // 'active' = open, in progress, on hold; 'current' (Phase 7) = everything except archived.
            'status' => ['nullable', Rule::in([...MatterStatus::values(), 'active', 'current'])],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'matter_type' => ['nullable', 'string', 'max:100'],
            'deadline' => ['nullable', Rule::in(['overdue', 'upcoming', 'none'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('lexora.pagination.max_per_page')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
