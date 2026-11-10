<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\WishlistItem;
use App\Support\ImageToWebp;
use App\Support\ImageUploadException;

/**
 * Wishlist adds, edits, and deletes. Wishlists are posting only: guests
 * post what they want, and there is no reserving or claiming of items.
 *
 * Images (Hard Rule 7): uploads arrive as base64 and are converted to
 * WebP data URLs by the shared App\Support\ImageToWebp pipeline (the
 * same one poll option photos use). AVIF is never accepted or stored.
 */
class WishlistService
{
    /** Each guest may post at most this many wishes (owner's rule). */
    private const MAX_WISHES = 3;

    /** Each wish may carry at most this many photos (owner's rule, 2026-11-10). */
    public const MAX_IMAGES = 3;

    /**
     * Add an item to the owner's own wishlist. The owner always comes from
     * the guest session; a guest_id in the payload is ignored by the
     * controller and never reaches this method.
     *
     * @param  array{text?: mixed, link?: mixed, notes?: mixed, image?: mixed, images?: mixed}  $data
     *
     * @throws WishlistException
     */
    public function addItem(Guest $owner, array $data): WishlistItem
    {
        // The three-wish cap is a count of what the guest already has;
        // edits and deletes are never blocked by it, and a guest who
        // already holds more (from before the cap) simply cannot add
        // until they are back under it.
        if ($owner->wishlistItems()->count() >= self::MAX_WISHES) {
            throw WishlistException::make('wish_limit');
        }

        $text = $this->nullableText($data['text'] ?? null);
        $link = $this->nullableText($data['link'] ?? null);
        $notes = $this->nullableText($data['notes'] ?? null);

        // Throws too_many_images over three, and image_too_large /
        // image_invalid on a bad upload; a wish with a broken photo
        // is refused rather than stored half-formed.
        $photos = $this->resolveNewPhotos($data, []);

        if ($text === null && $link === null && $photos === []) {
            throw WishlistException::make('wish_empty');
        }

        $link = $this->normalizeLink($link);

        return $owner->wishlistItems()->create([
            'text' => $text,
            'link' => $link,
            'notes' => $notes,
            'kind' => $this->deriveKind($photos[0] ?? null, $link),
            'image' => $photos[0] ?? null,
            'images' => $photos === [] ? null : $photos,
        ]);
    }

    /**
     * Edit one of the owner's own items. Only the keys actually present in
     * $data change; absent keys keep their current values. Photos are
     * special: an absent images key keeps the current set; a present
     * array is the full new set, where each entry is a fresh upload
     * (data URL) or a "keep:N" marker for the Nth current photo, so
     * the form can drop, reorder, and replace slots in one save. The
     * legacy single image key still works the same way it always did
     * (absent keeps, null clears all, string replaces the set with
     * that one photo). After the edit the wish must still have a
     * text, a link, or a photo, same as an add. Nothing is saved
     * when the edit is refused.
     *
     * @param  array{text?: mixed, link?: mixed, notes?: mixed, image?: mixed, images?: mixed}  $data
     *
     * @throws WishlistException
     */
    public function updateItem(Guest $actor, WishlistItem $item, array $data): WishlistItem
    {
        if ((int) $item->guest_id !== (int) $actor->id) {
            throw WishlistException::make('forbidden', 403);
        }

        $text = array_key_exists('text', $data) ? $this->nullableText($data['text']) : $item->text;
        $link = array_key_exists('link', $data) ? $this->nullableText($data['link']) : $item->link;
        $notes = array_key_exists('notes', $data) ? $this->nullableText($data['notes']) : $item->notes;

        $photos = $item->photos();
        if (is_array($data['images'] ?? null)) {
            $photos = $this->resolveNewPhotos($data, $photos);
        } elseif (array_key_exists('image', $data)) {
            $imageInput = $data['image'];
            if ($imageInput === null) {
                $photos = [];
            } elseif (is_string($imageInput) && trim($imageInput) !== '') {
                // Throws image_too_large / image_invalid on a bad upload.
                $photos = [$this->processImage($imageInput)];
            }
            // A blank or non-string image value means "no new photo":
            // the current set stays, same as the add path treats it.
        }

        if ($text === null && $link === null && $photos === []) {
            throw WishlistException::make('wish_empty');
        }

        $link = $this->normalizeLink($link);

        $item->update([
            'text' => $text,
            'link' => $link,
            'notes' => $notes,
            'kind' => $this->deriveKind($photos[0] ?? null, $link),
            'image' => $photos[0] ?? null,
            'images' => $photos === [] ? null : $photos,
        ]);

        return $item;
    }

    /**
     * Resolve the photo set a payload asks for. The images array wins
     * when present; otherwise a non-blank legacy image string is a
     * one-photo set and anything else is no photos. Entries resolve
     * against $current: "keep:N" reuses the Nth current photo, any
     * other string is a fresh upload through the WebP pipeline, and
     * null entries are dropped slots.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $current
     * @return list<string>
     *
     * @throws WishlistException
     */
    private function resolveNewPhotos(array $data, array $current): array
    {
        if (is_array($data['images'] ?? null)) {
            $entries = array_values($data['images']);
        } elseif (is_string($data['image'] ?? null) && trim($data['image']) !== '') {
            $entries = [$data['image']];
        } else {
            return [];
        }

        $entries = array_filter($entries, fn ($entry) => $entry !== null);
        if (count($entries) > self::MAX_IMAGES) {
            throw WishlistException::make('too_many_images');
        }

        $photos = [];
        foreach ($entries as $entry) {
            if (is_string($entry) && preg_match('/^keep:(\d+)$/', $entry, $m) === 1) {
                $kept = $current[(int) $m[1]] ?? null;
                if ($kept === null) {
                    throw WishlistException::make('image_invalid');
                }
                $photos[] = $kept;
            } elseif (is_string($entry) && trim($entry) !== '') {
                $photos[] = $this->processImage($entry);
            }
            // Blank or non-string entries are ignored, matching how
            // the single-photo paths treat a malformed image value.
        }

        return $photos;
    }

    /** Bare links get https:// prepended, same as an add. */
    private function normalizeLink(?string $link): ?string
    {
        if ($link !== null && preg_match('#^https?://#i', $link) !== 1) {
            return 'https://'.$link;
        }

        return $link;
    }

    /** Kind follows what the wish shows first: image, then link, then text. */
    private function deriveKind(?string $image, ?string $link): string
    {
        return $image !== null ? 'image' : ($link !== null ? 'link' : 'text');
    }

    /**
     * Only the owner may delete their own items (A7: add/delete check the
     * session guest, so the controller passes the session guest as actor).
     *
     * @throws WishlistException
     */
    public function deleteItem(Guest $actor, WishlistItem $item): void
    {
        if ((int) $item->guest_id !== (int) $actor->id) {
            throw WishlistException::make('forbidden', 403);
        }

        $item->delete();
    }

    /**
     * Convert an uploaded base64 image through the shared pipeline,
     * mapping its failures onto this service's error contract.
     *
     * @throws WishlistException
     */
    private function processImage(string $image): string
    {
        try {
            return ImageToWebp::convert($image);
        } catch (ImageUploadException $e) {
            throw WishlistException::make($e->errorCode);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
