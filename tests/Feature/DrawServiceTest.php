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

test('derangement: nobody draws themselves for guest counts 2 to 15', function () {
    $draw = app(DrawService::class);

    foreach (range(2, 15) as $n) {
        // Repeat each size a few times: the shuffle is random.
        for ($round = 0; $round < 5; $round++) {
            $party = makeDrawParty($n);
            $draw->redraw($party);

            $guests = $party->guests()->get();
            $recipients = $guests->pluck('recipient_id');

            foreach ($guests as $guest) {
                expect($guest->recipient_id)->not->toBeNull()
                    ->and((int) $guest->recipient_id)->not->toBe($guest->id);
            }

            // A derangement here is also a permutation: everyone is drawn once.
            expect($recipients->unique())->toHaveCount($n);
        }
    }
});

test('two guests simply swap', function () {
    $party = makeDrawParty(2);
    [$a, $b] = $party->guests()->orderBy('id')->get();

    app(DrawService::class)->redraw($party);

    expect($a->fresh()->recipient_id)->toBe($b->id)
        ->and($b->fresh()->recipient_id)->toBe($a->id);
});

test('a single guest gets no recipient', function () {
    $party = makeDrawParty(1);

    app(DrawService::class)->redraw($party);

    expect($party->guests()->first()->recipient_id)->toBeNull();
});

test('an empty party draws without error', function () {
    $party = makeDrawParty(0);

    app(DrawService::class)->redraw($party);

    expect($party->guests()->count())->toBe(0);
});

test('redraw is refused once any name is claimed', function () {
    $party = makeDrawParty(3);
    $party->guests()->first()->update(['claimed' => true, 'claimed_at' => now()]);

    $draw = app(DrawService::class);

    expect($draw->anyClaimed($party))->toBeTrue()
        ->and($draw->canRedraw($party))->toBeFalse();

    expect(fn () => $draw->redraw($party))
        ->toThrow(DomainException::class, 'guests_locked');
});

test('canRedraw is true while nobody has claimed', function () {
    $party = makeDrawParty(4);
    $draw = app(DrawService::class);

    expect($draw->anyClaimed($party))->toBeFalse()
        ->and($draw->canRedraw($party))->toBeTrue();
});
