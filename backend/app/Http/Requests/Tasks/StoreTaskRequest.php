<?php

namespace App\Http\Requests\Tasks;

class StoreTaskRequest extends TaskRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }
}
