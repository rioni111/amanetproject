<?php

namespace App\Http\Requests\Tasks;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tasks always belong to the matter in the URL; matter_id, created_by and completed_at are
 * server-managed and rejected if supplied.
 */
abstract class TaskRequest extends FormRequest
{
    abstract protected function isUpdate(): bool;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = $this->isUpdate() ? 'sometimes' : 'nullable';

        return [
            'title' => [$this->isUpdate() ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [$optional, Rule::enum(TaskStatus::class)],
            'priority' => [$optional, Rule::enum(Priority::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:2099-12-31'],
            'lock_version' => $this->isUpdate() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'matter_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'due_at' => ['prohibited'],
            'id' => ['prohibited'],
        ];
    }
}
