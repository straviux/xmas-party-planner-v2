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
