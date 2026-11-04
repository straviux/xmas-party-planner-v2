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
}
