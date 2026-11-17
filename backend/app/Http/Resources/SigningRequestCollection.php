<?php

namespace App\Http\Resources;

class SigningRequestCollection extends PaginatedCollection
{
    public $collects = SigningRequestResource::class;
}
