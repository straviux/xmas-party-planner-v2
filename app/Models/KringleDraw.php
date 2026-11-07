<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Monito Monita roulette draw: who a guest is buying for in one
 * week's themed exchange. Server-side only: a draw is ever returned
 * solely as the spinning guest's own recipient codename for that
 * week (see KringleService); the week's pairings are never listed.
 */
class KringleDraw extends Model
{
    protected $fillable = [
        'party_id',
        'week_start',
        'giver_id',
        'recipient_id',
    ];

    // week_start is an exact 'Y-m-d' string, never cast (see
    // KringleTheme for why).

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'giver_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'recipient_id');
    }
}
