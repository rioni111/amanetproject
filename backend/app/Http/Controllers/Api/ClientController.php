<?php

namespace App\Http\Controllers\Api;

use App\Enums\MatterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\IndexClientsRequest;
use App\Http\Resources\ClientCollection;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 1: read-only listing. Create/update arrive in Phase 2.
 */
class ClientController extends Controller
{
    public function index(IndexClientsRequest $request): ClientCollection
    {
        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $query = Client::query()
            ->withCount([
                'matters',
                'matters as active_matters_count' => fn (Builder $q) => $q->whereIn('status', MatterStatus::activeValues()),
            ])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%'; // escape LIKE wildcards
                $q->where(function (Builder $q) use ($term) {
                    $q->whereLike('name', $term)
                        ->orWhereLike('email', $term)
                        ->orWhereLike('reference_number', $term);
                });
            })
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('client_type', $type))
            ->orderBy($sort, $direction)
            ->orderBy('id');

        $perPage = (int) ($filters['per_page'] ?? config('lexora.pagination.default_per_page'));

        return new ClientCollection($query->paginate($perPage)->withQueryString());
    }
}
