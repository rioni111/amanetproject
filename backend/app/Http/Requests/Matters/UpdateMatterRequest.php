<?php

namespace App\Http\Requests\Matters;

class UpdateMatterRequest extends MatterRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('matter')) ?? false;
    }

    protected function isUpdate(): bool
    {
        return true;
    }
}
