<?php

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use Illuminate\Support\Facades\Hash;

if (! function_exists('revealAgainParty')) {
    /**
     * A party with guests and a completed draw.
     *
     * Each guest definition: display, plus optional nickname, aliases,
     * pin (plain text, stored hashed), claimed, revealed.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function revealAgainParty(array $definitions): array
    {
        $party = Party::create([
            'name' => 'Office Christmas Party',
            'budget' => '500 pesos',
            'date' => '2026-12-20',
            'invite_code' => 'TESTCODE',
        ]);

        $guests = [];
        foreach ($definitions as $definition) {
            $guests[$definition['display']] = $party->guests()->create([
                'display' => $definition['display'],
                'nickname' => $definition['nickname'] ?? null,
                'aliases' => $definition['aliases'] ?? null,
                'pin_hash' => isset($definition['pin']) ? Hash::make($definition['pin']) : null,
                'claimed' => $definition['claimed'] ?? false,
                'revealed' => $definition['revealed'] ?? false,
            ]);
        }

        (new DrawService)->redraw($party);

        return [$party, $guests];
    }
}

if (! function_exists('revealAgainDefaultParty')) {
    /** @return array{0: Party, 1: array<string, Guest>} */
    function revealAgainDefaultParty(): array
    {
        return revealAgainParty([
            ['display' => 'Ana Cruz', 'nickname' => 'Annie'],
            ['display' => 'Ben Reyes'],
            ['display' => 'Cora Diaz', 'aliases' => ['Coring']],
        ]);
    }
}

// ----------------------------------------------------------------------
// Happy path
// ----------------------------------------------------------------------

test('reveal_again with the correct PIN returns the same recipient as the reveal, plus the budget', function () {
    [$party, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $revealed = $this->postJson('/api/reveal');
    $revealed->assertOk();
    $recipientId = $revealed->json('recipient.id');

    $response = $this->postJson('/api/reveal_again', ['pin' => '1234']);
    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('recipient.id', $recipientId)
        ->assertJsonPath('recipient.display', $revealed->json('recipient.display'))
        ->assertJsonPath('recipient.nickname', $revealed->json('recipient.nickname'))
        ->assertJsonPath('budget', $party->budget);

    // It stays available: asking again returns the same recipient.
    $this->postJson('/api/reveal_again', ['pin' => '1234'])
        ->assertOk()
        ->assertJsonPath('recipient.id', $recipientId);
});

// ----------------------------------------------------------------------
// PIN failures carry no recipient data
// ----------------------------------------------------------------------

test('reveal_again with a wrong PIN is pin_incorrect and leaks no recipient data', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $recipient = Guest::find($ana->fresh()->recipient_id);

    $response = $this->postJson('/api/reveal_again', ['pin' => '9999']);
    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'pin_incorrect')
        ->assertJsonMissingPath('recipient');

    expect(json_encode($response->json()))->not->toContain($recipient->display);
});

test('reveal_again with a malformed PIN is pin_must_be_4_digits and an absent PIN is pin_incorrect', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $this->postJson('/api/reveal_again', ['pin' => '12'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_must_be_4_digits');

    $this->postJson('/api/reveal_again', [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_incorrect');
});

test('reveal_again for a revealed guest with no PIN hash is pin_required_create', function () {
    [, $guests] = revealAgainDefaultParty();
    $cora = $guests['Cora Diaz'];
    $cora->update(['claimed' => true, 'claimed_at' => now(), 'revealed' => true, 'pin_hash' => null]);

    $recipient = Guest::find($cora->fresh()->recipient_id);

    $this->withSession(['guest_id' => $cora->id]);

    $response = $this->postJson('/api/reveal_again', ['pin' => '1234']);
    $response->assertStatus(422)
        ->assertJsonPath('error', 'pin_required_create')
        ->assertJsonMissingPath('recipient');

    expect(json_encode($response->json()))->not->toContain($recipient->display);
});

// ----------------------------------------------------------------------
// Each guest only ever sees their own recipient
// ----------------------------------------------------------------------

test('reveal_again returns each guest their own recipient and never another guest\'s', function () {
    [, $guests] = revealAgainParty([
        ['display' => 'Ana Cruz', 'pin' => '1111'],
        ['display' => 'Ben Reyes', 'pin' => '2222'],
        ['display' => 'Cora Diaz', 'pin' => '3333'],
    ]);
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    $anaRecipientId = $ana->fresh()->recipient_id;
    $benRecipientId = $ben->fresh()->recipient_id;

    // The draw is a permutation, so the two recipients are different guests.
    expect($anaRecipientId)->not->toBe($benRecipientId);

    $this->postJson('/api/enter', ['guest_id' => $ana->id, 'pin' => '1111'])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $this->postJson('/api/enter', ['guest_id' => $ben->id, 'pin' => '2222'])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    // Ben's session: Ben's PIN returns Ben's recipient.
    $benResponse = $this->postJson('/api/reveal_again', ['pin' => '2222']);
    $benResponse->assertOk()
        ->assertJsonPath('recipient.id', $benRecipientId);
    expect(json_encode($benResponse->json()))
        ->not->toContain(Guest::find($anaRecipientId)->display);

    // Ana's session again: Ana's PIN returns Ana's recipient, and no
    // other guest's name appears anywhere in the payload.
    $this->postJson('/api/enter', ['guest_id' => $ana->id, 'pin' => '1111'])->assertOk();

    $anaResponse = $this->postJson('/api/reveal_again', ['pin' => '1111']);
    $anaResponse->assertOk()
        ->assertJsonPath('recipient.id', $anaRecipientId);

    $anaJson = json_encode($anaResponse->json());
    foreach ($guests as $guest) {
        if ((int) $guest->id !== (int) $anaRecipientId) {
            expect($anaJson)->not->toContain($guest->display);
        }
    }
});

// ----------------------------------------------------------------------
// State gates
// ----------------------------------------------------------------------

test('reveal_again before the first reveal is not_revealed', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $recipient = Guest::find($ana->fresh()->recipient_id);

    $response = $this->postJson('/api/reveal_again', ['pin' => '1234']);
    $response->assertStatus(409)
        ->assertJsonPath('error', 'not_revealed')
        ->assertJsonMissingPath('recipient');

    expect(json_encode($response->json()))->not->toContain($recipient->display);
    expect($ana->fresh()->revealed)->toBeFalse();
});

test('reveal_again without a claimed session guest is not_claimed', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    // No session at all.
    $this->postJson('/api/reveal_again', ['pin' => '1234'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    // A session pointing at a guest who never claimed their name.
    $this->withSession(['guest_id' => $ana->id]);

    $this->postJson('/api/reveal_again', ['pin' => '1234'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
});

// ----------------------------------------------------------------------
// Read-only, and the first reveal stays once-only
// ----------------------------------------------------------------------

test('reveal_again changes no guest state', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $snapshot = function () use ($ana) {
        $fresh = $ana->fresh();

        return [
            'claimed' => (bool) $fresh->claimed,
            'revealed' => (bool) $fresh->revealed,
            'recipient_id' => $fresh->recipient_id,
            'pin_hash' => $fresh->pin_hash,
        ];
    };

    $before = $snapshot();

    $this->postJson('/api/reveal_again', ['pin' => '1234'])->assertOk();

    expect($snapshot())->toBe($before);
});

test('the reveal itself stays once-only after reveal_again exists', function () {
    [, $guests] = revealAgainDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();
    $this->postJson('/api/reveal_again', ['pin' => '1234'])->assertOk();

    $recipient = Guest::find($ana->fresh()->recipient_id);

    $second = $this->postJson('/api/reveal');
    $second->assertStatus(409)
        ->assertJsonPath('error', 'already_claimed');
    expect(json_encode($second->json()))->not->toContain($recipient->display);
});
