<?php

namespace App\Http\Resources;

class MatterNoteCollection extends PaginatedCollection
{
    public $collects = MatterNoteResource::class;
}
