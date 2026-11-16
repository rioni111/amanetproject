<?php

namespace App\Http\Resources;

class ActivityEventCollection extends PaginatedCollection
{
    public $collects = ActivityEventResource::class;
}
