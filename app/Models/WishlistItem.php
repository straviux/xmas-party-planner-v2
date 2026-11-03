<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistItem extends Model
{
    protected $fillable = [
        'guest_id',
        'text',
        'link',
        'notes',
        'kind',
        'image',
        'reserved_by_id',
    ];

    protected $hidden = [
        'reserved_by_id',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Server-side only: the reserver's identity is never exposed; responses
     * carry `reserved` / `reserved_by_me` booleans instead (spec).
     */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'reserved_by_id');
    }
}
