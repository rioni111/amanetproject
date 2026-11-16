<?php

namespace App\Services;

use App\Enums\ActivityEventType;
use App\Enums\ClientType;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Client records. Client events are recorded with matter_id = null (they are not attached to an
 * invented matter); contact details are not copied into history, only which fields changed.
 *
 * Retention: a client can be deleted only while it has no matters (also enforced by the
 * matters.client_id ON DELETE RESTRICT foreign key). Clients with legal matters are kept.
 */
class ClientService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data validated input */
    public function create(array $data, User $actor): Client
    {
        return DB::transaction(function () use ($data, $actor) {
            $client = new Client;
            $client->forceFill($this->attributes($data))->save();

            $this->activity->record(null, ActivityEventType::ClientCreated, "Client created: {$client->name}.", $actor, $client,
                ['client_type' => $client->client_type->value]);

            return $client;
        });
    }

    /** @param array<string, mixed> $data validated input */
    public function update(Client $client, array $data, User $actor): Client
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $client = Client::query()->lockForUpdate()->findOrFail($client->getKey());
            $client->forceFill($this->attributes($data, partial: true));
            if (! $client->isDirty()) {
                return $client;
            }
            $changed = array_keys($client->getDirty());
            $client->save();

            $this->activity->record(null, ActivityEventType::ClientUpdated, "Client updated: {$client->name}.", $actor, $client,
                ['changed_fields' => $changed]);

            return $client;
        });
    }

    public function delete(Client $client, User $actor): void
    {
        DB::transaction(function () use ($client, $actor) {
            $client = Client::query()->lockForUpdate()->findOrFail($client->getKey());
            if ($client->matters()->exists()) {
                throw new ConflictHttpException('This client has legal matters and cannot be deleted. Client records with matters are retained.');
            }
            $name = $client->name;
            $id = $client->id;
            $client->delete();

            $this->activity->record(null, ActivityEventType::ClientDeleted, "Client deleted: {$name}.", $actor, null, ['client_id' => $id]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $partial = false): array
    {
        $attributes = [];
        foreach (['name', 'email', 'phone', 'address', 'reference_number'] as $field) {
            if (! $partial || array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] ?? null;
            }
        }
        if (! $partial || array_key_exists('client_type', $data)) {
            $attributes['client_type'] = ClientType::from($data['client_type']);
        }
        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower(trim($attributes['email']));
        }

        return $attributes;
    }
}
