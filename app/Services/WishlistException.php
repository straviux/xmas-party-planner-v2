<?php

namespace App\Services;

use DomainException;

/**
 * A wishlist failure carrying one of the stable error codes.
 *
 * The ApiController turns these into the {ok:false, error:code, message}
 * JSON contract, same as GuestEntryException. Messages are plain language
 * for direct display in the UI.
 */
class WishlistException extends DomainException
{
    /** @var array<string, string> error code => plain-language message */
    private const MESSAGES = [
        'wish_empty' => 'Add some text, a link, or a screenshot first.',
        'wish_limit' => 'You can post up to three wishes. Delete one to add another.',
        'too_many_images' => 'A wish can have up to three photos.',
        'image_too_large' => 'That image is too large. Try a smaller screenshot.',
        'image_invalid' => 'That image could not be read. Try a PNG, JPEG, GIF, or WebP image.',
        'forbidden' => 'You can only change your own wishlist.',
    ];

    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct(self::MESSAGES[$errorCode] ?? $errorCode);
    }

    public static function make(string $errorCode, int $status = 422): self
    {
        return new self($errorCode, $status);
    }
}
