<?php

namespace App\Http\Requests\Clients;

class UpdateClientRequest extends ClientRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('client')) ?? false;
    }

    protected function isUpdate(): bool
    {
        return true;
    }
}
