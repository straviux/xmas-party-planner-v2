<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\KringleDraw;
use App\Models\KringleTheme;
use App\Models\Party;
use App\Support\Serializers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monito Monita (Kris Kringle): the weekly themed exchange that runs
 * alongside the main Secret Santa exchange, which it never touches.
 *
 * Themes: one per party per Monday week (Asia/Manila), generated
 * lazily the first time anyone asks in a new week and stored, drawn
 * at random from the pool without repeats until the pool cycles.
 * The run ends at the party week: the week containing the party's
 * date gets no theme (and no spins). No party date, no end.
 *
 * The roulette: each week, each guest spins once to draw who they
 * are buying for that week. The spin is a live pick from the guests
 * not yet drawn as recipients that week, never the spinner, inside a
 * locked transaction, so a week's spins build a derangement of the
 * party spin by spin. When exactly two givers are left in a week
 * and the other giver is still undrawn, the pick is forced onto
 * that other giver: taking anyone else would strand the week's
 * last spinner with only themselves, and the week's counts include
 * guests who may never spin, so no later swap could repair it. The
 * one accepted edge (design doc): if the last spinner is also the
 * week's last undrawn guest while another guest never spins at all,
 * the spin reports kringle_no_one_left rather than ever assigning
 * a guest to themselves.
 *
 * A week's pairings are never listed anywhere: a guest only ever
 * receives their own recipient's real name (Kringle is open, by the
 * owner's call; the main exchange stays codename-only), the host
 * only a count.
 */
class KringleService
{
    public const TIMEZONE = 'Asia/Manila';

    /** @var array<int, string> */
    public const THEMES = [
        'Something long',
        'Something soft',
        'Something red',
        'Something sweet',
        'Something round',
        'Something shiny',
        'Something blue',
        'Something useful',
        'Something that makes a sound',
        'Something that smells good',
        'Something edible',
        'Something green',
        'Something you can wear',
        'Something for the desk',
        'Something yellow',
        'Something hard',
        'Something small',
        'Something warm',
        'Something with stripes',
        'Something that lights up',
        'Something made of paper',
        'Something minty',
        'Something that comes in pairs',
        'Something golden',
    ];

    /** Monday of the current week, Asia/Manila. */
    public function currentWeekStart(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * Monday of the party's week as a 'Y-m-d' string, or null when no
     * party date is set. All week comparisons in this service are
     * date-string comparisons: the current week is computed in
     * Asia/Manila while the party date lives in the app's timezone,
     * and comparing the two as instants would shift the boundary.
     */
    private function partyWeekStart(Party $party): ?string
    {
        if ($party->date === null) {
            return null;
        }

        return Carbon::parse($party->date->toDateString())
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();
    }

    /** Whether the given week is inside the run (before the party week). */
    public function isRunWeek(Party $party, Carbon $weekStart): bool
    {
        $partyWeek = $this->partyWeekStart($party);

        return $partyWeek === null || $weekStart->toDateString() < $partyWeek;
    }

    /** Theme weeks remaining including the given week; null when no party date is set. */
    public function weeksLeft(Party $party, Carbon $weekStart): ?int
    {
        $partyWeek = $this->partyWeekStart($party);

        if ($partyWeek === null) {
            return null;
        }

        if (! $this->isRunWeek($party, $weekStart)) {
            return 0;
        }

        return (int) Carbon::parse($weekStart->toDateString())->diffInWeeks(Carbon::parse($partyWeek));
    }

    /**
     * The current week's theme, generated on first ask. Null when the
     * run is over (the party week has arrived).
     */
    public function currentTheme(Party $party): ?KringleTheme
    {
        $weekStart = $this->currentWeekStart();

        if (! $this->isRunWeek($party, $weekStart)) {
            return null;
        }

        $date = $weekStart->toDateString();

        $existing = $party->kringleThemes()->where('week_start', $date)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($party, $date) {
            $existing = $party->kringleThemes()->where('week_start', $date)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            try {
                return $party->kringleThemes()->create([
                    'week_start' => $date,
                    'theme' => $this->pickTheme($party, []),
                ]);
            } catch (QueryException) {
                // A concurrent first view won the unique (party, week)
                // race; its row is the week's theme.
                return $party->kringleThemes()->where('week_start', $date)->firstOrFail();
            }
        });
    }

    /**
     * Host regenerate: swap the current week's theme for a different
     * unused one; the replaced theme returns to the party's pool.
     * Returns the (possibly unchanged) theme row, or null outside the
     * run.
     */
    public function regenerate(Party $party): ?KringleTheme
    {
        $theme = $this->currentTheme($party);
        if ($theme === null) {
            return null;
        }

        $otherThemes = $party->kringleThemes()
            ->where('id', '!=', $theme->id)
            ->pluck('theme')
            ->all();

        $replacement = $this->pickTheme($party, [...$otherThemes, $theme->theme]);

        if ($replacement !== null && $replacement !== $theme->theme) {
            $theme->update(['theme' => $replacement]);
        }

        return $theme->fresh();
    }

    /**
     * Pick a theme at random from the pool, skipping the party's used
     * themes and the hard $exclude list. Once every pool theme has
     * been used the cycle restarts and used themes may repeat, but
     * the hard excludes always hold (regenerate never lands on
     * another week's theme or the theme it is replacing). Null only
     * when the hard excludes cover the whole pool.
     *
     * @param  array<int, string>  $exclude
     */
    private function pickTheme(Party $party, array $exclude): ?string
    {
        $used = $party->kringleThemes()->pluck('theme')->all();

        $candidates = array_values(array_diff(self::THEMES, $used, $exclude));
        if ($candidates === []) {
            $candidates = array_values(array_diff(self::THEMES, $exclude));
        }
        if ($candidates === []) {
            return null;
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * The spin: draw the guest's recipient for the current week.
     *
     * @throws KringleException already spun (409), inactive week,
     *         fewer than two guests, or the accepted no-one-left edge.
     */
    public function spin(Guest $guest): KringleDraw
    {
        $party = $guest->party;
        $weekStart = $this->currentWeekStart();
        $date = $weekStart->toDateString();

        if ($this->currentTheme($party) === null) {
            throw KringleException::make('kringle_inactive', 409);
        }

        return DB::transaction(function () use ($party, $guest, $date) {
            $already = $party->kringleDraws()
                ->where('week_start', $date)
                ->where('giver_id', $guest->id)
                ->exists();
            if ($already) {
                throw KringleException::make('kringle_already_spun', 409);
            }

            $guests = $party->guests()->orderBy('id')->lockForUpdate()->get();

            if ($guests->count() < 2) {
                throw KringleException::make('kringle_not_enough_guests');
            }

            $draws = $party->kringleDraws()->where('week_start', $date)->get();
            $takenIds = $draws->pluck('recipient_id')->map(fn ($id) => (int) $id)->all();
            $giverIds = $draws->pluck('giver_id')->map(fn ($id) => (int) $id)->all();

            $targets = $guests->reject(fn (Guest $g) => in_array((int) $g->id, $takenIds, true));
            $candidates = $targets->reject(fn (Guest $g) => (int) $g->id === (int) $guest->id);

            if ($candidates->isEmpty()) {
                throw KringleException::make('kringle_no_one_left', 409);
            }

            $giversLeft = $guests->reject(fn (Guest $g) => in_array((int) $g->id, $giverIds, true));

            $pick = null;
            if ($giversLeft->count() === 2) {
                $other = $giversLeft->first(fn (Guest $g) => (int) $g->id !== (int) $guest->id);
                if ($other !== null && $candidates->contains(fn (Guest $g) => (int) $g->id === (int) $other->id)) {
                    // Forced: taking anyone else strands the week's
                    // last giver with only themselves.
                    $pick = $other;
                }
            }

            $pick ??= $candidates->random();

            return $party->kringleDraws()->create([
                'week_start' => $date,
                'giver_id' => (int) $guest->id,
                'recipient_id' => (int) $pick->id,
            ]);
        });
    }

    /**
     * The guest-facing state (kringle_current action and the Kringle
     * page props): the current theme and week, the caller's own spin
     * state and recipient name, and their past weeks. Never any
     * other guest's pairing, never a real name.
     *
     * @return array<string, mixed>
     */
    public function guestState(Party $party, Guest $guest): array
    {
        $weekStart = $this->currentWeekStart();
        $theme = $this->currentTheme($party);

        $ownDraw = $party->kringleDraws()
            ->where('week_start', $weekStart->toDateString())
            ->where('giver_id', $guest->id)
            ->with('recipient')
            ->first();

        $history = [];
        $pastThemes = $party->kringleThemes()
            ->where('week_start', '<', $weekStart->toDateString())
            ->orderByDesc('week_start')
            ->get();
        if ($pastThemes->isNotEmpty()) {
            $pastDraws = $party->kringleDraws()
                ->whereIn('week_start', $pastThemes->pluck('week_start')->all())
                ->where('giver_id', $guest->id)
                ->with('recipient')
                ->get()
                ->keyBy(fn (KringleDraw $draw) => $draw->week_start);

            foreach ($pastThemes as $past) {
                $draw = $pastDraws->get($past->week_start);
                $history[] = [
                    'week_start' => $past->week_start,
                    'week_end' => Carbon::parse($past->week_start)->addDays(6)->toDateString(),
                    'theme' => $past->theme,
                    'recipient_name' => $draw?->recipient === null
                        ? null
                        : Serializers::kringleRecipient($draw->recipient)['name'],
                ];
            }
        }

        return [
            'active' => $theme !== null,
            'theme' => $theme?->theme,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
            'weeks_left' => $this->weeksLeft($party, $weekStart),
            'spun' => $ownDraw !== null,
            'recipient' => $ownDraw?->recipient === null
                ? null
                : Serializers::kringleRecipient($ownDraw->recipient),
            'history' => $history,
        ];
    }

    /**
     * The host-facing summary for the dashboard card: the current
     * theme and how many guests have spun this week. Counts only;
     * never any pairing.
     *
     * @return array<string, mixed>
     */
    public function hostSummary(Party $party): array
    {
        $weekStart = $this->currentWeekStart();
        $theme = $this->currentTheme($party);

        return [
            'active' => $theme !== null,
            'theme' => $theme?->theme,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
            'weeks_left' => $this->weeksLeft($party, $weekStart),
            'spun_count' => $party->kringleDraws()->where('week_start', $weekStart->toDateString())->count(),
            'guest_count' => $party->guests()->count(),
        ];
    }
}
