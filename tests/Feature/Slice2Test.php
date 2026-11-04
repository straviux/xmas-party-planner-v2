<?php

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use App\Services\GuestEntryService;
use Illuminate\Support\Facades\Hash;

if (! function_exists('slice2Party')) {
    /**
     * A party with guests and a completed draw.
     *
     * Each guest definition: display, plus optional nickname, aliases,
     * pin (plain text, stored hashed), claimed, revealed.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function slice2Party(array $definitions): array
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

if (! function_exists('slice2DefaultParty')) {
    /** @return array{0: Party, 1: array<string, Guest>} */
    function slice2DefaultParty(): array
    {
        return slice2Party([
            ['display' => 'Ana Cruz', 'nickname' => 'Annie'],
            ['display' => 'Ben Reyes'],
            ['display' => 'Cora Diaz', 'aliases' => ['Coring']],
        ]);
    }
}

if (! function_exists('slice2KeysRecursive')) {
    /** @return array<int, string> */
    function slice2KeysRecursive(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = [...$keys, ...slice2KeysRecursive($value)];
            }
        }

        return $keys;
    }
}

// ----------------------------------------------------------------------
// Guest search (A13)
// ----------------------------------------------------------------------

test('search finds guests by nickname, alias, and accent-insensitive display name', function () {
    [$party] = slice2Party([
        ['display' => 'José Rizal', 'nickname' => 'Pepe', 'aliases' => ['Joe']],
        ['display' => 'Maria Clara'],
        ['display' => 'Andres Bonifacio'],
    ]);

    $search = app(GuestEntryService::class);

    expect($search->search($party, 'pepe')->pluck('display')->all())->toContain('José Rizal')
        ->and($search->search($party, 'joe')->pluck('display')->all())->toContain('José Rizal')
        ->and($search->search($party, 'jose')->pluck('display')->all())->toContain('José Rizal')
        ->and($search->search($party, 'José')->pluck('display')->all())->toContain('José Rizal');
});

test('search tolerates case and extra spaces, allows one-letter typos, and handles empty and unmatched queries', function () {
    [$party] = slice2Party([
        ['display' => 'José Rizal', 'nickname' => 'Pepe', 'aliases' => ['Joe']],
        ['display' => 'Maria Clara'],
        ['display' => 'Andres Bonifacio'],
    ]);

    $search = app(GuestEntryService::class);

    expect($search->search($party, '  MARIA   CLARA ')->pluck('display')->all())->toBe(['Maria Clara'])
        ->and($search->search($party, 'andrs')->pluck('display')->all())->toBe(['Andres Bonifacio'])
        ->and($search->search($party, 'zzzz')->all())->toBe([])
        ->and($search->search($party, '')->count())->toBe(3)
        ->and($search->search($party, '')->pluck('display')->all())
        ->toBe(['Andres Bonifacio', 'José Rizal', 'Maria Clara']);
});

// ----------------------------------------------------------------------
// Entry: create a PIN
// ----------------------------------------------------------------------

test('enter with a new PIN locks the name, hashes the PIN, and returns no recipient data', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];
    $recipientBefore = $ana->fresh()->recipient_id;

    $response = $this->postJson('/api/enter', [
        'guest_id' => $ana->id,
        'pin' => '1234',
        'pin_confirmation' => '1234',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('claimed', true)
        ->assertJsonPath('revealed', false);

    $keys = slice2KeysRecursive($response->json());
    expect($keys)->not->toContain('recipient')
        ->and($keys)->not->toContain('recipient_id')
        ->and($keys)->not->toContain('pin_hash');

    $ana = $ana->fresh();
    expect($ana->claimed)->toBeTrue()
        ->and($ana->claimed_at)->not->toBeNull()
        ->and($ana->pin_hash)->not->toBeNull()
        ->and($ana->pin_hash)->not->toBe('1234')
        ->and(Hash::check('1234', $ana->pin_hash))->toBeTrue()
        ->and($ana->recipient_id)->toBe($recipientBefore);

    $response->assertSessionHas('guest_id', $ana->id);
    $response->assertSessionHas('pin_verified', true);
});

test('enter create-PIN errors never lock the guest', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', ['guest_id' => $ana->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_required_create');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '123', 'pin_confirmation' => '123',
    ])->assertStatus(422)->assertJsonPath('error', 'pin_must_be_4_digits');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1235',
    ])->assertStatus(422)->assertJsonPath('error', 'pin_mismatch');

    $ana = $ana->fresh();
    expect($ana->claimed)->toBeFalse()
        ->and($ana->pin_hash)->toBeNull();
});

test('enter without a party is not_set_up and an unknown guest is guest_not_found', function () {
    $this->postJson('/api/enter', ['guest_id' => 1, 'pin' => '1234', 'pin_confirmation' => '1234'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_set_up');

    slice2DefaultParty();

    $this->postJson('/api/enter', ['guest_id' => 9999, 'pin' => '1234', 'pin_confirmation' => '1234'])
        ->assertStatus(404)
        ->assertJsonPath('error', 'guest_not_found');
});

// ----------------------------------------------------------------------
// Entry: existing PIN
// ----------------------------------------------------------------------

test('enter with a host-set PIN verifies it before locking', function () {
    [, $guests] = slice2Party([
        ['display' => 'Ana Cruz'],
        ['display' => 'Ben Reyes', 'pin' => '4321'],
        ['display' => 'Cora Diaz'],
    ]);
    $ben = $guests['Ben Reyes'];

    $this->postJson('/api/enter', ['guest_id' => $ben->id, 'pin' => '12'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_must_be_4_digits');

    $this->postJson('/api/enter', ['guest_id' => $ben->id, 'pin' => '0000'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_incorrect');

    expect($ben->fresh()->claimed)->toBeFalse();

    $this->postJson('/api/enter', ['guest_id' => $ben->id, 'pin' => '4321'])
        ->assertOk()
        ->assertJsonPath('claimed', true);

    expect($ben->fresh()->claimed)->toBeTrue();
});

test('a second entry on a locked, revealed name still needs the right PIN', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();
    expect($ana->fresh()->revealed)->toBeTrue();

    // Wrong PIN: an error, and nothing about the state changes.
    $this->postJson('/api/enter', ['guest_id' => $ana->id, 'pin' => '9999'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_incorrect');

    // Correct PIN: welcome back, already revealed.
    $this->postJson('/api/enter', ['guest_id' => $ana->id, 'pin' => '1234'])
        ->assertOk()
        ->assertJsonPath('claimed', true)
        ->assertJsonPath('revealed', true);
});

test('a claimed guest with no PIN (host reset or legacy) creates one on return', function () {
    [, $guests] = slice2DefaultParty();
    $cora = $guests['Cora Diaz'];
    $cora->update(['claimed' => true, 'claimed_at' => now(), 'pin_hash' => null]);

    $this->postJson('/api/enter', [
        'guest_id' => $cora->id, 'pin' => '5555', 'pin_confirmation' => '5555',
    ])->assertOk()->assertJsonPath('claimed', true);

    $cora = $cora->fresh();
    expect($cora->claimed)->toBeTrue()
        ->and(Hash::check('5555', $cora->pin_hash))->toBeTrue();
});

// ----------------------------------------------------------------------
// Reveal
// ----------------------------------------------------------------------

test('reveal returns the recipient and budget exactly once', function () {
    [$party, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $recipient = Guest::find($ana->fresh()->recipient_id);

    $response = $this->postJson('/api/reveal');
    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('recipient.id', $recipient->id)
        // The recipient is carried by secret name only (null here: the
        // fixture guests never picked one). Their real name must not
        // appear anywhere in the response.
        ->assertJsonPath('recipient.secret_name', null)
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname')
        ->assertJsonPath('budget', $party->budget);

    $revealJson = json_encode($response->json());
    expect($revealJson)->not->toContain($recipient->display);
    if ($recipient->nickname !== null) {
        expect($revealJson)->not->toContain($recipient->nickname);
    }

    expect($ana->fresh()->revealed)->toBeTrue();

    $second = $this->postJson('/api/reveal');
    $second->assertStatus(409)
        ->assertJsonPath('error', 'already_claimed');
    expect(json_encode($second->json()))->not->toContain($recipient->display);
});

test('reveal without entry is not_claimed and without a verified PIN is pin_incorrect', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/reveal')
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $this->withSession(['pin_verified' => false]);

    $this->postJson('/api/reveal')
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_incorrect');

    expect($ana->fresh()->revealed)->toBeFalse();
});

// ----------------------------------------------------------------------
// guest_set_pin / guest_verify_pin / guest_logout
// ----------------------------------------------------------------------

test('guest_set_pin sets a PIN only when none exists, and guest_verify_pin checks it', function () {
    [, $guests] = slice2DefaultParty();
    $cora = $guests['Cora Diaz'];
    $cora->update(['claimed' => true, 'claimed_at' => now(), 'pin_hash' => null]);

    // No session guest yet.
    $this->postJson('/api/guest_set_pin', ['pin' => '7777', 'pin_confirmation' => '7777'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
    $this->postJson('/api/guest_verify_pin', ['pin' => '7777'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    $this->withSession(['guest_id' => $cora->id, 'pin_verified' => true]);

    $this->postJson('/api/guest_set_pin', ['pin' => '7777', 'pin_confirmation' => '7777'])
        ->assertOk()
        ->assertJsonPath('ok', true);
    expect(Hash::check('7777', $cora->fresh()->pin_hash))->toBeTrue();

    $this->postJson('/api/guest_set_pin', ['pin' => '8888', 'pin_confirmation' => '8888'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'pin_already_set');

    $this->postJson('/api/guest_verify_pin', ['pin' => '77'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_must_be_4_digits');
    $this->postJson('/api/guest_verify_pin', ['pin' => '0000'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'pin_incorrect');
    $this->postJson('/api/guest_verify_pin', ['pin' => '7777'])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('guest_logout clears the guest session keys but keeps the lock', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $response = $this->postJson('/api/guest_logout');
    $response->assertOk()->assertJsonPath('ok', true);
    $response->assertSessionMissing('guest_id');
    $response->assertSessionMissing('pin_verified');

    expect($ana->fresh()->claimed)->toBeTrue();

    $this->get('/guest')->assertRedirect('/enter');
});

// ----------------------------------------------------------------------
// Page routing
// ----------------------------------------------------------------------

test('guest pages redirect to entry when there is no guest session', function () {
    slice2DefaultParty();

    $this->get('/guest')->assertRedirect('/enter');
    $this->get('/reveal')->assertRedirect('/enter');
    $this->get('/guest/gifts')->assertRedirect('/enter');
    $this->get('/enter')->assertOk();
});

test('an entered guest who has not revealed is sent to the reveal page', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();

    $this->get('/guest')->assertRedirect('/reveal');
    $this->get('/enter')->assertRedirect('/reveal');
    $this->get('/guest/gifts')->assertRedirect('/reveal');
    $this->get('/guest/wishlists')->assertRedirect('/reveal');
    $this->get('/guest/vote')->assertRedirect('/reveal');
    $this->get('/reveal')->assertOk();
});

test('a revealed guest goes to guest home and never back to reveal', function () {
    [, $guests] = slice2DefaultParty();
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234', 'pin_confirmation' => '1234',
    ])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $this->get('/reveal')->assertRedirect('/guest');
    $this->get('/enter')->assertRedirect('/guest');
    $this->get('/guest')->assertOk();
    $this->get('/guest/gifts')->assertOk();
    $this->get('/guest/wishlists')->assertOk();
    $this->get('/guest/vote')->assertOk();
});

test('the join link lands on entry with the code applied', function () {
    slice2DefaultParty();

    // A matching code (any letter case) redirects to entry carrying the
    // code in the URL, so the entry page auto-checks it even when the
    // session cookie does not survive the trip through a scanner app.
    $this->get('/join/testcode')->assertRedirect('/enter?code=TESTCODE');

    // A wrong code still lands on entry, without a code to auto-check.
    $this->get('/join/WRONG99')->assertRedirect('/enter');
});
