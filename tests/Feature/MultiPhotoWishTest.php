<?php

use App\Models\WishlistItem;

/*
 * Up to three photos per wish (owner's ask, 2026-11-10). The photos
 * are a JSON list of WebP data URLs; `image` mirrors the cover. The
 * payload ships one URL per photo behind the image route.
 */

test('a wish can be added with three photos, served individually', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $item = slice3Add($this, [
        'text' => 'Three angles',
        'images' => [slice3PngDataUrl(12, 12), slice3PngDataUrl(14, 14), slice3PngDataUrl(16, 16)],
    ]);

    $stored = WishlistItem::find($item['id']);
    expect($stored->photos())->toHaveCount(3)
        ->and($stored->image)->toBe($stored->photos()[0])
        ->and($item['images'])->toHaveCount(3)
        ->and($item['image'])->toBe($item['images'][0]);

    // Each photo serves its own bytes behind its own URL.
    foreach ($item['images'] as $i => $url) {
        $response = $this->get($url)->assertOk();
        expect($response->getContent())
            ->toBe(base64_decode(substr($stored->photos()[$i], strlen('data:image/webp;base64,'))));
    }
    // The bare address still means the cover photo.
    $this->get('/wishlist-items/'.$item['id'].'/image')->assertOk();
    // And a fourth slot does not exist.
    $this->get('/wishlist-items/'.$item['id'].'/image/3')->assertNotFound();
});

test('a fourth photo is refused on add and on edit', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $four = [slice3PngDataUrl(8, 8), slice3PngDataUrl(9, 9), slice3PngDataUrl(10, 10), slice3PngDataUrl(11, 11)];
    $this->postJson('/api/wishlist_add', ['text' => 'Too many', 'images' => $four])
        ->assertStatus(422)
        ->assertJsonPath('error', 'too_many_images');

    $item = slice3Add($this, ['text' => 'One', 'images' => [slice3PngDataUrl(8, 8)]]);
    $this->postJson('/api/wishlist_update', ['item_id' => $item['id'], 'images' => $four])
        ->assertStatus(422)
        ->assertJsonPath('error', 'too_many_images');
    expect(WishlistItem::find($item['id'])->photos())->toHaveCount(1);
});

test('edits can keep, drop, reorder, and replace photos in one save', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $item = slice3Add($this, [
        'text' => 'Gallery',
        'images' => [slice3PngDataUrl(12, 12), slice3PngDataUrl(14, 14), slice3PngDataUrl(16, 16)],
    ]);
    $before = WishlistItem::find($item['id'])->photos();

    // Keep the third, drop the rest, add a fresh one in front.
    $res = $this->postJson('/api/wishlist_update', [
        'item_id' => $item['id'],
        'images' => [slice3PngDataUrl(20, 20), 'keep:2'],
    ])->assertOk();

    $after = WishlistItem::find($item['id']);
    expect($after->photos())->toHaveCount(2)
        ->and($after->photos()[1])->toBe($before[2])
        ->and($after->image)->toBe($after->photos()[0])
        ->and($res->json('item.images'))->toHaveCount(2);

    // An edit that does not mention photos keeps the set untouched.
    $this->postJson('/api/wishlist_update', ['item_id' => $item['id'], 'text' => 'Renamed'])
        ->assertOk();
    expect(WishlistItem::find($item['id'])->photos())->toBe($after->photos());

    // An empty set clears the photos (with text present, the wish lives).
    $this->postJson('/api/wishlist_update', ['item_id' => $item['id'], 'images' => []])
        ->assertOk();
    $cleared = WishlistItem::find($item['id']);
    expect($cleared->photos())->toBe([])
        ->and($cleared->image)->toBeNull()
        ->and($cleared->kind)->toBe('text');
});

test('the legacy single image key still adds and replaces one photo', function () {
    [, $guests] = slice3Party();
    slice3Enter($this, $guests['Ana Cruz']);

    $item = slice3Add($this, ['text' => 'Legacy', 'image' => slice3PngDataUrl(12, 12)]);
    expect(WishlistItem::find($item['id'])->photos())->toHaveCount(1);

    $this->postJson('/api/wishlist_update', ['item_id' => $item['id'], 'image' => null])
        ->assertOk();
    expect(WishlistItem::find($item['id'])->photos())->toBe([]);
});
