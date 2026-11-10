<?php

use App\Models\Guest;
use App\Models\Party;
use App\Support\TempSecretName;
use Illuminate\Support\Facades\Hash;

/*
 * Temporary secret names (owner's ask, 2026-11-10): every guest gets a
 * codename from the moment they are added so nobody is left showing as
 * "still choosing"; the name is flagged temporary until the guest
 * picks their own after setting their PIN. Host/public "picked"
 * signals count only chosen names.
 */

function tempNameParty(array $displays): array
{
    $party = Party::create([
        'name' => 'Office Christmas Party',
        'budget' => '500 pesos',
        'date' => '2026-12-20',
        'invite_code' => 'TESTCODE',
    ]);

    $guests = [];
    foreach ($displays as $display) {
        $guests[$display] = $party->guests()->create(['display' => $display]);
    }

    return [$party, $guests];
}

test('host adding a guest assigns them a temporary secret name', function () {
    tempNameParty([]);
    secretNameLoginHost($this);

    $this->postJson('/api/host_guest_add', ['display' => 'Ana Cruz'])
        ->assertOk()
        // The host payload still reads "not picked": a temporary name
        // is not a picked one.
        ->assertJsonPath('guest.has_secret_name', false);

    $guest = Guest::query()->where('display', 'Ana Cruz')->firstOrFail();
    expect($guest->secret_name)->toBeString()->not->toBe('')
        ->and($guest->secret_name_temp)->toBeTrue();
});

test('backfill gives every nameless guest a unique temporary name and keeps chosen ones', function () {
    [$party, $guests] = tempNameParty(['Ana Cruz', 'Ben Reyes', 'Cora Diaz']);
    $guests['Ben Reyes']->update(['secret_name' => 'Cozy Reindeer']);

    $assigned = TempSecretName::backfillParty($party);

    expect($assigned)->toBe(2);
    $names = $party->guests()->pluck('secret_name')->all();
    expect($names)->toHaveCount(3)
        ->and(array_unique(array_map('mb_strtolower', $names)))->toHaveCount(3)
        ->and($guests['Ben Reyes']->fresh()->secret_name)->toBe('Cozy Reindeer')
        ->and($guests['Ben Reyes']->fresh()->secret_name_temp)->toBeFalse()
        ->and($guests['Ana Cruz']->fresh()->secret_name_temp)->toBeTrue()
        ->and($guests['Cora Diaz']->fresh()->secret_name_temp)->toBeTrue();
});

test('temporary names never collide with an existing codename, case-insensitively', function () {
    [$party, $guests] = tempNameParty(['Ana Cruz']);
    // Occupy a large share of the pool with case-varied duplicates of
    // one pair plus another exact pair, then assign many guests.
    $guests['Ana Cruz']->update(['secret_name' => 'jolly reindeer']);
    for ($i = 0; $i < 12; $i++) {
        $guest = $party->guests()->create(['display' => 'Guest '.$i]);
        TempSecretName::assign($guest);
        expect(mb_strtolower($guest->fresh()->secret_name))->not->toBe('jolly reindeer');
    }
    $all = $party->guests()->pluck('secret_name')->map(fn ($n) => mb_strtolower($n))->all();
    expect(array_unique($all))->toHaveCount(count($all));
});

test('a recipient on a temporary name reveals with it instead of still choosing', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz', 'pin' => '1234'],
        ['display' => 'Ben Reyes'],
        ['display' => 'Cora Diaz'],
    ]);
    TempSecretName::backfillParty(Party::firstOrFail());

    $ana = $guests['Ana Cruz']->fresh();
    $recipient = $ana->recipient()->firstOrFail();

    secretNameEnter($this, $ana);
    $this->postJson('/api/reveal')
        ->assertOk()
        ->assertJsonPath('recipient.secret_name', $recipient->fresh()->secret_name)
        ->assertJsonPath('recipient.id', $recipient->id);
});

test('picking your own name retires the temporary one and flips the picked flags', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz', 'pin' => '1234'],
        ['display' => 'Ben Reyes'],
    ]);
    $ana = $guests['Ana Cruz'];
    TempSecretName::assign($ana);
    expect($ana->fresh()->secret_name_temp)->toBeTrue();

    secretNameEnter($this, $ana->fresh());
    $this->postJson('/api/guest_set_secret_name', ['secret_name' => 'My Own Name'])
        ->assertOk()
        ->assertJsonPath('secret_name', 'My Own Name');

    $ana = $ana->fresh();
    expect($ana->secret_name_temp)->toBeFalse()
        ->and($ana->secret_name)->toBe('My Own Name')
        // The public/host "picked" flag now reads true for her.
        ->and(\App\Support\Serializers::publicGuest($ana)['has_secret_name'])->toBeTrue();
});

test('viewing your own secret name reports the temporary flag', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz', 'pin' => '1234'],
        ['display' => 'Ben Reyes'],
    ]);
    $ana = $guests['Ana Cruz'];
    TempSecretName::assign($ana);
    $tempName = $ana->fresh()->secret_name;

    secretNameEnter($this, $ana->fresh());
    $this->postJson('/api/guest_secret_name_view', ['pin' => '1234'])
        ->assertOk()
        ->assertJsonPath('secret_name', $tempName)
        ->assertJsonPath('secret_name_temp', true);
});

test('wishlist surfaces show the temporary codename for the wisher', function () {
    [, $guests] = secretNameParty([
        ['display' => 'Ana Cruz', 'pin' => '1234'],
        ['display' => 'Ben Reyes'],
    ]);
    TempSecretName::backfillParty(Party::firstOrFail());
    $ben = $guests['Ben Reyes']->fresh();

    $ben->wishlistItems()->create(['kind' => 'text', 'text' => 'A warm scarf']);

    secretNameEnter($this, $guests['Ana Cruz']->fresh());
    $board = collect($this->postJson('/api/wishlist_list')->assertOk()->json('guests'));
    expect($board->firstWhere('id', $ben->id)['secret_name'])->toBe($ben->secret_name);
});
