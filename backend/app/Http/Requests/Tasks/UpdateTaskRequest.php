<?php

namespace App\Http\Requests\Tasks;

class UpdateTaskRequest extends TaskRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
