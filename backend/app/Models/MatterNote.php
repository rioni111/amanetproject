<?php

namespace App\Models;

use App\Models\Concerns\HasLockVersion;
use Database\Factories\MatterNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal administrator note. Must never be serialised into client-facing payloads.
 */
class MatterNote extends Model
{
    /** @use HasFactory<MatterNoteFactory> */
    use HasFactory, HasLockVersion;

    protected $fillable = [
        'matter_id',
        'author_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
        ];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
