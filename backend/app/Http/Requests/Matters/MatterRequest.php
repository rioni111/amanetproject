<?php

namespace App\Http\Requests\Matters;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and editing matters. Dates are calendar dates (YYYY-MM-DD,
 * see App\Support\LegalDate). The matter number, lifecycle timestamps and audit fields are
 * server-managed and rejected if supplied; status changes go through the status endpoint.
 */
abstract class MatterRequest extends FormRequest
{
    abstract protected function isUpdate(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        $date = ['nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:2099-12-31'];

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'client_id' => [$required, 'integer', 'min:1', Rule::exists('clients', 'id')],
            'matter_type' => ['nullable', 'string', 'max:100'],
            'priority' => [$this->isUpdate() ? 'sometimes' : 'nullable', Rule::enum(Priority::class)],
            'opened_date' => $date,
            'due_date' => [...$date, ...($this->filled('opened_date') ? ['after_or_equal:opened_date'] : [])],
            'status' => $this->isUpdate()
                ? ['prohibited']
                : ['nullable', Rule::in(MatterStatus::initialValues())],
            'lock_version' => $this->isUpdate() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'matter_number' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'archived_from_status' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'due_at' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.exists' => 'Choose an existing client.',
            'due_date.after_or_equal' => 'The deadline cannot be before the opening date.',
            'status.in' => 'A new matter must start as open, in progress or on hold.',
            'status.prohibited' => 'Change the status with the status action, not the edit form.',
        ];
    }
}
