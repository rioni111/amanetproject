<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class DocumentLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('document')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['nullable', 'integer', 'min:1']];
    }
}
