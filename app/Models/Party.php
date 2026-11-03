<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $fillable = [
        'name',
        'budget',
        'date',
        'invite_code',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class);
    }
}
