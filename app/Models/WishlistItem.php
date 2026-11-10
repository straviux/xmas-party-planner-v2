<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistItem extends Model
{
    protected $fillable = [
        'images',
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

    protected function casts(): array
    {
        return [
            'images' => 'array',
        ];
    }

    /**
     * The wish's photos as a list of WebP data URLs (up to three).
     * `images` is the store; the legacy single `image` column mirrors
     * the first entry, and rows from before the list existed read
     * through it.
     *
     * @return list<string>
     */
    public function photos(): array
    {
        $photos = array_values(array_filter(
            is_array($this->images) ? $this->images : [],
            fn ($photo) => is_string($photo) && $photo !== '',
        ));
        if ($photos !== []) {
            return $photos;
        }

        return is_string($this->image) && $this->image !== '' ? [$this->image] : [];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
