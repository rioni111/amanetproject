<?php

namespace App\Http\Resources;

class MatterCollection extends PaginatedCollection
{
    public $collects = MatterResource::class;
}
