<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KringleTheme extends Model
{
    protected $fillable = [
        'party_id',
        'week_start',
        'theme',
    ];

    // week_start is deliberately NOT cast: it is stored and compared
    // as an exact 'Y-m-d' string on every database. (A 'date' cast
    // stores a full datetime string on SQLite, which silently breaks
    // the equality lookups the lazy generation relies on.)

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
