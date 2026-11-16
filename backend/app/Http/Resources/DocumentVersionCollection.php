<?php

namespace App\Http\Resources;

class DocumentVersionCollection extends PaginatedCollection
{
    public $collects = DocumentVersionResource::class;
}
