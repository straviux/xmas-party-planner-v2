<?php

use App\Models\Guest;
use App\Models\Party;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Vote;
use App\Models\WishlistItem;
use App\Services\DrawService;
use Illuminate\Support\Facades\Hash;

if (! function_exists('slice5LoginHost')) {
    /** Create the host user (if needed) and sign in through the real action. */
    function slice5LoginHost(object $test, string $password = 'secret-password'): void
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

if (! function_exists('slice5Enter')) {
    /** Enter as a guest through the real action, creating PIN 1234 when none is set. */
    function slice5Enter(object $test, Guest $guest, string $pin = '1234'): void
    {
        $payload = ['guest_id' => $guest->id, 'pin' => $pin];
        if (empty($guest->fresh()->pin_hash)) {
            $payload['pin_confirmation'] = $pin;
        }

        $test->postJson('/api/enter', $payload)->assertOk()->assertJsonPath('ok', true);
    }
}

if (! function_exists('slice5Fixture')) {
    /**
     * One rich party, built entirely through the real actions:
     *  - host creates the party and adds Ana, Ben, and Cora (draw runs);
     *  - all three enter with PIN 1234, and Ana reveals her recipient;
     *  - Ana adds two wishlist items and Ben reserves the first one;
     *  - a poll exists, with Ana voting option 1 and Ben option 2.
     *
     * @return array{party: Party, guests: array<string, Guest>, items: array<int, int>, poll: array}
     */
    function slice5Fixture(object $test): array
    {
        slice5LoginHost($test);

        $test->postJson('/api/party_update', [
            'name' => 'Office Christmas Party',
            'budget' => '500 pesos',
            'date' => '2026-12-20',
        ])->assertOk();

        $guests = [];
        foreach (['Ana Cruz', 'Ben Reyes', 'Cora Diaz'] as $display) {
            $response = $test->postJson('/api/host_guest_add', ['display' => $display]);
            $response->assertOk();
            $guests[$display] = Guest::findOrFail($response->json('guest.id'));
        }

        foreach ($guests as $guest) {
            slice5Enter($test, $guest);
        }

        // Ana reveals (the one response that ever shows a recipient).
        slice5Enter($test, $guests['Ana Cruz']);
        $test->postJson('/api/reveal')->assertOk()->assertJsonPath('ok', true);

        // Ana's wishlist: two items.
        slice5Enter($test, $guests['Ana Cruz']);
        $items = [];
        foreach (['Blue scarf', 'Photo mug'] as $text) {
            $response = $test->postJson('/api/wishlist_add', ['text' => $text]);
            $response->assertOk();
            $items[] = (int) $response->json('item.id');
        }

        // Ben reserves Ana's first item.
        slice5Enter($test, $guests['Ben Reyes']);
        $test->postJson('/api/wishlist_reserve', ['id' => $items[0]])
            ->assertOk()->assertJsonPath('reserved', true);

        // One poll; Ana votes the first option, Ben the second.
        $poll = $test->postJson('/api/host_poll_create', [
            'question' => 'Best Christmas theme?',
            'options' => ['Classic Red', 'Winter Blue', 'Gold'],
        ])->assertOk()->json('poll');

        slice5Enter($test, $guests['Ana Cruz']);
        $test->postJson('/api/poll_vote', [
            'poll_id' => $poll['id'],
            'option_id' => $poll['options'][0]['id'],
        ])->assertOk();

        slice5Enter($test, $guests['Ben Reyes']);
        $test->postJson('/api/poll_vote', [
            'poll_id' => $poll['id'],
            'option_id' => $poll['options'][1]['id'],
        ])->assertOk();

        return [
            'party' => Party::firstOrFail(),
            'guests' => $guests,
            'items' => $items,
            'poll' => $poll,
        ];
    }
}

if (! function_exists('slice5Mapping')) {
    /** @return array<int, int|null> guest id => recipient_id, read straight from the DB */
    function slice5Mapping(): array
    {
        return Guest::orderBy('id')->pluck('recipient_id', 'id')
            ->map(fn ($recipient) => $recipient === null ? null : (int) $recipient)
            ->all();
    }
}

if (! function_exists('slice5PinHashes')) {
    /** @return array<int, string|null> guest id => pin_hash, read straight from the DB */
    function slice5PinHashes(): array
    {
        return Guest::orderBy('id')->pluck('pin_hash', 'id')->all();
    }
}

// ----------------------------------------------------------------------
// Reset one
// ----------------------------------------------------------------------

test('reset_one clears only the claim and the reveal for that guest', function () {
    ['guests' => $guests] = slice5Fixture($this);
    $ana = $guests['Ana Cruz'];

    $before = $ana->fresh();
    expect($before->claimed)->toBeTrue()
        ->and($before->revealed)->toBeTrue();

    $response = $this->postJson('/api/reset_one', ['guest_id' => $ana->id]);
    $response->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('guest.claimed', false)
        ->assertJsonPath('guest.revealed', false)
        ->assertJsonPath('guest.status', 'awaiting');

    $after = $ana->fresh();
    expect($after->claimed)->toBeFalse()
        ->and($after->claimed_at)->toBeNull()
        ->and($after->revealed)->toBeFalse()
        // Everything else is exactly as it was.
        ->and($after->pin_hash)->toBe($before->pin_hash)
        ->and($after->recipient_id)->toBe($before->recipient_id)
        ->and(WishlistItem::where('guest_id', $ana->id)->count())->toBe(2)
        ->and(Vote::where('guest_id', $ana->id)->count())->toBe(1);

    // The other guests are untouched.
    expect($guests['Ben Reyes']->fresh()->claimed)->toBeTrue();

    // Ana can enter again with the same PIN: it was never cleared.
    slice5Enter($this, $ana);
    expect($ana->fresh()->claimed)->toBeTrue();
});

test('reset_one with an unknown guest is guest_not_found', function () {
    slice5Fixture($this);

    $this->postJson('/api/reset_one', ['guest_id' => 999999])
        ->assertStatus(404)
        ->assertJsonPath('error', 'guest_not_found');
});

// ----------------------------------------------------------------------
// Unclaim all and redraw
// ----------------------------------------------------------------------

test('host_unclaim_all clears every claim and changes nothing else', function () {
    slice5Fixture($this);

    $mappingBefore = slice5Mapping();
    $pinsBefore = slice5PinHashes();
    expect(array_filter($mappingBefore))->not->toBeEmpty();

    $this->postJson('/api/host_unclaim_all')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('unclaimed', 3);

    expect(Guest::where('claimed', true)->count())->toBe(0)
        ->and(Guest::where('revealed', true)->count())->toBe(0)
        ->and(Guest::whereNotNull('claimed_at')->count())->toBe(0)
        // The draw is NOT redrawn and PINs are NOT cleared.
        ->and(slice5Mapping())->toBe($mappingBefore)
        ->and(slice5PinHashes())->toBe($pinsBefore)
        ->and(WishlistItem::count())->toBe(2)
        ->and(Vote::count())->toBe(2)
        ->and(Poll::count())->toBe(1);
});

test('host_redraw is blocked while a name is claimed, then redraws a derangement after unclaim all', function () {
    slice5Fixture($this);

    $this->postJson('/api/host_redraw')
        ->assertStatus(409)
        ->assertJsonPath('error', 'guests_locked')
        ->assertJsonPath('message', DrawService::LOCKED_MESSAGE);

    $this->postJson('/api/host_unclaim_all')->assertOk();
    $this->postJson('/api/host_redraw')
        ->assertOk()
        ->assertJsonPath('ok', true);

    $mapping = slice5Mapping();
    expect($mapping)->toHaveCount(3);
    foreach ($mapping as $guestId => $recipientId) {
        expect($recipientId)->not->toBeNull()
            ->and($recipientId)->not->toBe($guestId);
    }
    expect(array_unique(array_values($mapping)))->toHaveCount(3);
});

// ----------------------------------------------------------------------
// Reset PIN
// ----------------------------------------------------------------------

test('host_reset_pin clears only the PIN hash, even for a claimed guest', function () {
    ['guests' => $guests] = slice5Fixture($this);
    $ana = $guests['Ana Cruz'];

    $this->postJson('/api/host_reset_pin', ['guest_id' => $ana->id])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('guest.needs_pin', true)
        ->assertJsonPath('guest.pin_set', false);

    $after = $ana->fresh();
    expect($after->pin_hash)->toBeNull()
        ->and($after->claimed)->toBeTrue()
        ->and($after->revealed)->toBeTrue()
        ->and(WishlistItem::where('guest_id', $ana->id)->count())->toBe(2)
        ->and(Vote::where('guest_id', $ana->id)->count())->toBe(1);

    // On return, Ana creates a NEW PIN (the create path), and it replaces
    // the forgotten one rather than reviving it.
    slice5Enter($this, $ana, '9876');

    $hash = $ana->fresh()->pin_hash;
    expect(Hash::check('9876', $hash))->toBeTrue()
        ->and(Hash::check('1234', $hash))->toBeFalse();
});

// ----------------------------------------------------------------------
// Clear wishlists
// ----------------------------------------------------------------------

test('host_clear_wishlists deletes the items and nothing else', function () {
    ['guests' => $guests] = slice5Fixture($this);

    $this->postJson('/api/host_clear_wishlists')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('deleted', 2);

    expect(WishlistItem::count())->toBe(0)
        ->and(Guest::count())->toBe(3)
        ->and(Guest::where('claimed', true)->count())->toBe(3)
        ->and($guests['Ana Cruz']->fresh()->revealed)->toBeTrue()
        ->and(Poll::count())->toBe(1)
        ->and(Vote::count())->toBe(2);
});

// ----------------------------------------------------------------------
// Change password
// ----------------------------------------------------------------------

test('host_change_password validates in order and the new password survives a re-seed', function () {
    slice5LoginHost($this);

    // 1. The current password must be right, before anything else.
    $this->postJson('/api/host_change_password', [
        'current_password' => 'not-the-password',
        'new_password' => 'short',
        'new_password_confirmation' => 'different',
    ])->assertStatus(422)->assertJsonPath('error', 'password_incorrect');

    // 2. Then length.
    $this->postJson('/api/host_change_password', [
        'current_password' => 'secret-password',
        'new_password' => 'short',
        'new_password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonPath('error', 'password_too_short');

    // 3. Then the confirmation.
    $this->postJson('/api/host_change_password', [
        'current_password' => 'secret-password',
        'new_password' => 'brand-new-password',
        'new_password_confirmation' => 'brand-new-passw0rd',
    ])->assertStatus(422)->assertJsonPath('error', 'password_mismatch');

    // 4. Then sameness.
    $this->postJson('/api/host_change_password', [
        'current_password' => 'secret-password',
        'new_password' => 'secret-password',
        'new_password_confirmation' => 'secret-password',
    ])->assertStatus(422)->assertJsonPath('error', 'password_same');

    // Success.
    $this->postJson('/api/host_change_password', [
        'current_password' => 'secret-password',
        'new_password' => 'brand-new-password',
        'new_password_confirmation' => 'brand-new-password',
    ])->assertOk()->assertJsonPath('ok', true);

    // The old password is dead, the new one signs in.
    $this->postJson('/api/host_logout')->assertOk();
    $this->postJson('/api/host_login', [
        'username' => 'host',
        'password' => 'secret-password',
    ])->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
    $this->postJson('/api/host_login', [
        'username' => 'host',
        'password' => 'brand-new-password',
    ])->assertOk();

    // Re-seeding never resets a changed password (firstOrCreate).
    $this->seed();
    expect(Hash::check('brand-new-password', User::where('username', 'host')->firstOrFail()->password))->toBeTrue();

    $this->postJson('/api/host_logout')->assertOk();
    $this->postJson('/api/host_login', [
        'username' => 'host',
        'password' => 'changeme',
    ])->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
    $this->postJson('/api/host_login', [
        'username' => 'host',
        'password' => 'brand-new-password',
    ])->assertOk();
});

// ----------------------------------------------------------------------
// Reset everything
// ----------------------------------------------------------------------

test('reset_all deletes the party and leaves nothing behind', function () {
    slice5Fixture($this);

    $this->postJson('/api/reset_all')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('exists', false);

    expect(Party::count())->toBe(0)
        ->and(Guest::count())->toBe(0)
        ->and(WishlistItem::count())->toBe(0)
        ->and(Poll::count())->toBe(0)
        ->and(PollOption::count())->toBe(0)
        ->and(Vote::count())->toBe(0);

    $this->postJson('/api/host_status')
        ->assertOk()
        ->assertJsonPath('exists', false);
});

// ----------------------------------------------------------------------
// Gating and the no-party state
// ----------------------------------------------------------------------

test('admin actions need the host session', function () {
    slice5Fixture($this);
    $this->postJson('/api/host_logout')->assertOk();

    foreach (['reset_one', 'host_unclaim_all', 'host_redraw', 'host_reset_pin', 'host_clear_wishlists', 'reset_all', 'host_change_password'] as $action) {
        $this->postJson("/api/{$action}", ['guest_id' => 1])
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }
});

test('admin writes with no party report not_set_up', function () {
    slice5LoginHost($this);

    $this->postJson('/api/reset_one', ['guest_id' => 1])
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
    $this->postJson('/api/host_reset_pin', ['guest_id' => 1])
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
    $this->postJson('/api/host_unclaim_all')
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
    $this->postJson('/api/host_redraw')
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
    $this->postJson('/api/host_clear_wishlists')
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
    $this->postJson('/api/reset_all')
        ->assertStatus(422)->assertJsonPath('error', 'not_set_up');
});
