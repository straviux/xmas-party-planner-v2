<?php

namespace App\Support;

use App\Models\Guest;
use App\Models\Party;

/**
 * Temporary secret names (owner's ask, 2026-11-10): every guest gets a
 * codename from the moment they are added, so nobody is ever left
 * showing as "still choosing" on a reveal or a wishlist. The name is a
 * festive word pair from the same pools the suggestion dice uses,
 * unique within the party like a chosen name, and flagged
 * `secret_name_temp` until the guest picks their own via
 * guest_set_secret_name, which clears the flag. Host and public
 * payloads keep counting only CHOSEN names as "picked"
 * (Serializers::publicGuest), so the pick-one nudges keep working for
 * guests still on a temporary name.
 */
class TempSecretName
{
    /** @var list<string> Mirrors NAME_FIRST in SecretNameForm.vue. */
    private const FIRST = [
        'Jolly', 'Frosty', 'Twinkle', 'Cozy', 'Merry', 'Peppy', 'Holly',
        'Ginger', 'Sugar', 'Snowy', 'Tinsel', 'Pudding', 'Waffle',
        'Noodle', 'Mumble', 'Pickle', 'Wobbly', 'Giggly', 'Goofy',
        'Chunky', 'Sleepy', 'Clumsy', 'Cheeky', 'Bouncy', 'Dizzy',
        'Snazzy',
    ];

    /** @var list<string> Mirrors NAME_SECOND in SecretNameForm.vue. */
    private const SECOND = [
        'Reindeer', 'Elf', 'Pixie', 'Fox', 'Penguin', 'Snowman', 'Cookie',
        'Cocoa', 'Candle', 'Mittens', 'Sleigh', 'Star', 'Bell', 'Moose',
        'Panda', 'Sprout', 'Mango', 'Pineapple', 'Coconut', 'Banana',
        'Papaya', 'Cherry', 'Melon', 'Lemon', 'Guava', 'Durian', 'Owl',
        'Bear', 'Bunny', 'Turtle', 'Frog', 'Koala', 'Tiger', 'Monkey',
        'Duck', 'Goat', 'Cat', 'Llama',
    ];

    /**
     * Lowercase set of every codename already used in the party
     * (chosen and temporary alike), keyed for O(1) checks.
     *
     * @return array<string, true>
     */
    private static function takenNames(Party $party): array
    {
        $taken = [];
        foreach ($party->guests()->pluck('secret_name') as $name) {
            if (is_string($name) && trim($name) !== '') {
                $taken[mb_strtolower(trim($name))] = true;
            }
        }

        return $taken;
    }

    /**
     * A random unused pair for the party. With 26 x 38 = 988 pairs a
     * real party never runs out; if one somehow did, a numeric suffix
     * keeps the name unique (and within the 30-character limit)
     * instead of failing.
     *
     * @param  array<string, true>  $taken
     */
    private static function generate(array $taken): string
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $candidate = self::FIRST[array_rand(self::FIRST)].' '.self::SECOND[array_rand(self::SECOND)];
            if (! isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        foreach (self::FIRST as $first) {
            foreach (self::SECOND as $second) {
                $candidate = $first.' '.$second;
                if (! isset($taken[mb_strtolower($candidate)])) {
                    return $candidate;
                }
            }
        }

        $suffix = 2;
        while (isset($taken['jolly reindeer '.$suffix])) {
            $suffix++;
        }

        return 'Jolly Reindeer '.$suffix;
    }

    /**
     * Give the guest a temporary name when they have no codename at
     * all. A guest who already picked (or already holds a temporary)
     * is left untouched.
     */
    public static function assign(Guest $guest): void
    {
        if (is_string($guest->secret_name) && trim($guest->secret_name) !== '') {
            return;
        }

        $guest->secret_name = self::generate(self::takenNames($guest->party));
        $guest->secret_name_temp = true;
        $guest->save();
    }

    /**
     * Assign temporary names to every guest in the party who has none.
     * Used by the migration that introduced the flag, so guests added
     * before temporary names existed are not left behind either.
     * Returns how many guests were assigned.
     */
    public static function backfillParty(Party $party): int
    {
        $taken = self::takenNames($party);
        $assigned = 0;

        foreach ($party->guests()->get() as $guest) {
            if (is_string($guest->secret_name) && trim($guest->secret_name) !== '') {
                continue;
            }
            $name = self::generate($taken);
            $taken[mb_strtolower($name)] = true;
            $guest->secret_name = $name;
            $guest->secret_name_temp = true;
            $guest->save();
            $assigned++;
        }

        return $assigned;
    }
}
