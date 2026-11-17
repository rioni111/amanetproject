<?php

namespace App\Http\Resources;

class DocumentRequestCollection extends PaginatedCollection
{
    public $collects = DocumentRequestResource::class;
}
