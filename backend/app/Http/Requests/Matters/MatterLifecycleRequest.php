<?php

namespace App\Http\Requests\Matters;

use Illuminate\Foundation\Http\FormRequest;

/** Archive / restore. The ability ("archive" or "restore") is the route's action name. */
class MatterLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->route()?->getActionMethod() === 'restore' ? 'restore' : 'archive';

        return $this->user()?->can($ability, $this->route('matter')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['nullable', 'integer', 'min:1']];
    }
}
