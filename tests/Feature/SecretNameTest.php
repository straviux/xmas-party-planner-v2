<?php

use App\Models\Guest;
use App\Models\Party;
use App\Models\User;
use App\Services\DrawService;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

if (! function_exists('secretNameParty')) {
    /**
     * A party with guests and a completed draw.
     *
     * Each guest definition: display, plus optional nickname, aliases,
     * pin (plain text, stored hashed), claimed, revealed.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function secretNameParty(array $definitions): array
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

if (! function_exists('secretNameDefaultParty')) {
    /** @return array{0: Party, 1: array<string, Guest>} */
    function secretNameDefaultParty(): array
    {
        return secretNameParty([
            ['display' => 'Ana Cruz', 'nickname' => 'Annie'],
            ['display' => 'Ben Reyes', 'nickname' => 'Benny'],
            ['display' => 'Cora Diaz', 'nickname' => 'Coring'],
        ]);
    }
}

if (! function_exists('secretNameEnter')) {
    /** Enter as a guest through the real action, creating PIN 1234 when none is set. */
    function secretNameEnter(object $test, Guest $guest, string $pin = '1234'): void
    {
        $payload = ['guest_id' => $guest->id, 'pin' => $pin];
        if (empty($guest->fresh()->pin_hash)) {
            $payload['pin_confirmation'] = $pin;
        }

        $test->postJson('/api/enter', $payload)->assertOk()->assertJsonPath('ok', true);
    }
}

if (! function_exists('secretNameLoginHost')) {
    /** Create the host user (if needed) and sign in through the real action. */
    function secretNameLoginHost(object $test, string $password = 'secret-password'): void
    {
        User::firstOrCreate(
            ['username' => 'host'],
            ['password' => Hash::make($password)],
        );

        $test->postJson('/api/host_login', [
            'username' => 'host',
            'password' => $password,
        ])->assertOk();
    }
}

// ----------------------------------------------------------------------
// Setting a secret name
// ----------------------------------------------------------------------

test('a claimed guest can set a secret name, stored trimmed with its case preserved', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    $this->postJson('/api/guest_set_secret_name', ['secret_name' => '  Elf One  '])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('secret_name', 'Elf One');

    expect($ana->fresh()->secret_name)->toBe('Elf One');
});

test('secret names must be 2 to 30 characters after trimming', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    foreach (['', '   ', 'A', str_repeat('a', 31)] as $invalid) {
        $this->postJson('/api/guest_set_secret_name', ['secret_name' => $invalid])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'secret_name_invalid');
    }

    // A missing key is invalid too, and nothing was stored meanwhile.
    $this->postJson('/api/guest_set_secret_name', [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'secret_name_invalid');

    expect($ana->fresh()->secret_name)->toBeNull();

    // Both boundaries are accepted.
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Ab'])
        ->assertOk()
        ->assertJsonPath('secret_name', 'Ab');

    $thirty = str_repeat('b', 30);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => $thirty])
        ->assertOk()
        ->assertJsonPath('secret_name', $thirty);

    expect($ana->fresh()->secret_name)->toBe($thirty);
});

test('a secret name another guest already uses is taken, compared case-insensitively', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameEnter($this, $ben);

    foreach (['elf one', 'ELF ONE', '  Elf One  '] as $attempt) {
        $this->postJson('/api/guest_set_secret_name', ['secret_name' => $attempt])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'secret_name_taken');
    }

    expect($ben->fresh()->secret_name)->toBeNull()
        ->and($ana->fresh()->secret_name)->toBe('Elf One');
});

test('re-setting your own secret name in any letter case is fine', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])->assertOk();

    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ELF ONE'])
        ->assertOk()
        ->assertJsonPath('secret_name', 'ELF ONE');

    expect($ana->fresh()->secret_name)->toBe('ELF ONE');
});

test('changing your secret name frees the old one for another guest', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])->assertOk();
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf Two'])->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameEnter($this, $ben);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])
        ->assertOk()
        ->assertJsonPath('secret_name', 'Elf One');

    expect($ana->fresh()->secret_name)->toBe('Elf Two')
        ->and($ben->fresh()->secret_name)->toBe('Elf One');
});

test('setting a secret name needs a claimed session guest', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    // No session at all.
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    // A session pointing at a guest who never claimed their name.
    $this->withSession(['guest_id' => $ana->id]);

    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Elf One'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    expect($ana->fresh()->secret_name)->toBeNull();
});

// ----------------------------------------------------------------------
// Public payloads: the flag ships, the value never does
// ----------------------------------------------------------------------

test('public payloads carry the has_secret_name flag and never the secret value', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    $listGuest = function () use ($ana) {
        $response = $this->postJson('/api/list')->assertOk();

        return collect($response->json('guests'))->firstWhere('id', $ana->id);
    };

    expect($listGuest()['has_secret_name'])->toBeFalse();

    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])
        ->assertOk()
        ->assertJsonPath('secret_name', 'ReindeerGames');

    $after = $listGuest();
    expect($after['has_secret_name'])->toBeTrue()
        ->and($after)->not->toHaveKey('secret_name');

    // The value is absent from the whole list payload, and from a fresh
    // enter response issued after the value exists.
    $listJson = json_encode($this->postJson('/api/list')->json());
    expect($listJson)->not->toContain('ReindeerGames');

    $this->postJson('/api/guest_logout')->assertOk();
    $enterJson = json_encode($this->postJson('/api/enter', [
        'guest_id' => $ana->id, 'pin' => '1234',
    ])->assertOk()->json());
    expect($enterJson)->not->toContain('ReindeerGames');
});

// ----------------------------------------------------------------------
// Reveal: the secret name is what the recipient is shown as
// ----------------------------------------------------------------------

test('reveal returns the recipient secret name and never their real name', function () {
    [$party, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    $recipient = Guest::find($ana->fresh()->recipient_id);
    expect($recipient)->not->toBeNull()
        ->and($recipient->nickname)->not->toBeNull();

    // The recipient claims their name and picks a codename first.
    secretNameEnter($this, $recipient);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Comet Rider'])
        ->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameEnter($this, $ana);

    $response = $this->postJson('/api/reveal');
    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('recipient.id', $recipient->id)
        ->assertJsonPath('recipient.secret_name', 'Comet Rider')
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname')
        ->assertJsonPath('budget', $party->budget);

    $json = json_encode($response->json());
    expect($json)->not->toContain($recipient->display)
        ->and($json)->not->toContain($recipient->nickname);

    expect($ana->fresh()->revealed)->toBeTrue();
});

test('reveal still works when the recipient has no secret name yet, returning null', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    $this->postJson('/api/reveal')
        ->assertOk()
        ->assertJsonPath('recipient.id', $ana->fresh()->recipient_id)
        ->assertJsonPath('recipient.secret_name', null)
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname');

    expect($ana->fresh()->revealed)->toBeTrue();
});

test('reveal_again mirrors the secret name shape', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    $recipient = Guest::find($ana->fresh()->recipient_id);

    secretNameEnter($this, $recipient);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Comet Rider'])
        ->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameEnter($this, $ana);
    $this->postJson('/api/reveal')->assertOk();

    $response = $this->postJson('/api/reveal_again', ['pin' => '1234']);
    $response->assertOk()
        ->assertJsonPath('recipient.id', $recipient->id)
        ->assertJsonPath('recipient.secret_name', 'Comet Rider')
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname');

    $json = json_encode($response->json());
    expect($json)->not->toContain($recipient->display)
        ->and($json)->not->toContain($recipient->nickname);
});

// ----------------------------------------------------------------------
// Host visibility and the guest home prop
// ----------------------------------------------------------------------

test('the host sees secret names and counts them while the public list hides the values', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];
    $cora = $guests['Cora Diaz'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameEnter($this, $ben);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'Snow Fox'])->assertOk();
    $this->postJson('/api/guest_logout')->assertOk();

    secretNameLoginHost($this);

    $status = $this->postJson('/api/host_status')->assertOk();
    $status->assertJsonPath('counts.secret_names_set', 2)
        ->assertJsonPath('counts.guests', 3);

    $shapes = collect($status->json('guests'))->keyBy('id');
    expect($shapes[$ana->id]['secret_name'])->toBe('ReindeerGames')
        ->and($shapes[$ben->id]['secret_name'])->toBe('Snow Fox')
        ->and($shapes[$cora->id]['secret_name'])->toBeNull()
        ->and($shapes[$ana->id]['has_secret_name'])->toBeTrue()
        ->and($shapes[$cora->id]['has_secret_name'])->toBeFalse();

    // The public list, on the same data, contains neither value anywhere.
    $listJson = json_encode($this->postJson('/api/list')->assertOk()->json());
    expect($listJson)->not->toContain('ReindeerGames')
        ->and($listJson)->not->toContain('Snow Fox');
});

test('guest home receives only the own-secret-name flag, never the value', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])->assertOk();
    $this->postJson('/api/reveal')->assertOk();

    $response = $this->get('/guest');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Guest/Home')
        ->where('ownHasSecretName', true)
        ->missing('ownSecretName')
        ->where('guest.has_secret_name', true));

    // The value appears nowhere in the whole page response: not in the
    // Inertia props, not anywhere in the rendered shell.
    expect($response->getContent())->not->toContain('ReindeerGames');
});

test('guest home flag is false when the guest has no secret name yet', function () {
    [, $guests] = secretNameDefaultParty();
    $ben = $guests['Ben Reyes'];

    secretNameEnter($this, $ben);
    $this->postJson('/api/reveal')->assertOk();

    $this->get('/guest')->assertInertia(fn (Assert $page) => $page
        ->component('Guest/Home')
        ->where('ownHasSecretName', false)
        ->missing('ownSecretName')
        ->where('guest.has_secret_name', false));
});

// ----------------------------------------------------------------------
// Viewing your own secret name: PIN-gated, the value never ships otherwise
// ----------------------------------------------------------------------

test('viewing your own secret name with the correct PIN returns the value', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])->assertOk();

    $this->postJson('/api/guest_secret_name_view', ['pin' => '1234'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('secret_name', 'ReindeerGames');
});

test('viewing your own secret name with a wrong PIN is pin_incorrect and leaks nothing', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])->assertOk();

    $response = $this->postJson('/api/guest_secret_name_view', ['pin' => '9999']);
    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'pin_incorrect')
        ->assertJsonMissingPath('secret_name');

    expect(json_encode($response->json()))->not->toContain('ReindeerGames');
});

test('viewing your own secret name before picking one is secret_name_unset', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);

    $this->postJson('/api/guest_secret_name_view', ['pin' => '1234'])
        ->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'secret_name_unset')
        ->assertJsonMissingPath('secret_name');

    // The unset gate runs before the PIN: even a malformed PIN reports unset.
    $this->postJson('/api/guest_secret_name_view', ['pin' => '12'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'secret_name_unset');
});

test('viewing your own secret name needs a claimed session guest', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];
    $ana->update(['secret_name' => 'ReindeerGames']);

    // No session at all.
    $response = $this->postJson('/api/guest_secret_name_view', ['pin' => '1234']);
    $response->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
    expect(json_encode($response->json()))->not->toContain('ReindeerGames');

    // A session pointing at a guest who never claimed their name.
    $this->withSession(['guest_id' => $ana->id]);

    $response = $this->postJson('/api/guest_secret_name_view', ['pin' => '1234']);
    $response->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
    expect(json_encode($response->json()))->not->toContain('ReindeerGames');
});

test('viewing your own secret name with a missing or malformed PIN is pin_must_be_4_digits', function () {
    [, $guests] = secretNameDefaultParty();
    $ana = $guests['Ana Cruz'];

    secretNameEnter($this, $ana);
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'ReindeerGames'])->assertOk();

    foreach ([[], ['pin' => ''], ['pin' => '12'], ['pin' => '12345'], ['pin' => 'abcd']] as $payload) {
        $response = $this->postJson('/api/guest_secret_name_view', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('error', 'pin_must_be_4_digits')
            ->assertJsonMissingPath('secret_name');
        expect(json_encode($response->json()))->not->toContain('ReindeerGames');
    }
});

test('viewing your own secret name with no PIN hash is pin_required_create', function () {
    [, $guests] = secretNameDefaultParty();
    $cora = $guests['Cora Diaz'];
    $cora->update([
        'claimed' => true, 'claimed_at' => now(), 'pin_hash' => null,
        'secret_name' => 'Silent Owl',
    ]);

    $this->withSession(['guest_id' => $cora->id]);

    $response = $this->postJson('/api/guest_secret_name_view', ['pin' => '1234']);
    $response->assertStatus(422)
        ->assertJsonPath('error', 'pin_required_create')
        ->assertJsonMissingPath('secret_name');

    expect(json_encode($response->json()))->not->toContain('Silent Owl');
});
