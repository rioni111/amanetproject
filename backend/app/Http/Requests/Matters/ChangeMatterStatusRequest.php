<?php

namespace App\Http\Requests\Matters;

use App\Enums\MatterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeMatterStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Archiving has its own endpoint (confirmation + restore semantics).
            'status' => ['required', Rule::enum(MatterStatus::class)->except([MatterStatus::Archived])],
            'lock_version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['status.enum' => 'Choose open, in progress, on hold or closed. Use the archive action to archive a matter.'];
    }
}
