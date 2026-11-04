<?php

use App\Models\Guest;
use App\Models\Party;
use App\Models\WishlistItem;

if (! function_exists('slice3Party')) {
    /**
     * A party with three pin-less guests.
     *
     * @return array{0: Party, 1: array<string, Guest>} guests keyed by display
     */
    function slice3Party(): array
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

if (! function_exists('slice3Enter')) {
    /** Enter as a guest through the real action, creating PIN 1234 on first entry. */
    function slice3Enter(object $test, Guest $guest): void
    {
        $payload = ['guest_id' => $guest->id, 'pin' => '1234'];
        if (empty($guest->fresh()->pin_hash)) {
            $payload['pin_confirmation'] = '1234';
        }

        $test->postJson('/api/enter', $payload)->assertOk();
    }
}

if (! function_exists('slice3Add')) {
    /** Add an item as the current session guest and return its board shape. */
    function slice3Add(object $test, array $data): array
    {
        $response = $test->postJson('/api/wishlist_add', $data);
        $response->assertOk()->assertJsonPath('ok', true);

        return $response->json('item');
    }
}

if (! function_exists('slice3PngDataUrl')) {
    /** A solid red PNG of the given size, as a data URL. */
    function slice3PngDataUrl(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 220, 30, 30));

        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }
}

if (! function_exists('slice3StoredImageInfo')) {
    /** getimagesizefromstring() info for a stored WebP data URL. */
    function slice3StoredImageInfo(string $dataUrl): array
    {
        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);

        return getimagesizefromstring($binary);
    }
}

if (! function_exists('slice3KeysRecursive')) {
    /** @return array<int, string> */
    function slice3KeysRecursive(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = [...$keys, ...slice3KeysRecursive($value)];
            }
        }

        return $keys;
    }
}

// ----------------------------------------------------------------------
// Adding items and kind derivation
// ----------------------------------------------------------------------

test('wishlist_add derives kind from what is present and normalizes bare links', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $text = slice3Add($this, ['text' => 'A good book']);
    expect($text['kind'])->toBe('text')
        ->and(array_keys($text))->not->toContain('reserved')
        ->and(array_keys($text))->not->toContain('reserved_by_me');

    $link = slice3Add($this, ['link' => 'example.com/headphones']);
    expect($link['kind'])->toBe('link')
        ->and($link['link'])->toBe('https://example.com/headphones');

    $both = slice3Add($this, ['text' => 'Headphones', 'link' => 'https://shop.example.com/h']);
    expect($both['kind'])->toBe('link');

    $image = slice3Add($this, ['text' => 'This one', 'image' => slice3PngDataUrl(12, 12)]);
    expect($image['kind'])->toBe('image');

    expect(array_keys($text))->not->toContain('reserved_by_id');
});

test('wishlist_add with nothing to show is wish_empty, and notes alone do not count', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $this->postJson('/api/wishlist_add', [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'wish_empty');

    $this->postJson('/api/wishlist_add', ['text' => '  ', 'link' => '', 'notes' => 'Size 9, please'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'wish_empty');

    expect(WishlistItem::count())->toBe(0);
});

test('wishlist actions need a guest session', function () {
    slice3Party();

    $this->postJson('/api/wishlist_add', ['text' => 'Book'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
    $this->postJson('/api/wishlist_delete', ['id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_claimed');
});

test('wishlist_add always lands on the session guest list, ignoring a payload guest_id', function () {
    [, $guests] = slice3Party();
    $ana = $guests['Ana Cruz'];
    slice3Enter($this, $ana);

    $item = slice3Add($this, ['text' => 'Mine', 'guest_id' => $guests['Ben Reyes']->id]);

    expect(WishlistItem::find($item['id'])->guest_id)->toBe($ana->id);
});

// ----------------------------------------------------------------------
// Images: WebP conversion (Hard Rule 7)
// ----------------------------------------------------------------------

test('an uploaded PNG is stored as a WebP data URL at its original size', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $item = slice3Add($this, ['image' => slice3PngDataUrl(40, 20)]);

    expect($item['image'])->toStartWith('data:image/webp;base64,');

    $info = slice3StoredImageInfo($item['image']);
    expect($info[0])->toBe(40)
        ->and($info[1])->toBe(20)
        ->and($info[2])->toBe(IMAGETYPE_WEBP);

    // Raw base64 without a data-URL prefix is accepted too.
    $raw = substr(slice3PngDataUrl(10, 10), strlen('data:image/png;base64,'));
    $second = slice3Add($this, ['image' => $raw]);
    expect($second['image'])->toStartWith('data:image/webp;base64,');
});

test('a large image is resized so its longest side is 1600', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $item = slice3Add($this, ['image' => slice3PngDataUrl(2000, 100)]);

    $info = slice3StoredImageInfo($item['image']);
    expect($info[0])->toBe(1600)
        ->and($info[1])->toBe(80)
        ->and($info[2])->toBe(IMAGETYPE_WEBP);
});

test('broken and non-image uploads are image_invalid', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $this->postJson('/api/wishlist_add', ['image' => '!!!not-base64!!!'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'image_invalid');

    $this->postJson('/api/wishlist_add', ['image' => base64_encode('these are just words, not an image')])
        ->assertStatus(422)
        ->assertJsonPath('error', 'image_invalid');

    expect(WishlistItem::count())->toBe(0);
});

test('an image string over the server cap is image_too_large', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $this->postJson('/api/wishlist_add', ['image' => str_repeat('A', 14_000_001)])
        ->assertStatus(422)
        ->assertJsonPath('error', 'image_too_large');

    expect(WishlistItem::count())->toBe(0);
});

// ----------------------------------------------------------------------
// Deleting
// ----------------------------------------------------------------------

test('only the owner can delete an item', function () {
    [, $guests] = slice3Party();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    slice3Enter($this, $ana);
    $item = slice3Add($this, ['text' => 'Ana wish']);

    $this->postJson('/api/wishlist_delete', ['id' => 9999])
        ->assertStatus(404)
        ->assertJsonPath('error', 'wish_not_found');

    $this->postJson('/api/guest_logout')->assertOk();
    slice3Enter($this, $ben);

    $this->postJson('/api/wishlist_delete', ['id' => $item['id']])
        ->assertStatus(403)
        ->assertJsonPath('error', 'forbidden');
    expect(WishlistItem::find($item['id']))->not->toBeNull();

    $this->postJson('/api/guest_logout')->assertOk();
    slice3Enter($this, $ana);

    $this->postJson('/api/wishlist_delete', ['id' => $item['id']])
        ->assertOk()
        ->assertJsonPath('ok', true);
    expect(WishlistItem::find($item['id']))->toBeNull();

    $board = $this->postJson('/api/wishlist_list')->assertOk()->json('guests');
    $anaBoard = collect($board)->firstWhere('id', $ana->id);
    expect($anaBoard['items'])->toBe([])
        ->and($anaBoard['wish_count'])->toBe(0);
});

// ----------------------------------------------------------------------
// Posting only: there is no reserve feature
// ----------------------------------------------------------------------

test('wishlist_reserve is gone and answers unknown_action like any unregistered action', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);
    $item = slice3Add($this, ['text' => 'Ana wish']);

    $this->postJson('/api/wishlist_reserve', ['id' => $item['id']])
        ->assertStatus(404)
        ->assertJsonPath('error', 'unknown_action');
});

test('wishlist payloads carry no reserve state, even over a populated dormant column', function () {
    [, $guests] = slice3Party();
    $ana = $guests['Ana Cruz'];
    $ben = $guests['Ben Reyes'];

    slice3Enter($this, $ana);
    $item = slice3Add($this, ['text' => 'Ana wish']);

    // Legacy rows can still carry a reserver in the dormant reserved_by_id
    // column; that must never surface as reserve state (or at all).
    WishlistItem::findOrFail($item['id'])->update(['reserved_by_id' => $ben->id]);

    $this->postJson('/api/guest_logout')->assertOk();
    slice3Enter($this, $ben);
    $board = $this->postJson('/api/wishlist_list')->assertOk();

    $anaBoard = collect($board->json('guests'))->firstWhere('id', $ana->id);
    expect(array_keys($anaBoard['items'][0]))
        ->not->toContain('reserved')
        ->not->toContain('reserved_by_me');

    $keys = slice3KeysRecursive($board->json());
    expect($keys)->not->toContain('reserved')
        ->and($keys)->not->toContain('reserved_by_me')
        ->and($keys)->not->toContain('reserved_by_id')
        ->and($keys)->not->toContain('recipient_id')
        ->and($keys)->not->toContain('pin_hash');
});

// ----------------------------------------------------------------------
// Board read + member page
// ----------------------------------------------------------------------

test('wishlist_list with no party is an empty board, not an error', function () {
    $this->postJson('/api/wishlist_list')
        ->assertOk()
        ->assertJsonPath('party', null)
        ->assertJsonPath('guests', []);
});

test('wishlist_list groups items under their owners, newest first', function () {
    [, $guests] = slice3Party();
    $ana = $guests['Ana Cruz'];

    slice3Enter($this, $ana);
    slice3Add($this, ['text' => 'First wish']);
    slice3Add($this, ['text' => 'Second wish']);

    $board = $this->postJson('/api/wishlist_list')->assertOk();
    $anaBoard = collect($board->json('guests'))->firstWhere('id', $ana->id);
    $benBoard = collect($board->json('guests'))->firstWhere('id', $guests['Ben Reyes']->id);

    expect($anaBoard['wish_count'])->toBe(2)
        ->and(array_column($anaBoard['items'], 'text'))->toBe(['Second wish', 'First wish'])
        ->and($benBoard['wish_count'])->toBe(0)
        ->and($benBoard['items'])->toBe([]);
});

test('the member wishlist page renders for a revealed guest and unknown members go back to the board', function () {
    [, $guests] = slice3Party();
    $ana = $guests['Ana Cruz'];

    slice3Enter($this, $ana);
    $this->postJson('/api/reveal')->assertOk();

    $this->get('/guest/wishlists/'.$guests['Ben Reyes']->id)->assertOk();
    $this->get('/guest/wishlists/9999')->assertRedirect('/guest/wishlists');
    $this->get('/guest/wishlists/not-a-number')->assertRedirect('/guest/wishlists');
});
