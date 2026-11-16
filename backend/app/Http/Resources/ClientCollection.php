<?php

namespace App\Http\Resources;

class ClientCollection extends PaginatedCollection
{
    public $collects = ClientResource::class;
}
