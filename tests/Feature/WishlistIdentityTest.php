<?php

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

if (! function_exists('wishlistIdentityParty')) {
    /**
     * A party whose guests have distinctive real names, nicknames, and
     * codenames, so a substring scan over a whole payload can prove a
     * real name appears NOWHERE in it. Yolanda deliberately has no
     * secret name: her real name must still not leak.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function wishlistIdentityParty(): array
    {
        $party = Party::create([
            'name' => 'Office Christmas Party',
            'budget' => '500 pesos',
            'date' => '2026-12-20',
            'invite_code' => 'TESTCODE',
        ]);

        $definitions = [
            ['display' => 'Zebediah Quillfeather', 'nickname' => 'ZebbyUnique', 'secret_name' => 'FrostByteUnique', 'pin' => '1111'],
            ['display' => 'Yolanda Meriweather', 'nickname' => 'YollyUnique', 'secret_name' => null, 'pin' => '2222'],
            ['display' => 'Xavier Pemberton', 'nickname' => 'XavyUnique', 'secret_name' => 'TinselFoxUnique', 'pin' => '3333'],
        ];

        $guests = [];
        foreach ($definitions as $definition) {
            $guests[$definition['display']] = $party->guests()->create([
                'display' => $definition['display'],
                'nickname' => $definition['nickname'],
                'secret_name' => $definition['secret_name'],
                'pin_hash' => Hash::make($definition['pin']),
            ]);
        }

        // Roulette world: recipients come from spins, so the fixture
        // spins for everyone (each guest draws live, in id order).
        $draw = new DrawService;
        foreach ($party->guests()->orderBy('id')->get() as $partyGuest) {
            $draw->assignForGuest($partyGuest);
        }

        return [$party, $guests];
    }
}

if (! function_exists('wishlistIdentityRealNames')) {
    /**
     * Every real-name string that must never appear in a guest-facing
     * wishlist payload: all displays and all nicknames.
     *
     * @param  array<string, Guest>  $guests
     * @return array<int, string>
     */
    function wishlistIdentityRealNames(array $guests): array
    {
        $names = [];
        foreach ($guests as $guest) {
            $names[] = $guest->display;
            $names[] = $guest->nickname;
        }

        return $names;
    }
}

if (! function_exists('wishlistIdentityEnter')) {
    /** Enter as a guest whose PIN is already set, then reveal. */
    function wishlistIdentityEnter(object $test, Guest $guest, string $pin): void
    {
        $test->postJson('/api/enter', [
            'guest_id' => $guest->id, 'pin' => $pin, 'pin_confirmation' => $pin,
        ])->assertOk();
        $test->postJson('/api/reveal')->assertOk();
    }
}

// ----------------------------------------------------------------------
// The board: wishers are codenames, never real names
// ----------------------------------------------------------------------

test('wishlist_list identifies wishers by secret name only', function () {
    [, $guests] = wishlistIdentityParty();
    $zeb = $guests['Zebediah Quillfeather'];
    $yolanda = $guests['Yolanda Meriweather'];
    $xavier = $guests['Xavier Pemberton'];

    $zeb->wishlistItems()->create(['text' => 'Zeb First Wish', 'kind' => 'text']);
    $zeb->wishlistItems()->create(['text' => 'Zeb Second Wish', 'kind' => 'text']);
    $yolanda->wishlistItems()->create(['text' => 'Yolanda Wish', 'kind' => 'text']);

    $response = $this->postJson('/api/wishlist_list')->assertOk();
    $board = collect($response->json('guests'));

    $zebEntry = $board->firstWhere('id', $zeb->id);
    $yolandaEntry = $board->firstWhere('id', $yolanda->id);
    $xavierEntry = $board->firstWhere('id', $xavier->id);

    // The codename is the identity: set codenames ship as the value, an
    // unset codename ships as null (the key is still present).
    expect($zebEntry['secret_name'])->toBe('FrostByteUnique')
        ->and($xavierEntry['secret_name'])->toBe('TinselFoxUnique')
        ->and(array_key_exists('secret_name', $yolandaEntry))->toBeTrue()
        ->and($yolandaEntry['secret_name'])->toBeNull();

    // No real-name fields ride along on any entry.
    foreach ([$zebEntry, $yolandaEntry, $xavierEntry] as $entry) {
        expect($entry)->not->toHaveKey('display')
            ->not->toHaveKey('nickname')
            ->not->toHaveKey('aliases');
    }

    // Everything else the board carried is intact.
    expect($zebEntry['wish_count'])->toBe(2)
        ->and(array_column($zebEntry['items'], 'text'))->toBe(['Zeb Second Wish', 'Zeb First Wish'])
        ->and($yolandaEntry['wish_count'])->toBe(1)
        ->and($xavierEntry['wish_count'])->toBe(0)
        ->and($xavierEntry['items'])->toBe([]);

    // Whole-payload scan: no guest's real display name or nickname
    // appears anywhere, including the guest with no codename.
    $json = json_encode($response->json());
    foreach (wishlistIdentityRealNames($guests) as $realName) {
        expect($json)->not->toContain($realName);
    }
    expect($json)->toContain('FrostByteUnique')
        ->toContain('TinselFoxUnique');
});

// ----------------------------------------------------------------------
// The recipient wishlist: codename only, and nobody else's real name
// ----------------------------------------------------------------------

test('guest_recipient_wishlist response contains no real name of any guest', function () {
    [, $guests] = wishlistIdentityParty();
    $zeb = $guests['Zebediah Quillfeather'];
    $recipient = Guest::find($zeb->fresh()->recipient_id);

    $recipient->wishlistItems()->create(['text' => 'Recipient Wish', 'kind' => 'text']);

    wishlistIdentityEnter($this, $zeb, '1111');

    $response = $this->postJson('/api/guest_recipient_wishlist');
    $response->assertOk()
        ->assertJsonPath('recipient.id', $recipient->id)
        ->assertJsonPath('recipient.secret_name', $recipient->secret_name)
        ->assertJsonMissingPath('recipient.display')
        ->assertJsonMissingPath('recipient.nickname')
        ->assertJsonPath('items.0.text', 'Recipient Wish');

    $json = json_encode($response->json());
    foreach (wishlistIdentityRealNames($guests) as $realName) {
        expect($json)->not->toContain($realName);
    }
});

// ----------------------------------------------------------------------
// The member wishlist page: the member prop is the codename shape
// ----------------------------------------------------------------------

test('the member wishlist page identifies the member by secret name only', function () {
    [, $guests] = wishlistIdentityParty();
    $zeb = $guests['Zebediah Quillfeather'];
    $yolanda = $guests['Yolanda Meriweather'];
    $xavier = $guests['Xavier Pemberton'];

    wishlistIdentityEnter($this, $zeb, '1111');

    $response = $this->get('/guest/wishlists/'.$xavier->id);
    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Guest/MemberWishlist')
            ->where('member.id', $xavier->id)
            ->where('member.secret_name', 'TinselFoxUnique')
            ->missing('member.display')
            ->missing('member.nickname')
            ->missing('member.aliases'));

    // The member's real name and nickname appear nowhere in the page
    // payload (the viewer's own guest prop is a different guest).
    expect($response->getContent())->not->toContain('Xavier Pemberton')
        ->not->toContain('XavyUnique');

    // A member with no codename yet: secret_name is null, and their
    // real name still does not ship.
    $unset = $this->get('/guest/wishlists/'.$yolanda->id);
    $unset->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Guest/MemberWishlist')
            ->where('member.id', $yolanda->id)
            ->where('member.secret_name', null)
            ->missing('member.display')
            ->missing('member.nickname'));

    expect($unset->getContent())->not->toContain('Yolanda Meriweather')
        ->not->toContain('YollyUnique');
});
