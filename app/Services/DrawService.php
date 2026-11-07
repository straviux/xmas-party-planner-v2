<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;

/**
 * Secret Santa draw: the roulette.
 *
 * There is no pre-draw. A guest's recipient is assigned live, at the
 * moment of their first reveal (their "spin"), by
 * assignForGuest(): a random pick from the guests who are not yet
 * anyone's recipient, never the spinner themselves. Across all spins
 * this still yields a derangement of the whole party: every guest is
 * picked exactly once and nobody picks themselves.
 *
 * Two invariants make that hold:
 *  - Spins are serialised (the caller wraps the reveal in a
 *    transaction and this method locks the party's guest rows), and
 *    the counts always match: guests without a recipient (givers
 *    left) equals guests not yet picked (targets left).
 *  - The endgame guard: when exactly two givers are left and the
 *    other giver is still an unpicked target, the spinner MUST take
 *    that other giver. Taking anyone else would leave the last giver
 *    facing only themselves, which no later rule could repair (every
 *    earlier giver has already revealed by then, so swapping a
 *    revealed result is never an option). With that single forced
 *    pick, no sequence of spins can strand the last guest; this is
 *    the only infeasible state a pick can create, by Hall's theorem
 *    for self-forbidden assignments.
 *
 * A guest who already has a recipient (assigned by an earlier spin,
 * or pre-drawn under the retired full-draw system) simply keeps
 * them: assignForGuest() returns the existing recipient untouched.
 *
 * With fewer than two guests no draw is possible and recipient_id
 * stays null (A8). Once any name is claimed the guest list is
 * frozen: guest changes refuse with the 'guests_locked' error code
 * (see ApiController's lockedResponse()).
 */
class DrawService
{
    public const LOCKED_MESSAGE = 'Cannot change guests after names have been locked. Reset claims first.';

    public function anyClaimed(Party $party): bool
    {
        return $party->guests()->where('claimed', true)->exists();
    }

    public function canRedraw(Party $party): bool
    {
        return ! $this->anyClaimed($party);
    }

    /**
     * The spin: return the guest's recipient, drawing one live when
     * they do not have one yet. Returns null when no draw is possible
     * (a party of fewer than two, or the unreachable stranded state,
     * which returns null rather than ever assigning a guest to
     * themselves).
     *
     * Must be called inside a database transaction; it locks the
     * party's guest rows so concurrent spins cannot pick the same
     * target.
     */
    public function assignForGuest(Guest $guest): ?Guest
    {
        if ($guest->recipient_id !== null) {
            return $guest->recipient()->first();
        }

        $party = $guest->party;
        if ($party === null) {
            return null;
        }

        $guests = $party->guests()->orderBy('id')->lockForUpdate()->get();

        if ($guests->count() < 2) {
            return null;
        }

        $takenIds = $guests
            ->pluck('recipient_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->all();

        $targets = $guests->reject(fn (Guest $g) => in_array((int) $g->id, $takenIds, true));
        $candidates = $targets->reject(fn (Guest $g) => (int) $g->id === (int) $guest->id);

        if ($candidates->isEmpty()) {
            // The spinner is the only unpicked target left. The
            // endgame guard makes this unreachable; never self-assign.
            return null;
        }

        $giversLeft = $guests->filter(fn (Guest $g) => $g->recipient_id === null);

        $pick = null;
        if ($giversLeft->count() === 2) {
            $other = $giversLeft->first(fn (Guest $g) => (int) $g->id !== (int) $guest->id);
            if ($other !== null && $candidates->contains(fn (Guest $g) => (int) $g->id === (int) $other->id)) {
                // Forced: taking anyone else strands the last giver
                // with only themselves (see the class docblock).
                $pick = $other;
            }
        }

        $pick ??= $candidates->random();

        $guest->recipient_id = (int) $pick->id;
        $guest->save();

        return $pick;
    }
}
