<?php

namespace App\Http\Requests\DocumentRequests;

use App\Enums\DocumentRequestStatus;
use App\Models\DocumentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Request lists (workspace-wide and per matter): allow-listed filters and sorts only. */
class IndexDocumentRequestsRequest extends FormRequest
{
    public const SORTABLE = ['updated_at', 'created_at', 'due_at', 'title', 'last_submitted_at', 'matter_number'];

    /** Groups in addition to the individual statuses. */
    public const STATUS_GROUPS = ['open', 'awaiting_client', 'awaiting_review'];

    public function authorize(): bool
    {
        $matter = $this->route('matter');

        return $matter
            ? ($this->user()?->can('view', $matter) ?? false)
            : ($this->user()?->can('viewAny', DocumentRequest::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...DocumentRequestStatus::values(), ...self::STATUS_GROUPS])],
            'overdue' => ['nullable', 'boolean'],
            'matter_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('lexora.pagination.max_per_page')],
        ];
    }
}
