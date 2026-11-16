<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consistent pagination envelope for every list endpoint:
 * { data: [...], meta: { current_page, last_page, per_page, total, from, to }, links: { first, last, prev, next } }
 */
abstract class PaginatedCollection extends ResourceCollection
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'last_page' => $paginated['last_page'],
                'per_page' => (int) $paginated['per_page'],
                'total' => $paginated['total'],
                'from' => $paginated['from'],
                'to' => $paginated['to'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'],
                'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
