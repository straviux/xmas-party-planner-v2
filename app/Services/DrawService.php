<?php

namespace App\Services;

use App\Models\Party;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Secret Santa draw: a derangement of the party's guests.
 *
 * Nobody draws themselves. With two guests they swap. With fewer than two
 * guests no draw is possible and every recipient_id stays null (A8).
 * Once any name is claimed the draw is frozen: redraw() refuses with the
 * 'guests_locked' error code.
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
     * (Re)assign every guest's recipient.
     *
     * @throws DomainException with message 'guests_locked' when any guest has claimed.
     */
    public function redraw(Party $party): void
    {
        if ($this->anyClaimed($party)) {
            throw new DomainException('guests_locked');
        }

        DB::transaction(function () use ($party) {
            $guests = $party->guests()->orderBy('id')->get();
            $ids = $guests->pluck('id')->map(fn ($id) => (int) $id)->all();
            $n = count($ids);

            if ($n < 2) {
                foreach ($guests as $guest) {
                    if ($guest->recipient_id !== null) {
                        $guest->update(['recipient_id' => null]);
                    }
                }

                return;
            }

            $assignment = $this->derangement($ids);

            foreach ($guests as $guest) {
                $guest->update(['recipient_id' => $assignment[(int) $guest->id]]);
            }
        });
    }

    /**
     * Reshuffle recipients mid-party (owner's ask, 2026-11-10): unlike
     * redraw(), this is allowed after names are claimed, because the
     * host may need it once gift buying has started. Givers listed in
     * $exceptGuestIds keep their current recipient exactly (for
     * example a guest who already bought their gift); everyone else
     * with a recipient is re-dealt from the remaining recipients, so
     * nobody draws themselves, frozen recipients are never re-dealt,
     * and the assignment stays one-to-one. Claims, reveals, and PINs
     * are untouched: a reshuffled guest who already revealed simply
     * sees their new recipient on Show my recipient again.
     *
     * A single guest left over is never an error: the only
     * recipient available to them is the one they already have (the
     * pool is the free guests' own current recipients), so they
     * simply keep it and count as kept.
     *
     * @param  array<int, int>  $exceptGuestIds
     * @return array{kept: int, reshuffled: int}
     */
    public function reshuffle(Party $party, array $exceptGuestIds): array
    {
        return DB::transaction(function () use ($party, $exceptGuestIds) {
            $guests = $party->guests()->orderBy('id')->lockForUpdate()->get();
            $except = array_map('intval', $exceptGuestIds);

            $kept = 0;
            $free = [];
            foreach ($guests as $guest) {
                if ($guest->recipient_id === null) {
                    continue;
                }
                if (in_array((int) $guest->id, $except, true)) {
                    $kept++;
                } else {
                    $free[] = $guest;
                }
            }

            if (count($free) <= 1) {
                // Nobody to re-deal, or one guest left over whose only
                // available recipient is their current one: they keep
                // it and count as kept.
                return ['kept' => $kept + count($free), 'reshuffled' => 0];
            }

            $giverIds = array_map(fn ($guest) => (int) $guest->id, $free);
            $pool = array_map(fn ($guest) => (int) $guest->recipient_id, $free);

            $assignment = $this->constrainedPermutation($giverIds, $pool);

            foreach ($free as $guest) {
                $guest->update(['recipient_id' => $assignment[(int) $guest->id]]);
            }

            return ['kept' => $kept, 'reshuffled' => count($giverIds)];
        });
    }

    /**
     * Assign each giver one recipient from the pool (same size, all
     * distinct) with no giver receiving their own id.
     *
     * @param  array<int, int>  $giverIds
     * @param  array<int, int>  $pool
     * @return array<int, int> giver id => recipient id
     */
    private function constrainedPermutation(array $giverIds, array $pool): array
    {
        $n = count($giverIds);

        if ($n === 1) {
            // Only reachable with a pool holding the giver's current
            // recipient, so the forced assignment is the identity.
            return [$giverIds[0] => $pool[0]];
        }

        for ($attempt = 0; $attempt < 300; $attempt++) {
            $shuffled = $pool;
            shuffle($shuffled);

            $valid = true;
            foreach ($giverIds as $i => $giverId) {
                if ($shuffled[$i] === $giverId) {
                    $valid = false;
                    break;
                }
            }

            if ($valid) {
                return array_combine($giverIds, $shuffled);
            }
        }

        // Rejection sampling only ever runs dry on tiny or degenerate
        // pools; settle those with a plain backtracking search. (A
        // valid assignment always exists for two or more givers: any
        // single giver can take anything but themselves, and any set
        // of two or more can take the whole pool between them.)
        $assignment = [];
        $used = [];
        $this->assignBySearch($giverIds, $pool, 0, $used, $assignment);

        return $assignment;
    }

    /**
     * @param  array<int, int>  $giverIds
     * @param  array<int, int>  $pool
     * @param  array<int, bool>  $used
     * @param  array<int, int>  $assignment
     */
    private function assignBySearch(array $giverIds, array $pool, int $index, array &$used, array &$assignment): bool
    {
        if ($index >= count($giverIds)) {
            return true;
        }

        $giverId = $giverIds[$index];
        foreach ($pool as $recipientId) {
            if (isset($used[$recipientId]) || $recipientId === $giverId) {
                continue;
            }
            $used[$recipientId] = true;
            $assignment[$giverId] = $recipientId;
            if ($this->assignBySearch($giverIds, $pool, $index + 1, $used, $assignment)) {
                return true;
            }
            unset($used[$recipientId], $assignment[$giverId]);
        }

        return false;
    }

    /**
     * Alias kept for call sites that speak of "the draw" rather than a redraw.
     */
    public function draw(Party $party): void
    {
        $this->redraw($party);
    }

    /**
     * Map of giver id => recipient id with no fixed points.
     *
     * Rejection-sampled shuffle first; after a capped number of attempts a
     * rotation by one is used, which is a derangement for every n >= 2.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function derangement(array $ids): array
    {
        $n = count($ids);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $shuffled = $ids;
            shuffle($shuffled);

            $valid = true;
            foreach ($ids as $i => $id) {
                if ($shuffled[$i] === $id) {
                    $valid = false;
                    break;
                }
            }

            if ($valid) {
                return array_combine($ids, $shuffled);
            }
        }

        $rotated = [];
        foreach ($ids as $i => $id) {
            $rotated[$id] = $ids[($i + 1) % $n];
        }

        return $rotated;
    }
}
