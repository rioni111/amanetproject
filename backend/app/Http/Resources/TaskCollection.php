<?php

namespace App\Http\Resources;

class TaskCollection extends PaginatedCollection
{
    public $collects = TaskResource::class;
}
