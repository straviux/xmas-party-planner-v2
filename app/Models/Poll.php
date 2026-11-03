<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    protected $fillable = [
        'party_id',
        'question',
        'open',
    ];

    protected function casts(): array
    {
        return [
            'open' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }
}
