<?php

namespace App\Models;

use App\Enums\ClientType;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'client_type',
        'email',
        'phone',
        'address',
        'reference_number',
    ];

    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
        ];
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
