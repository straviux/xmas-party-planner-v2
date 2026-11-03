<?php

namespace App\Services;

use DomainException;

/**
 * A host admin failure carrying one of the stable error codes.
 *
 * The ApiController turns these into the {ok:false, error:code, message}
 * JSON contract, same as the other service exceptions. Messages are plain
 * language for direct display in the UI.
 */
class AdminException extends DomainException
{
    /** @var array<string, string> error code => plain-language message */
    private const MESSAGES = [
        'password_incorrect' => 'Your current password is not right.',
        'password_too_short' => 'Your new password needs at least 8 characters.',
        'password_mismatch' => 'The new password and its confirmation do not match.',
        'password_same' => 'Your new password must be different from your current one.',
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
