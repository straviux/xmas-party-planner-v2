<?php

use App\Models\Guest;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

if (! function_exists('seedHostUser')) {
    function seedHostUser(): User
    {
        return User::create([
            'username' => 'host',
            'password' => Hash::make('secret-password'),
        ]);
    }
}

if (! function_exists('loginAsHost')) {
    function loginAsHost($test): void
    {
        $test->postJson('/api/host_login', [
            'username' => 'host',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonPath('ok', true);
    }
}

if (! function_exists('collectKeysRecursive')) {
    /** @return array<int, string> */
    function collectKeysRecursive(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = [...$keys, ...collectKeysRecursive($value)];
            }
        }

        return $keys;
    }
}

test('the seeder creates the host from env defaults and never resets the password', function () {
    $this->seed();

    $host = User::where('username', 'host')->first();
    expect($host)->not->toBeNull()
        ->and(Hash::check('changeme', $host->password))->toBeTrue();

    // Simulate the host changing their password, then re-seed.
    $host->update(['password' => Hash::make('my-new-password')]);
    $this->seed();

    expect(User::count())->toBe(1)
        ->and(Hash::check('my-new-password', User::first()->password))->toBeTrue();
});

test('host actions are forbidden when signed out and unknown actions 404', function () {
    $this->postJson('/api/host_status')
        ->assertForbidden()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'forbidden');

    $this->postJson('/api/host_guest_add', ['display' => 'Ana'])
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden');

    $this->postJson('/api/not_a_real_action')
        ->assertNotFound()
        ->assertJsonPath('error', 'unknown_action');
});

test('host login rejects bad credentials and accepts the seeded host', function () {
    seedHostUser();

    $this->postJson('/api/host_login', ['username' => 'host', 'password' => 'wrong'])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_credentials');

    loginAsHost($this);

    $this->postJson('/api/host_logout')->assertOk()->assertJsonPath('ok', true);

    $this->postJson('/api/host_status')->assertForbidden();
});

test('host status with no party is a normal empty state', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/host_status')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('exists', false)
        ->assertJsonPath('party', null)
        ->assertJsonPath('guests', [])
        ->assertJsonPath('counts.guests', 0);
});

test('guest writes before a party exists return not_set_up', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/host_guest_add', ['display' => 'Ana'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_set_up');
});

test('party setup, guest adds, and the automatic derangement', function () {
    seedHostUser();
    loginAsHost($this);

    $party = $this->postJson('/api/party_update', [
        'name' => 'Office Christmas Party',
        'date' => '2026-12-20',
        'budget' => '500 pesos',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('party.name', 'Office Christmas Party')
        ->json('party');

    expect($party['invite_code'])->not->toBeEmpty()
        ->and($party['budget'])->toBe('500 pesos')
        ->and($party['date'])->toBe('2026-12-20');

    foreach (['Ana', 'Ben', 'Cora'] as $display) {
        $this->postJson('/api/host_guest_add', ['display' => $display])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guest.display', $display)
            ->assertJsonPath('guest.pin_set', false)
            ->assertJsonPath('guest.status', 'awaiting');
    }

    $guests = Guest::orderBy('id')->get();
    expect($guests)->toHaveCount(3);
    foreach ($guests as $guest) {
        expect($guest->recipient_id)->not->toBeNull()
            ->and((int) $guest->recipient_id)->not->toBe($guest->id);
    }
    expect($guests->pluck('recipient_id')->unique())->toHaveCount(3);

    $this->postJson('/api/host_status')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('counts.guests', 3)
        ->assertJsonPath('counts.locked', 0)
        ->assertJsonPath('counts.awaiting', 3);
});

test('public list leaks no recipient, pin, or reserver keys anywhere', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/party_update', ['name' => 'Leak Check Party']);
    $this->postJson('/api/host_guest_add', ['display' => 'Ana', 'pin' => '1234']);
    $this->postJson('/api/host_guest_add', ['display' => 'Ben', 'nickname' => 'Benny', 'aliases' => ['B']]);

    $response = $this->postJson('/api/list')->assertOk()->assertJsonPath('ok', true);

    $keys = collectKeysRecursive($response->json());
    expect($keys)->not->toContain('recipient_id')
        ->not->toContain('pin_hash')
        ->not->toContain('reserved_by_id');

    expect($response->getContent())
        ->not->toContain('recipient_id')
        ->not->toContain('pin_hash');

    $response->assertJsonPath('party.name', 'Leak Check Party')
        ->assertJsonPath('guests.0.display', 'Ana')
        ->assertJsonPath('guests.0.needs_pin', false)
        ->assertJsonPath('guests.1.needs_pin', true)
        ->assertJsonPath('guests.1.aliases', ['B']);

    // The host view is blind too.
    $status = $this->postJson('/api/host_status')->assertOk();
    expect($status->getContent())
        ->not->toContain('recipient_id')
        ->not->toContain('pin_hash');
    $status->assertJsonPath('guests.0.pin_set', true)
        ->assertJsonPath('guests.1.pin_set', false);
});

test('public list with no party returns empty data, not an error', function () {
    $this->postJson('/api/list')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('party', null)
        ->assertJsonPath('guests', []);
});

test('editing a guest never forces a pin, and adding a pin works', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/party_update', ['name' => 'Pin Party']);
    $guest = $this->postJson('/api/host_guest_add', ['display' => 'Ana'])->json('guest');

    $this->postJson('/api/host_guest_update', ['id' => $guest['id'], 'nickname' => 'Annie'])
        ->assertOk()
        ->assertJsonPath('guest.nickname', 'Annie')
        ->assertJsonPath('guest.pin_set', false);

    expect(Guest::find($guest['id'])->pin_hash)->toBeNull();

    $this->postJson('/api/host_guest_update', ['id' => $guest['id'], 'pin' => '4321'])
        ->assertOk()
        ->assertJsonPath('guest.pin_set', true);

    expect(Hash::check('4321', Guest::find($guest['id'])->pin_hash))->toBeTrue();

    // Editing details alone must not touch the draw (recipients stay set).
    $this->postJson('/api/party_update', ['name' => 'Pin Party Renamed', 'budget' => '750'])
        ->assertOk()
        ->assertJsonPath('party.name', 'Pin Party Renamed');
    expect(Party::count())->toBe(1);
});

test('guest changes are blocked once a name is claimed', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/party_update', ['name' => 'Locked Party']);
    $this->postJson('/api/host_guest_add', ['display' => 'Ana']);
    $guest = $this->postJson('/api/host_guest_add', ['display' => 'Ben'])->json('guest');

    Guest::find($guest['id'])->update(['claimed' => true, 'claimed_at' => now()]);

    $message = 'Cannot change guests after names have been locked. Reset claims first.';

    $this->postJson('/api/host_guest_add', ['display' => 'Cora'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'guests_locked')
        ->assertJsonPath('message', $message);

    $this->postJson('/api/host_guest_update', ['id' => $guest['id'], 'nickname' => 'Benny'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'guests_locked');

    $this->postJson('/api/host_guest_remove', ['id' => $guest['id']])
        ->assertStatus(409)
        ->assertJsonPath('error', 'guests_locked');

    expect(Guest::count())->toBe(2);
});

test('removing a guest removes their wishlist and re-draws the rest', function () {
    seedHostUser();
    loginAsHost($this);

    $this->postJson('/api/party_update', ['name' => 'Removal Party']);
    $ana = $this->postJson('/api/host_guest_add', ['display' => 'Ana'])->json('guest');
    $this->postJson('/api/host_guest_add', ['display' => 'Ben']);
    $this->postJson('/api/host_guest_add', ['display' => 'Cora']);

    Guest::find($ana['id'])->wishlistItems()->create(['text' => 'A book', 'kind' => 'text']);
    expect(Guest::find($ana['id'])->wishlistItems()->count())->toBe(1);

    $this->postJson('/api/host_guest_remove', ['id' => $ana['id']])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Guest::count())->toBe(2);
    $remaining = Guest::all();
    foreach ($remaining as $guest) {
        // Two guests swap after the redraw.
        expect((int) $guest->recipient_id)->not->toBe($guest->id)
            ->and($guest->recipient_id)->not->toBeNull();
    }
});

test('invite check accepts the right code in any case and rejects wrong codes', function () {
    seedHostUser();
    loginAsHost($this);

    // No party yet: any code is incorrect.
    $this->postJson('/api/invite_check', ['code' => 'WHATEVER'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'invite_incorrect');

    $code = $this->postJson('/api/party_update', ['name' => 'Code Party'])->json('party.invite_code');

    $this->postJson('/api/invite_check', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->postJson('/api/invite_check', ['code' => strtolower($code)])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->postJson('/api/invite_check', ['code' => 'WRONGCODE'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'invite_incorrect');
});

test('invite regen changes only the code', function () {
    seedHostUser();
    loginAsHost($this);

    $oldCode = $this->postJson('/api/party_update', ['name' => 'Regen Party'])->json('party.invite_code');
    $this->postJson('/api/host_guest_add', ['display' => 'Ana']);
    $this->postJson('/api/host_guest_add', ['display' => 'Ben']);
    $recipientsBefore = Guest::orderBy('id')->pluck('recipient_id')->all();

    $newCode = $this->postJson('/api/host_invite_regen')
        ->assertOk()
        ->json('invite_code');

    expect($newCode)->not->toBe($oldCode)
        ->and(Guest::count())->toBe(2)
        ->and(Guest::orderBy('id')->pluck('recipient_id')->all())->toBe($recipientsBefore);
});
