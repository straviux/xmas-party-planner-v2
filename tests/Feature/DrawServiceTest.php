<?php

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use Illuminate\Support\Str;

if (! function_exists('makeDrawParty')) {
    function makeDrawParty(int $guestCount): Party
    {
        $party = Party::create([
            'name' => 'Draw Test Party',
            'invite_code' => strtoupper(Str::random(8)),
        ]);

        for ($i = 1; $i <= $guestCount; $i++) {
            $party->guests()->create(['display' => "Guest {$i}"]);
        }

        return $party;
    }
}

if (! function_exists('spinForAll')) {
    /**
     * Spin for every guest in the given order (of guest models),
     * inside one transaction like a sequence of real reveals.
     *
     * @param  array<int, Guest>  $orderedGuests
     */
    function spinForAll(array $orderedGuests): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($orderedGuests) {
            $draw = app(DrawService::class);
            foreach ($orderedGuests as $guest) {
                $draw->assignForGuest($guest->fresh());
            }
        });
    }
}

test('a spin assigns a recipient who is not the spinner and not already taken', function () {
    $party = makeDrawParty(4);
    [$a, $b] = $party->guests()->orderBy('id')->get();

    $draw = app(DrawService::class);
    $first = $draw->assignForGuest($a->fresh());
    expect($first)->not->toBeNull()
        ->and($first->id)->not->toBe($a->id);

    $second = $draw->assignForGuest($b->fresh());
    expect($second)->not->toBeNull()
        ->and($second->id)->not->toBe($b->id)
        ->and($second->id)->not->toBe($first->id);
});

test('sequential spins derange the whole party for guest counts 2 to 15', function () {
    foreach (range(2, 15) as $n) {
        // Repeat each size a few times, in id order and shuffled
        // orders: the picks are random, the guarantee is not.
        for ($round = 0; $round < 4; $round++) {
            $party = makeDrawParty($n);
            $guests = $party->guests()->orderBy('id')->get()->all();
            if ($round % 2 === 1) {
                shuffle($guests);
            }

            spinForAll($guests);

            $fresh = $party->guests()->get();
            $recipients = $fresh->pluck('recipient_id');

            foreach ($fresh as $guest) {
                expect($guest->recipient_id)->not->toBeNull()
                    ->and((int) $guest->recipient_id)->not->toBe($guest->id);
            }

            // Everyone is picked exactly once.
            expect($recipients->unique())->toHaveCount($n);
        }
    }
});

test('two guests simply swap', function () {
    $party = makeDrawParty(2);
    [$a, $b] = $party->guests()->orderBy('id')->get();

    spinForAll([$a, $b]);

    expect($a->fresh()->recipient_id)->toBe($b->id)
        ->and($b->fresh()->recipient_id)->toBe($a->id);
});

test('endgame guard: the second-to-last spinner is forced onto the last giver when needed', function () {
    $party = makeDrawParty(3);
    [$a, $b, $c] = $party->guests()->orderBy('id')->get();

    // A has already spun and taken B. Givers left: B and C.
    // Targets left: A and C. If B took A, C would be stranded with
    // only C left, so B must be forced onto C; then C takes A.
    $a->update(['recipient_id' => $b->id]);

    $draw = app(DrawService::class);
    $bPick = $draw->assignForGuest($b->fresh());
    expect($bPick)->not->toBeNull()
        ->and($bPick->id)->toBe($c->id);

    $cPick = $draw->assignForGuest($c->fresh());
    expect($cPick)->not->toBeNull()
        ->and($cPick->id)->toBe($a->id);
});

test('an already assigned recipient is kept as-is', function () {
    $party = makeDrawParty(3);
    [$a, $b] = $party->guests()->orderBy('id')->get();
    $a->update(['recipient_id' => $b->id]);

    $kept = app(DrawService::class)->assignForGuest($a->fresh());

    expect($kept)->not->toBeNull()
        ->and($kept->id)->toBe($b->id)
        ->and($a->fresh()->recipient_id)->toBe($b->id);
});

test('a single guest gets no recipient', function () {
    $party = makeDrawParty(1);

    $pick = app(DrawService::class)->assignForGuest($party->guests()->first());

    expect($pick)->toBeNull()
        ->and($party->guests()->first()->recipient_id)->toBeNull();
});

test('anyClaimed and canRedraw track claims for the guest-list lock', function () {
    $party = makeDrawParty(3);
    $draw = app(DrawService::class);

    expect($draw->anyClaimed($party))->toBeFalse()
        ->and($draw->canRedraw($party))->toBeTrue();

    $party->guests()->first()->update(['claimed' => true, 'claimed_at' => now()]);

    expect($draw->anyClaimed($party))->toBeTrue()
        ->and($draw->canRedraw($party))->toBeFalse();
});
