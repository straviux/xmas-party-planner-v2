<?php

use App\Models\Guest;
use App\Models\KringleDraw;
use App\Models\Party;
use App\Services\KringleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Monday 9 November 2026, Asia/Manila: the "current week" for every
// test here starts 2026-11-09 and ends 2026-11-15.
if (! function_exists('kringleNow')) {
    function kringleNow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00:00', 'Asia/Manila'));
    }
}

if (! function_exists('kringleParty')) {
    /**
     * @param  array<int, string>  $displays
     * @return array{0: Party, 1: array<string, Guest>}
     */
    function kringleParty(array $displays, ?string $date = null): array
    {
        $party = Party::create([
            'name' => 'Kringle Party',
            'budget' => '300 pesos',
            'date' => $date,
            'invite_code' => strtoupper(Str::random(8)),
        ]);

        $guests = [];
        foreach ($displays as $display) {
            $guests[$display] = $party->guests()->create([
                'display' => $display,
                'secret_name' => 'Code ' . Str::before($display, ' '),
            ]);
        }

        return [$party, $guests];
    }
}

if (! function_exists('kringleEnter')) {
    function kringleEnter(object $test, Guest $guest): void
    {
        $test->postJson('/api/enter', [
            'guest_id' => $guest->id,
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertOk();
    }
}

// ----------------------------------------------------------------------
// Themes
// ----------------------------------------------------------------------

test('the weekly theme is generated once, stored, and stable', function () {
    kringleNow();
    [$party] = kringleParty(['Ana Cruz', 'Ben Reyes']);

    $service = app(KringleService::class);
    $first = $service->currentTheme($party);
    $second = $service->currentTheme($party);

    expect($first)->not->toBeNull()
        ->and($first->id)->toBe($second->id)
        ->and($first->theme)->toBeIn(KringleService::THEMES)
        ->and($first->week_start)->toBe('2026-11-09')
        ->and($party->kringleThemes()->count())->toBe(1);
});

test('theme generation never repeats a theme until the pool cycles', function () {
    kringleNow();
    [$party] = kringleParty(['Ana Cruz', 'Ben Reyes']);

    // 23 of the 24 pool themes already used in past weeks.
    $used = array_slice(KringleService::THEMES, 0, 23);
    foreach ($used as $i => $theme) {
        $party->kringleThemes()->create([
            'week_start' => Carbon::parse('2026-11-09')->subWeeks($i + 1)->toDateString(),
            'theme' => $theme,
        ]);
    }

    $current = app(KringleService::class)->currentTheme($party);
    expect($current->theme)->toBe(KringleService::THEMES[23]);

    // The current row completes the pool of 24; a fresh week still
    // gets a pool theme (the cycle restarts).
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Asia/Manila'));
    $next = app(KringleService::class)->currentTheme($party);
    expect($next)->not->toBeNull()
        ->and($next->theme)->toBeIn(KringleService::THEMES)
        ->and($next->week_start)->toBe('2026-11-16');
});

test('the party week gets no theme and the run reports itself over', function () {
    kringleNow();
    // Party is Saturday of the current week: this IS the party week.
    [$party] = kringleParty(['Ana Cruz', 'Ben Reyes'], '2026-11-14');

    $service = app(KringleService::class);
    expect($service->currentTheme($party))->toBeNull()
        ->and($service->weeksLeft($party, $service->currentWeekStart()))->toBe(0);
});

test('weeks left counts theme weeks up to the party week', function () {
    kringleNow();
    // Party is in the week of Monday 2026-11-23: two theme weeks run
    // before it (Nov 9 and Nov 16), so from Nov 9 there are 2 left.
    [$party] = kringleParty(['Ana Cruz', 'Ben Reyes'], '2026-11-25');

    $service = app(KringleService::class);
    expect($service->currentTheme($party))->not->toBeNull()
        ->and($service->weeksLeft($party, $service->currentWeekStart()))->toBe(2);

    // No date at all: the run never ends.
    [$dateless] = kringleParty(['Cora Diaz', 'Dan Lim']);
    expect($service->weeksLeft($dateless, $service->currentWeekStart()))->toBeNull()
        ->and($service->currentTheme($dateless))->not->toBeNull();
});

test('regenerate swaps to the one unused theme, and the freed theme can come back', function () {
    kringleNow();
    [$party] = kringleParty(['Ana Cruz', 'Ben Reyes']);

    $service = app(KringleService::class);
    $current = $service->currentTheme($party);

    // Use every other pool theme in past weeks except one spare.
    $spare = collect(KringleService::THEMES)->first(fn ($t) => $t !== $current->theme);
    $others = collect(KringleService::THEMES)->reject(fn ($t) => $t === $current->theme || $t === $spare)->values();
    foreach ($others as $i => $theme) {
        $party->kringleThemes()->create([
            'week_start' => Carbon::parse('2026-11-09')->subWeeks($i + 1)->toDateString(),
            'theme' => $theme,
        ]);
    }

    $swapped = $service->regenerate($party);
    expect($swapped->theme)->toBe($spare);

    // The theme just replaced is unused again: it is the only spare.
    $back = $service->regenerate($party);
    expect($back->theme)->toBe($current->theme);
});

// ----------------------------------------------------------------------
// Spins
// ----------------------------------------------------------------------

test('a week of spins in any order draws everyone exactly once, never themselves', function () {
    kringleNow();

    foreach ([2, 3, 5, 8] as $n) {
        for ($round = 0; $round < 3; $round++) {
            $displays = collect(range(1, $n))->map(fn ($i) => "Guest {$n}x{$i}")->all();
            [$party, $guests] = kringleParty($displays);
            $order = array_values($guests);
            if ($round > 0) {
                shuffle($order);
            }

            $service = app(KringleService::class);
            foreach ($order as $guest) {
                $service->spin($guest);
            }

            $draws = $party->kringleDraws()->where('week_start', '2026-11-09')->get();
            expect($draws)->toHaveCount($n)
                ->and($draws->pluck('recipient_id')->unique())->toHaveCount($n);
            foreach ($draws as $draw) {
                expect($draw->recipient_id)->not->toBe($draw->giver_id);
            }
        }
    }
});

test('endgame guard: the second-to-last spinner of the week is forced onto the last giver', function () {
    kringleNow();
    [, $guests] = kringleParty(['Ana Cruz', 'Ben Reyes', 'Cora Diaz']);
    [$ana, $ben, $cora] = array_values($guests);

    // Ana has spun and drawn Ben. Givers left: Ben and Cora; targets
    // left: Ana and Cora. If Ben took Ana, Cora would be stranded
    // with only Cora, so Ben must draw Cora; Cora then draws Ana.
    KringleDraw::create([
        'party_id' => $ana->party_id,
        'week_start' => '2026-11-09',
        'giver_id' => $ana->id,
        'recipient_id' => $ben->id,
    ]);

    $service = app(KringleService::class);
    expect($service->spin($ben)->recipient_id)->toBe($cora->id)
        ->and($service->spin($cora)->recipient_id)->toBe($ana->id);
});

test('a guest spins once per week through the API and sees only their own result, by real name', function () {
    kringleNow();
    [, $guests] = kringleParty(['Ana Cruz', 'Ben Reyes', 'Cora Diaz']);
    $ana = $guests['Ana Cruz'];

    // Signed-out-of-guest-session calls are refused as not claimed.
    $this->postJson('/api/kringle_current')
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    kringleEnter($this, $ana);

    $before = $this->postJson('/api/kringle_current')->assertOk()->json('kringle');
    expect($before['active'])->toBeTrue()
        ->and($before['theme'])->toBeIn(KringleService::THEMES)
        ->and($before['week_start'])->toBe('2026-11-09')
        ->and($before['week_end'])->toBe('2026-11-15')
        ->and($before['spun'])->toBeFalse()
        ->and($before['recipient'])->toBeNull();

    $spin = $this->postJson('/api/kringle_spin')->assertOk()->json('kringle');
    expect($spin['spun'])->toBeTrue()
        ->and($spin['recipient']['name'])->toBeIn(['Ben Reyes', 'Cora Diaz']);

    // Kringle names its Monito by real name (the owner's call), but
    // the payload carries ONLY the caller's own recipient: neither
    // the caller's own name nor the undrawn guest's name appears.
    $raw = json_encode($spin);
    expect($raw)->toContain($spin['recipient']['name'])
        ->and($raw)->not->toContain('Ana Cruz');
    $undrawn = $spin['recipient']['name'] === 'Ben Reyes' ? 'Cora Diaz' : 'Ben Reyes';
    expect($raw)->not->toContain($undrawn);

    $this->postJson('/api/kringle_spin')
        ->assertStatus(409)
        ->assertJsonPath('error', 'kringle_already_spun');
});

test('spin refuses a one-guest party', function () {
    kringleNow();
    [, $solo] = kringleParty(['Ana Cruz']);
    kringleEnter($this, $solo['Ana Cruz']);
    $this->postJson('/api/kringle_spin')
        ->assertStatus(422)
        ->assertJsonPath('error', 'kringle_not_enough_guests');
});

test('spin refuses during the party week and the state reports inactive', function () {
    kringleNow();
    [, $partyGuests] = kringleParty(['Eli Fox', 'Gia Ray'], '2026-11-14');
    kringleEnter($this, $partyGuests['Eli Fox']);
    $this->postJson('/api/kringle_spin')
        ->assertStatus(409)
        ->assertJsonPath('error', 'kringle_inactive');
    $state = $this->postJson('/api/kringle_current')->assertOk()->json('kringle');
    expect($state['active'])->toBeFalse()
        ->and($state['theme'])->toBeNull();
});

test('a new week starts unspun, and history carries the callers own past recipients', function () {
    kringleNow();
    [$party, $guests] = kringleParty(['Ana Cruz', 'Ben Reyes', 'Cora Diaz']);
    $ana = $guests['Ana Cruz'];

    // Last week: a theme ran and Ana drew Ben.
    $party->kringleThemes()->create(['week_start' => '2026-11-02', 'theme' => 'Something red']);
    KringleDraw::create([
        'party_id' => $party->id,
        'week_start' => '2026-11-02',
        'giver_id' => $ana->id,
        'recipient_id' => $guests['Ben Reyes']->id,
    ]);

    kringleEnter($this, $ana);
    $state = $this->postJson('/api/kringle_current')->assertOk()->json('kringle');

    expect($state['spun'])->toBeFalse()
        ->and($state['history'])->toHaveCount(1)
        ->and($state['history'][0]['theme'])->toBe('Something red')
        ->and($state['history'][0]['week_start'])->toBe('2026-11-02')
        ->and($state['history'][0]['recipient_name'])->toBe('Ben Reyes');
});

// ----------------------------------------------------------------------
// Host side
// ----------------------------------------------------------------------

test('host status carries the kringle summary and regenerate works for the host only', function () {
    kringleNow();
    [, $guests] = kringleParty(['Ana Cruz', 'Ben Reyes', 'Cora Diaz']);

    // Guests (and signed-out callers) cannot regenerate.
    $this->postJson('/api/host_kringle_regenerate')
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');

    seedHostUser();
    loginAsHost($this);

    $status = $this->postJson('/api/host_status')->assertOk()->json();
    expect(array_keys($status['kringle']))->toBe([
        'active', 'theme', 'week_start', 'week_end', 'weeks_left', 'spun_count', 'guest_count',
    ])->and($status['kringle']['theme'])->toBeIn(KringleService::THEMES)
        ->and($status['kringle']['spun_count'])->toBe(0)
        ->and($status['kringle']['guest_count'])->toBe(3);

    // One guest spins (the host session rides along in this client;
    // enter as the guest in a fresh sequence instead).
    $this->postJson('/api/host_logout')->assertOk();
    kringleEnter($this, $guests['Ana Cruz']);
    $this->postJson('/api/kringle_spin')->assertOk();

    loginAsHost($this);
    $status = $this->postJson('/api/host_status')->assertOk()->json();
    expect($status['kringle']['spun_count'])->toBe(1);

    // The kringle block never carries pairing data.
    expect(json_encode($status['kringle']))->not->toContain('recipient');

    $before = $status['kringle']['theme'];
    $after = $this->postJson('/api/host_kringle_regenerate')->assertOk()->json('kringle');
    expect($after['theme'])->toBeIn(KringleService::THEMES);
    // With 23 themes still unused, a regenerate must change it.
    expect($after['theme'])->not->toBe($before);
});
