<?php

namespace App\Services;

use DomainException;

/**
 * A guest-flow failure carrying one of the spec's stable error codes.
 *
 * The ApiController turns these into the {ok:false, error:code, message}
 * JSON contract. Messages are plain language for direct display in the UI.
 */
class GuestEntryException extends DomainException
{
    /** @var array<string, string> error code => plain-language message */
    private const MESSAGES = [
        'pin_incorrect' => 'That PIN does not match. Try again.',
        'pin_required_create' => 'Create a 4-digit PIN to continue.',
        'pin_must_be_4_digits' => 'Your PIN must be exactly 4 digits.',
        'pin_mismatch' => 'The two PINs do not match.',
        'pin_already_set' => 'A PIN is already set for this name.',
        'not_claimed' => 'Find and confirm your name first.',
        'already_claimed' => 'This name has already revealed its recipient.',
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
