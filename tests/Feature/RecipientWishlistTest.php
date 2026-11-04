<?php

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use Illuminate\Support\Facades\Hash;

if (! function_exists('recipientWishlistParty')) {
    /**
     * A party with guests and a completed draw.
     *
     * Each guest definition: display, pin (plain text, stored hashed),
     * plus optional secret_name.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function recipientWishlistParty(array $definitions): array
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
                'secret_name' => $definition['secret_name'] ?? null,
                'pin_hash' => Hash::make($definition['pin']),
            ]);
        }

        (new DrawService)->redraw($party);

        return [$party, $guests];
    }
}

if (! function_exists('recipientWishlistDefaultParty')) {
    /** @return array{0: Party, 1: array<string, Guest>} */
    function recipientWishlistDefaultParty(): array
    {
        return recipientWishlistParty([
            ['display' => 'Ana Cruz', 'pin' => '1111', 'secret_name' => 'FrostByte'],
            ['display' => 'Ben Reyes', 'pin' => '2222', 'secret_name' => 'Captain Pasko'],
            ['display' => 'Cora Diaz', 'pin' => '3333', 'secret_name' => 'TinselFox'],
        ]);
    }
}

if (! function_exists('recipientWishAdd')) {
    /** Put an item directly on a guest's list and return its title. */
    function recipientWishAdd(Guest $guest, string $text): string
    {
        $guest->wishlistItems()->create(['text' => $text, 'kind' => 'text']);

        return $text;
    }
}

if (! function_exists('giverOf')) {
    /** The guest whose draw recipient is the given guest. */
    function giverOf(array $guests, Guest $recipient): Guest
    {
        foreach ($guests as $guest) {
            if ((int) $guest->fresh()->recipient_id === (int) $recipient->id) {
                return $guest;
            }
        }

        throw new RuntimeException('No giver found for recipient.');
    }
}

// ----------------------------------------------------------------------
// Happy path
// ----------------------------------------------------------------------

test('guest_recipient_wishlist returns the codename and only the recipient items, newest first', function () {
    [$party, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];
    $recipient = Guest::find($ana->fresh()->recipient_id);
    $other = collect($guests)->first(fn (Guest $guest) => (int) $guest->id !== (int) $ana->id
        && (int) $guest->id !== (int) $recipient->id);

    recipientWishAdd($recipient, 'Recipient Wish One');
    recipientWishAdd($recipient, 'Recipient Wish Two');
    recipientWishAdd($other, 'Someone Else Wish');
    recipientWishAdd($ana, 'My Own Wish');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1111', 'pin_confirmation' => '1111',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $response = $this->postJson('/api/guest_recipient_wishlist');
    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('recipient.id', $recipient->id)
        ->assertJsonPath('recipient.secret_name', $recipient->secret_name)
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname')
        ->assertJsonPath('budget', $party->budget)
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.text', 'Recipient Wish Two')
        ->assertJsonPath('items.1.text', 'Recipient Wish One');

    $json = json_encode($response->json());
    expect($json)->not->toContain('Someone Else Wish')
        ->not->toContain('My Own Wish')
        // The recipient is a codename here, never a real name.
        ->not->toContain($recipient->display);

    // Repeatable: asking again returns the same list.
    $this->postJson('/api/guest_recipient_wishlist')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('recipient.secret_name', $recipient->secret_name);
});

test('guest_recipient_wishlist with an unset recipient codename returns null and still returns the items', function () {
    [, $guests] = recipientWishlistParty([
        ['display' => 'Ana Cruz', 'pin' => '1111', 'secret_name' => 'FrostByte'],
        ['display' => 'Ben Reyes', 'pin' => '2222'],
        ['display' => 'Cora Diaz', 'pin' => '3333', 'secret_name' => 'TinselFox'],
    ]);
    $ben = $guests['Ben Reyes'];
    $giver = giverOf($guests, $ben);
    $pin = ['Ana Cruz' => '1111', 'Ben Reyes' => '2222', 'Cora Diaz' => '3333'][$giver->display];

    recipientWishAdd($ben, 'Quiet Wish');

    $this->postJson('/api/enter', [
        'guest_id' => $giver->id, 'pin' => $pin, 'pin_confirmation' => $pin,
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $response = $this->postJson('/api/guest_recipient_wishlist');
    $response->assertOk()
        ->assertJsonPath('recipient.id', $ben->id)
        ->assertJsonPath('recipient.secret_name', null)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.text', 'Quiet Wish');

    expect(json_encode($response->json()))->not->toContain($ben->display);
});

// ----------------------------------------------------------------------
// State gates carry no recipient data
// ----------------------------------------------------------------------

test('guest_recipient_wishlist before the first reveal is not_revealed and leaks nothing', function () {
    [, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];
    $recipient = Guest::find($ana->fresh()->recipient_id);

    recipientWishAdd($recipient, 'Hidden Wish Title');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1111', 'pin_confirmation' => '1111',
    ])->assertOk();

    $response = $this->postJson('/api/guest_recipient_wishlist');
    $response->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'not_revealed')
        ->assertJsonMissingPath('recipient')
        ->assertJsonMissingPath('items');

    $json = json_encode($response->json());
    expect($json)->not->toContain($recipient->secret_name)
        ->not->toContain($recipient->display)
        ->not->toContain('Hidden Wish Title');
    expect($ana->fresh()->revealed)->toBeFalse();
});

test('guest_recipient_wishlist for a revealed guest with no recipient is not_revealed', function () {
    [, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1111', 'pin_confirmation' => '1111',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    // Simulate the mapping vanishing (e.g. the recipient was removed).
    $ana->fresh()->update(['recipient_id' => null]);

    $this->postJson('/api/guest_recipient_wishlist')
        ->assertStatus(409)
        ->assertJsonPath('error', 'not_revealed')
        ->assertJsonMissingPath('recipient')
        ->assertJsonMissingPath('items');
});

test('guest_recipient_wishlist without a claimed session guest is not_claimed', function () {
    [, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];
    $recipient = Guest::find($ana->fresh()->recipient_id);

    recipientWishAdd($recipient, 'Hidden Wish Title');

    // No session at all.
    $response = $this->postJson('/api/guest_recipient_wishlist');
    $response->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed')
        ->assertJsonMissingPath('recipient')
        ->assertJsonMissingPath('items');

    $json = json_encode($response->json());
    expect($json)->not->toContain($recipient->secret_name)
        ->not->toContain('Hidden Wish Title');

    // A session pointing at a guest who never claimed their name.
    $this->withSession(['guest_id' => $ana->id]);

    $this->postJson('/api/guest_recipient_wishlist')
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
});

// ----------------------------------------------------------------------
// Each guest only ever gets their own recipient's list
// ----------------------------------------------------------------------

test('guest_recipient_wishlist gives each guest their own recipient list', function () {
    [, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    $anaRecipient = Guest::find($ana->fresh()->recipient_id);
    $benRecipient = Guest::find($ben->fresh()->recipient_id);

    // The draw is a permutation, so the two recipients are different guests.
    expect($anaRecipient->id)->not->toBe($benRecipient->id);

    recipientWishAdd($anaRecipient, 'For Ana Recipient');
    recipientWishAdd($benRecipient, 'For Ben Recipient');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1111', 'pin_confirmation' => '1111',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $anaResponse = $this->postJson('/api/guest_recipient_wishlist');
    $anaResponse->assertOk()
        ->assertJsonPath('recipient.secret_name', $anaRecipient->secret_name)
        ->assertJsonPath('items.0.text', 'For Ana Recipient');
    expect(json_encode($anaResponse->json()))->not->toContain('For Ben Recipient');

    $this->postJson('/api/enter', [
        'guest_id' => $ben->id, 'pin' => '2222', 'pin_confirmation' => '2222',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $benResponse = $this->postJson('/api/guest_recipient_wishlist');
    $benResponse->assertOk()
        ->assertJsonPath('recipient.secret_name', $benRecipient->secret_name)
        ->assertJsonPath('items.0.text', 'For Ben Recipient');
    expect(json_encode($benResponse->json()))->not->toContain('For Ana Recipient');
});

// ----------------------------------------------------------------------
// Read-only
// ----------------------------------------------------------------------

test('guest_recipient_wishlist changes no guest state', function () {
    [, $guests] = recipientWishlistDefaultParty();
    $ana = $guests['Ana Cruz'];
    $recipient = Guest::find($ana->fresh()->recipient_id);

    recipientWishAdd($recipient, 'Recipient Wish One');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1111', 'pin_confirmation' => '1111',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $snapshot = function () use ($guests) {
        return collect($guests)->map(fn (Guest $guest) => [
            'claimed' => (bool) $guest->fresh()->claimed,
            'revealed' => (bool) $guest->fresh()->revealed,
            'recipient_id' => $guest->fresh()->recipient_id,
            'pin_hash' => $guest->fresh()->pin_hash,
        ])->all();
    };

    $before = $snapshot();

    $this->postJson('/api/guest_recipient_wishlist')->assertOk();

    expect($snapshot())->toBe($before);
});
