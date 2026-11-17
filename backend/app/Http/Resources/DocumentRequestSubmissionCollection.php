<?php

namespace App\Http\Resources;

class DocumentRequestSubmissionCollection extends PaginatedCollection
{
    public $collects = DocumentRequestSubmissionResource::class;
}
