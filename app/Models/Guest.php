<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    protected $fillable = [
        'party_id',
        'display',
        'nickname',
        'secret_name',
        'aliases',
        'pin_hash',
        'recipient_id',
        'claimed',
        'claimed_at',
        'revealed',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'claimed' => 'boolean',
            'revealed' => 'boolean',
            'claimed_at' => 'datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The Secret Santa recipient. Server-side only: recipient_id must never
     * appear in any API response except the single reveal response (Slice 2).
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'recipient_id');
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
