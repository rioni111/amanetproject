<?php

namespace App\Http\Resources;

class DocumentCollection extends PaginatedCollection
{
    public $collects = DocumentResource::class;
}
