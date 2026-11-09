<?php

namespace App\Support;

use DomainException;

/**
 * A failed upload conversion in ImageToWebp, carrying one of the
 * stable image error codes (image_too_large / image_invalid). The
 * owning service catches it and rethrows as its own exception type
 * (WishlistException, PollException), which keeps each API surface's
 * error contract unchanged.
 */
class ImageUploadException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }

    public static function make(string $errorCode): self
    {
        return new self($errorCode);
    }
}
