<?php

namespace App\Support;

/**
 * The one upload pipeline (Hard Rule 7): a base64 image becomes a
 * WebP data URL, max side 1600px, quality 85, alpha preserved. AVIF
 * is never accepted or stored. Shared by wishlist items and poll
 * option photos so both store identical formats.
 */
class ImageToWebp
{
    /** Server cap on the submitted base64 image string (spec). */
    private const IMAGE_CHAR_CAP = 14_000_000;

    private const IMAGE_MAX_SIDE = 1600;

    private const IMAGE_QUALITY = 85;

    /** @var array<int, int> GD image types accepted on upload (spec) */
    private const ACCEPTED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Convert an uploaded base64 image to a WebP data URL.
     *
     * @throws ImageUploadException image_too_large / image_invalid
     */
    public static function convert(string $image): string
    {
        if (strlen($image) > self::IMAGE_CHAR_CAP) {
            throw ImageUploadException::make('image_too_large');
        }

        $payload = trim($image);
        if (str_starts_with(strtolower($payload), 'data:')) {
            $comma = strpos($payload, ',');
            if ($comma === false) {
                throw ImageUploadException::make('image_invalid');
            }
            $payload = substr($payload, $comma + 1);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);
        if ($binary === false || $binary === '') {
            throw ImageUploadException::make('image_invalid');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], self::ACCEPTED_TYPES, true)) {
            throw ImageUploadException::make('image_invalid');
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw ImageUploadException::make('image_invalid');
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
            throw ImageUploadException::make('image_invalid');
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
            throw ImageUploadException::make('image_invalid');
        }

        return 'data:image/webp;base64,'.base64_encode($webp);
    }
}
