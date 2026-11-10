<?php

use App\Models\Guest;

/*
 * Host reshuffle with keeps (owner's ask, 2026-11-10): the draw may be
 * re-dealt after names are locked, except the guests the host marks
 * to keep their current recipient (one who already bought the gift).
 * The response carries counts only; the host never sees a mapping.
 */

/** @return array<string, int> giver id => recipient id, straight off the models */
function reshuffleMapping(array $guests): array
{
    $map = [];
    foreach ($guests as $guest) {
        $map[(int) $guest->id] = (int) $guest->fresh()->recipient_id;
    }

    return $map;
}

function expectValidDerangement(array $map): void
{
    expect(array_values($map))->toHaveCount(count($map))
        ->and(array_unique(array_values($map)))->toHaveCount(count($map));
    foreach ($map as $giver => $recipient) {
        expect($recipient)->not->toBe($giver);
    }
}

test('reshuffle keeps the excepted pair and re-deals the rest, even while claimed', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes'],
        ['display' => 'Cora Diaz'],
        ['display' => 'Dan Lim'],
        ['display' => 'Eve Santos'],
    ]);
    // Names are locked: a plain redraw would refuse from here on.
    $guests['Ana Cruz']->update(['claimed' => true, 'claimed_at' => now()]);
    $guests['Ben Reyes']->update(['claimed' => true, 'claimed_at' => now(), 'revealed' => true]);

    $before = reshuffleMapping($guests);
    expectValidDerangement($before);

    secretNameLoginHost($this);
    $response = $this->postJson('/api/host_reshuffle', [
        'except_guest_ids' => [$guests['Ana Cruz']->id],
    ])->assertOk()
        ->assertJsonPath('kept', 1)
        ->assertJsonPath('reshuffled', 4);

    // Counts only: no mapping leaks into the response.
    expect($response->json())->not->toHaveKeys(['recipient_id', 'recipients', 'guests']);

    $after = reshuffleMapping($guests);
    expectValidDerangement($after);
    // Ana keeps exactly her recipient; her recipient goes to nobody else.
    expect($after[$guests['Ana Cruz']->id])->toBe($before[$guests['Ana Cruz']->id]);
    // Claims and reveals are untouched by the reshuffle.
    expect($guests['Ben Reyes']->fresh()->claimed)->toBeTrue()
        ->and($guests['Ben Reyes']->fresh()->revealed)->toBeTrue();
});

test('reshuffle with no exceptions re-deals everyone into a derangement', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes'],
        ['display' => 'Cora Diaz'],
        ['display' => 'Dan Lim'],
    ]);

    secretNameLoginHost($this);
    $this->postJson('/api/host_reshuffle', ['except_guest_ids' => []])
        ->assertOk()
        ->assertJsonPath('kept', 0)
        ->assertJsonPath('reshuffled', 4);

    expectValidDerangement(reshuffleMapping($guests));
});

test('reshuffle with everyone excepted changes nothing', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes'],
        ['display' => 'Cora Diaz'],
    ]);
    $before = reshuffleMapping($guests);

    secretNameLoginHost($this);
    $this->postJson('/api/host_reshuffle', [
        'except_guest_ids' => collect($guests)->map(fn (Guest $g) => $g->id)->all(),
    ])->assertOk()
        ->assertJsonPath('kept', 3)
        ->assertJsonPath('reshuffled', 0);

    expect(reshuffleMapping($guests))->toBe($before);
});

test('a single guest left over simply keeps their recipient', function () {
    // With two of three kept, the one left over has no one to swap
    // with: the only recipient available is their current one. That
    // is a quiet no-op for them, not an error.
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes'],
        ['display' => 'Cora Diaz'],
    ]);
    $before = reshuffleMapping($guests);

    secretNameLoginHost($this);
    $this->postJson('/api/host_reshuffle', [
        'except_guest_ids' => [$guests['Ana Cruz']->id, $guests['Ben Reyes']->id],
    ])->assertOk()
        ->assertJsonPath('kept', 3)
        ->assertJsonPath('reshuffled', 0);

    expect(reshuffleMapping($guests))->toBe($before);
});

test('reshuffle needs the host session', function () {
    secretNameParty([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes'],
    ]);

    $this->postJson('/api/host_reshuffle', ['except_guest_ids' => []])
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden');
});
