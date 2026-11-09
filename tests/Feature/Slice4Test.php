<?php

use App\Models\Guest;
use App\Models\Party;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\Hash;

if (! function_exists('slice4LoginHost')) {
    /** Create the host user (if needed) and sign in through the real action. */
    function slice4LoginHost(object $test): void
    {
        User::firstOrCreate(
            ['username' => 'host'],
            ['password' => Hash::make('secret-password')],
        );

        $test->postJson('/api/host_login', [
            'username' => 'host',
            'password' => 'secret-password',
        ])->assertOk();
    }
}

if (! function_exists('slice4Party')) {
    /**
     * A party with three pin-less guests, built directly in the DB.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function slice4Party(): array
    {
        $party = Party::create([
            'name' => 'Office Christmas Party',
            'budget' => '500 pesos',
            'date' => '2026-12-20',
            'invite_code' => 'TESTCODE',
        ]);

        $guests = [];
        foreach (['Ana Cruz', 'Ben Reyes', 'Cora Diaz'] as $display) {
            $guests[$display] = $party->guests()->create(['display' => $display]);
        }

        return [$party, $guests];
    }
}

if (! function_exists('slice4CreatePoll')) {
    /** Create a poll through the host action and return its shape. */
    function slice4CreatePoll(object $test, string $question = 'Best Christmas theme?', array $options = ['Classic Red', 'Winter Blue', 'Gold']): array
    {
        $response = $test->postJson('/api/host_poll_create', [
            'question' => $question,
            'options' => $options,
        ]);
        $response->assertOk()->assertJsonPath('ok', true);

        return $response->json('poll');
    }
}

if (! function_exists('slice4Enter')) {
    /** Enter as a guest through the real action, creating PIN 1234 on first entry. */
    function slice4Enter(object $test, Guest $guest): void
    {
        $payload = ['guest_id' => $guest->id, 'pin' => '1234'];
        if (empty($guest->fresh()->pin_hash)) {
            $payload['pin_confirmation'] = '1234';
        }

        $test->postJson('/api/enter', $payload)->assertOk();
    }
}

if (! function_exists('slice4Vote')) {
    /** Vote through the real action and return the fresh poll shape. */
    function slice4Vote(object $test, int $pollId, int $optionId): array
    {
        $response = $test->postJson('/api/poll_vote', [
            'poll_id' => $pollId,
            'option_id' => $optionId,
        ]);
        $response->assertOk()->assertJsonPath('ok', true);

        return $response->json('poll');
    }
}

if (! function_exists('slice4KeysRecursive')) {
    /** @return array<int, string> */
    function slice4KeysRecursive(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = [...$keys, ...slice4KeysRecursive($value)];
            }
        }

        return $keys;
    }
}

if (! function_exists('slice4Option')) {
    /** One option of a shaped poll, by position. */
    function slice4Option(array $poll, int $index): array
    {
        return $poll['options'][$index];
    }
}

// ----------------------------------------------------------------------
// Host creation and validation
// ----------------------------------------------------------------------

test('host_poll_create validates the question and the option count', function () {
    slice4LoginHost($this);
    slice4Party();

    $this->postJson('/api/host_poll_create', ['question' => '  ', 'options' => ['A', 'B']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'poll_question_required');

    $this->postJson('/api/host_poll_create', ['question' => 'Theme?', 'options' => ['Only one']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'poll_needs_options');

    $this->postJson('/api/host_poll_create', ['question' => 'Theme?', 'options' => ['One', '  ']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'poll_needs_options');

    expect(Poll::count())->toBe(0);
});

test('host poll actions need the host session', function () {
    slice4Party();

    $this->postJson('/api/host_poll_create', ['question' => 'Theme?', 'options' => ['A', 'B']])
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden');
});

test('poll_get with no party is an empty list, and host create needs a party', function () {
    $this->postJson('/api/poll_get')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('polls', []);

    slice4LoginHost($this);

    $this->postJson('/api/host_poll_create', ['question' => 'Theme?', 'options' => ['A', 'B']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_set_up');
});

test('a fresh poll shows zero counts, no vote, and no winners, and names no voter', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);

    expect($poll['open'])->toBeTrue()
        ->and($poll['total'])->toBe(0)
        ->and($poll['winners'])->toBe([])
        ->and(array_column($poll['options'], 'votes'))->toBe([0, 0, 0]);

    slice4Enter($this, $guests['Ana Cruz']);

    $response = $this->postJson('/api/poll_get')->assertOk();
    $shaped = $response->json('polls.0');

    expect($shaped['my_vote'])->toBeNull()
        ->and($shaped['total'])->toBe(0);

    $keys = slice4KeysRecursive($response->json());
    expect($keys)->not->toContain('guest_id')
        ->and($keys)->not->toContain('guest')
        ->and($keys)->not->toContain('voters')
        ->and($response->getContent())->not->toContain('Ana Cruz');
});

// ----------------------------------------------------------------------
// Voting
// ----------------------------------------------------------------------

test('a guest votes once and can change the vote while the poll is open', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);
    [$first, $second] = [$poll['options'][0]['id'], $poll['options'][1]['id']];

    slice4Enter($this, $guests['Ana Cruz']);
    $afterFirst = slice4Vote($this, $poll['id'], $first);

    expect($afterFirst['total'])->toBe(1)
        ->and(slice4Option($afterFirst, 0)['votes'])->toBe(1)
        ->and($afterFirst['my_vote'])->toBe($first);

    // The same poll through a second guest carries no vote for them.
    slice4Enter($this, $guests['Ben Reyes']);
    $forBen = $this->postJson('/api/poll_get')->json('polls.0');
    expect($forBen['my_vote'])->toBeNull()
        ->and($forBen['total'])->toBe(1);

    // Ana changes her mind: still one vote, now on the second option.
    slice4Enter($this, $guests['Ana Cruz']);
    $afterChange = slice4Vote($this, $poll['id'], $second);

    expect($afterChange['total'])->toBe(1)
        ->and(slice4Option($afterChange, 0)['votes'])->toBe(0)
        ->and(slice4Option($afterChange, 1)['votes'])->toBe(1)
        ->and($afterChange['my_vote'])->toBe($second)
        ->and(Vote::where('poll_id', $poll['id'])->where('guest_id', $guests['Ana Cruz']->id)->count())->toBe(1);
});

test('two guests voting differently bring the total to two', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);

    slice4Enter($this, $guests['Ana Cruz']);
    slice4Vote($this, $poll['id'], $poll['options'][0]['id']);

    slice4Enter($this, $guests['Ben Reyes']);
    $shape = slice4Vote($this, $poll['id'], $poll['options'][1]['id']);

    expect($shape['total'])->toBe(2)
        ->and(slice4Option($shape, 0)['votes'])->toBe(1)
        ->and(slice4Option($shape, 1)['votes'])->toBe(1);
});

test('poll_vote needs a guest session and an option from the same poll', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);
    $other = slice4CreatePoll($this, 'Dessert?', ['Cake', 'Pie']);

    $this->postJson('/api/poll_vote', ['poll_id' => $poll['id'], 'option_id' => $poll['options'][0]['id']])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');

    slice4Enter($this, $guests['Ana Cruz']);

    $this->postJson('/api/poll_vote', ['poll_id' => $poll['id'], 'option_id' => $other['options'][0]['id']])
        ->assertStatus(404)
        ->assertJsonPath('error', 'option_not_found');

    $this->postJson('/api/poll_vote', ['poll_id' => 999999, 'option_id' => $poll['options'][0]['id']])
        ->assertStatus(404)
        ->assertJsonPath('error', 'poll_not_found');
});

// ----------------------------------------------------------------------
// Closing, winners, ties, reopening
// ----------------------------------------------------------------------

test('a closed poll refuses votes, shows its winner, and reopens cleanly', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);
    [$first, $second] = [$poll['options'][0]['id'], $poll['options'][1]['id']];

    slice4Enter($this, $guests['Ana Cruz']);
    slice4Vote($this, $poll['id'], $second);
    slice4Enter($this, $guests['Ben Reyes']);
    slice4Vote($this, $poll['id'], $second);
    slice4Enter($this, $guests['Cora Diaz']);
    slice4Vote($this, $poll['id'], $first);

    $closed = $this->postJson('/api/host_poll_toggle', ['id' => $poll['id']])
        ->assertOk()->json('poll');
    expect($closed['open'])->toBeFalse()
        ->and($closed['winners'])->toBe([$second]);

    $this->postJson('/api/poll_vote', ['poll_id' => $poll['id'], 'option_id' => $first])
        ->assertStatus(409)
        ->assertJsonPath('error', 'poll_closed');

    $listed = $this->postJson('/api/poll_get')->json('polls.0');
    expect($listed['winners'])->toBe([$second])
        ->and($listed['total'])->toBe(3);

    $reopened = $this->postJson('/api/host_poll_toggle', ['id' => $poll['id']])
        ->assertOk()->json('poll');
    expect($reopened['open'])->toBeTrue()
        ->and($reopened['winners'])->toBe([]);

    // Cora (the current session guest) can change her vote again:
    // her vote moves from the first option to the second.
    $shape = slice4Vote($this, $poll['id'], $second);
    expect($shape['total'])->toBe(3)
        ->and(slice4Option($shape, 0)['votes'])->toBe(0)
        ->and(slice4Option($shape, 1)['votes'])->toBe(3)
        ->and($shape['my_vote'])->toBe($second);
});

test('a closed poll with tied leaders lists every tied option as a winner', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);
    [$first, $second] = [$poll['options'][0]['id'], $poll['options'][1]['id']];

    slice4Enter($this, $guests['Ana Cruz']);
    slice4Vote($this, $poll['id'], $first);
    slice4Enter($this, $guests['Ben Reyes']);
    slice4Vote($this, $poll['id'], $second);

    $this->postJson('/api/host_poll_toggle', ['id' => $poll['id']])->assertOk();

    $listed = $this->postJson('/api/poll_get')->json('polls.0');
    expect($listed['total'])->toBe(2)
        ->and($listed['winners'])->toContain($first)
        ->and($listed['winners'])->toContain($second)
        ->and($listed['winners'])->toHaveCount(2);
});

// ----------------------------------------------------------------------
// Editing and deleting
// ----------------------------------------------------------------------

test('host_poll_update edits, adds, and removes options while kept votes survive', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $poll = slice4CreatePoll($this);
    [$keep, $drop, $alsoKeep] = array_column($poll['options'], 'id');

    slice4Enter($this, $guests['Ana Cruz']);
    slice4Vote($this, $poll['id'], $keep);
    slice4Enter($this, $guests['Ben Reyes']);
    slice4Vote($this, $poll['id'], $drop);

    $updated = $this->postJson('/api/host_poll_update', [
        'id' => $poll['id'],
        'question' => 'Pick the final theme',
        'options' => [
            ['id' => $keep, 'text' => 'Classic Crimson'],
            ['text' => 'Emerald'],
            ['id' => $alsoKeep, 'text' => 'Gold'],
        ],
    ])->assertOk()->json('poll');

    expect($updated['question'])->toBe('Pick the final theme')
        ->and(array_column($updated['options'], 'text'))->toBe(['Classic Crimson', 'Emerald', 'Gold'])
        ->and($updated['total'])->toBe(1)
        ->and(slice4Option($updated, 0)['votes'])->toBe(1)
        ->and(slice4Option($updated, 1)['votes'])->toBe(0)
        ->and(PollOption::find($drop))->toBeNull()
        ->and(Vote::where('poll_id', $poll['id'])->count())->toBe(1);
});

test('host_poll_update rejects an option id from another poll', function () {
    slice4LoginHost($this);
    slice4Party();
    $poll = slice4CreatePoll($this);
    $other = slice4CreatePoll($this, 'Dessert?', ['Cake', 'Pie']);

    $this->postJson('/api/host_poll_update', [
        'id' => $poll['id'],
        'question' => 'Best Christmas theme?',
        'options' => [
            ['id' => $poll['options'][0]['id'], 'text' => 'Classic Red'],
            ['id' => $other['options'][0]['id'], 'text' => 'Cake'],
        ],
    ])->assertStatus(404)->assertJsonPath('error', 'option_not_found');

    expect(Poll::find($poll['id'])->question)->toBe('Best Christmas theme?')
        ->and(PollOption::where('poll_id', $poll['id'])->count())->toBe(3);
});

test('host_poll_delete removes the poll, its options, and its votes; poll_get is newest first', function () {
    slice4LoginHost($this);
    [, $guests] = slice4Party();
    $first = slice4CreatePoll($this, 'First question?', ['A', 'B']);
    $second = slice4CreatePoll($this, 'Second question?', ['C', 'D']);

    slice4Enter($this, $guests['Ana Cruz']);
    slice4Vote($this, $first['id'], $first['options'][0]['id']);

    $listed = $this->postJson('/api/poll_get')->json('polls');
    expect(array_column($listed, 'id'))->toBe([$second['id'], $first['id']]);

    $this->postJson('/api/host_poll_delete', ['id' => $first['id']])
        ->assertOk()->assertJsonPath('ok', true);

    expect(Poll::find($first['id']))->toBeNull()
        ->and(PollOption::where('poll_id', $first['id'])->count())->toBe(0)
        ->and(Vote::where('poll_id', $first['id'])->count())->toBe(0)
        ->and(array_column($this->postJson('/api/poll_get')->json('polls'), 'id'))->toBe([$second['id']]);

    $this->postJson('/api/host_poll_delete', ['id' => $first['id']])
        ->assertStatus(404)
        ->assertJsonPath('error', 'poll_not_found');
});

if (! function_exists('slice4PngDataUrl')) {
    /** A solid blue PNG of the given size, as a data URL. */
    function slice4PngDataUrl(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 60, 220));

        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }
}

test('a poll option can carry a photo, stored as a WebP data URL like wishlist images', function () {
    slice4LoginHost($this);
    slice4Party();

    $poll = slice4CreatePoll($this, 'Which dress colour?', [
        ['text' => 'Cloud Dancer', 'image' => slice4PngDataUrl(24, 24)],
        ['text' => 'Viva Magenta'],
    ]);

    $withPhoto = $poll['options'][0];
    $plain = $poll['options'][1];
    expect($withPhoto['image'])->toStartWith('data:image/webp;base64,')
        ->and($plain['image'])->toBeNull();

    // Guests voting see the same shape (and therefore the photo).
    $listed = $this->postJson('/api/poll_get')->json('polls');
    $guestPoll = collect($listed)->firstWhere('id', $poll['id']);
    expect($guestPoll['options'][0]['image'])->toBe($withPhoto['image']);

    // Broken and oversized uploads are refused with the image codes.
    $this->postJson('/api/host_poll_create', [
        'question' => 'Bad photo?',
        'options' => [
            ['text' => 'A', 'image' => '!!!not-base64!!!'],
            ['text' => 'B'],
        ],
    ])->assertStatus(422)->assertJsonPath('error', 'image_invalid');

    $this->postJson('/api/host_poll_create', [
        'question' => 'Huge photo?',
        'options' => [
            ['text' => 'A', 'image' => str_repeat('A', 14_000_001)],
            ['text' => 'B'],
        ],
    ])->assertStatus(422)->assertJsonPath('error', 'image_too_large');
});

test('editing a poll keeps an untouched photo, null removes it, and a new upload replaces it', function () {
    slice4LoginHost($this);
    slice4Party();

    $poll = slice4CreatePoll($this, 'Pick a swatch', [
        ['text' => 'Red', 'image' => slice4PngDataUrl(16, 16)],
        ['text' => 'Blue'],
    ]);
    $red = $poll['options'][0];
    $blue = $poll['options'][1];
    $original = $red['image'];

    // No image key on the Red entry: the stored photo survives the edit.
    $kept = $this->postJson('/api/host_poll_update', [
        'id' => $poll['id'],
        'question' => 'Pick a swatch!',
        'options' => [
            ['id' => $red['id'], 'text' => 'Deep Red'],
            ['id' => $blue['id'], 'text' => 'Blue'],
        ],
    ])->assertOk()->json('poll');
    expect($kept['options'][0]['image'])->toBe($original)
        ->and($kept['options'][0]['text'])->toBe('Deep Red');

    // A null image removes it.
    $removed = $this->postJson('/api/host_poll_update', [
        'id' => $poll['id'],
        'question' => 'Pick a swatch!',
        'options' => [
            ['id' => $red['id'], 'text' => 'Deep Red', 'image' => null],
            ['id' => $blue['id'], 'text' => 'Blue'],
        ],
    ])->assertOk()->json('poll');
    expect($removed['options'][0]['image'])->toBeNull();

    // A fresh upload replaces it, again stored as WebP.
    $replaced = $this->postJson('/api/host_poll_update', [
        'id' => $poll['id'],
        'question' => 'Pick a swatch!',
        'options' => [
            ['id' => $red['id'], 'text' => 'Deep Red', 'image' => slice4PngDataUrl(20, 20)],
            ['id' => $blue['id'], 'text' => 'Blue'],
        ],
    ])->assertOk()->json('poll');
    expect($replaced['options'][0]['image'])->toStartWith('data:image/webp;base64,');
});
