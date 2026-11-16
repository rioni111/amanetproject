<?php

namespace App\Http\Requests\Matters;

use App\Models\Matter;

class StoreMatterRequest extends MatterRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Matter::class) ?? false;
    }

    protected function isUpdate(): bool
    {
        return false;
    }
}
