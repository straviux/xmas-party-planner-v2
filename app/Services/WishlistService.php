<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\WishlistItem;

/**
 * Wishlist adds and deletes. Wishlists are posting only: guests post what
 * they want, and there is no reserving or claiming of items.
 *
 * Images (Hard Rule 7): uploads arrive as base64 and are always converted
 * to WebP on save (max side 1600px, quality 85, alpha preserved) and
 * stored as data URLs. AVIF is never accepted or stored.
 */
class WishlistService
{
    /** Server cap on the submitted base64 image string (spec). */
    private const IMAGE_CHAR_CAP = 14_000_000;

    private const IMAGE_MAX_SIDE = 1600;

    private const IMAGE_QUALITY = 85;

    /** @var array<int, int> GD image types accepted on upload (spec) */
    private const ACCEPTED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Add an item to the owner's own wishlist. The owner always comes from
     * the guest session; a guest_id in the payload is ignored by the
     * controller and never reaches this method.
     *
     * @param  array{text?: mixed, link?: mixed, notes?: mixed, image?: mixed}  $data
     *
     * @throws WishlistException
     */
    public function addItem(Guest $owner, array $data): WishlistItem
    {
        $text = $this->nullableText($data['text'] ?? null);
        $link = $this->nullableText($data['link'] ?? null);
        $notes = $this->nullableText($data['notes'] ?? null);

        $imageInput = $data['image'] ?? null;
        $hasImage = is_string($imageInput) && trim($imageInput) !== '';

        if ($text === null && $link === null && ! $hasImage) {
            throw WishlistException::make('wish_empty');
        }

        // Throws image_too_large / image_invalid on a bad upload; a wish
        // with a broken image is refused rather than stored half-formed.
        $image = $hasImage ? $this->processImage($imageInput) : null;

        if ($link !== null && preg_match('#^https?://#i', $link) !== 1) {
            $link = 'https://'.$link;
        }

        $kind = $image !== null ? 'image' : ($link !== null ? 'link' : 'text');

        return $owner->wishlistItems()->create([
            'text' => $text,
            'link' => $link,
            'notes' => $notes,
            'kind' => $kind,
            'image' => $image,
        ]);
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
     * Convert an uploaded base64 image to a WebP data URL.
     *
     * @throws WishlistException
     */
    private function processImage(string $image): string
    {
        if (strlen($image) > self::IMAGE_CHAR_CAP) {
            throw WishlistException::make('image_too_large');
        }

        $payload = trim($image);
        if (str_starts_with(strtolower($payload), 'data:')) {
            $comma = strpos($payload, ',');
            if ($comma === false) {
                throw WishlistException::make('image_invalid');
            }
            $payload = substr($payload, $comma + 1);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);
        if ($binary === false || $binary === '') {
            throw WishlistException::make('image_invalid');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], self::ACCEPTED_TYPES, true)) {
            throw WishlistException::make('image_invalid');
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw WishlistException::make('image_invalid');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // Resize down only: the longest side never exceeds the cap and
        // smaller images are never upscaled.
        $scale = min(1.0, self::IMAGE_MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // Always draw onto a true-color canvas with a transparent fill so
        // palette images (GIF, PNG-8) keep their alpha through the resize.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw WishlistException::make('image_invalid');
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $encoded = imagewebp($canvas, null, self::IMAGE_QUALITY);
        $webp = ob_get_clean();

        if ($encoded !== true || $webp === false || $webp === '') {
            throw WishlistException::make('image_invalid');
        }

        return 'data:image/webp;base64,'.base64_encode($webp);
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
