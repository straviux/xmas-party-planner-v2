<?php

namespace App\Services;

use DomainException;

/**
 * A Monito Monita failure carrying one of the feature's stable error
 * codes. The ApiController turns these into the {ok:false, error:code,
 * message} JSON contract, same as GuestEntryException.
 */
class KringleException extends DomainException
{
    /** @var array<string, string> error code => plain-language message */
    private const MESSAGES = [
        'not_claimed' => 'Find and confirm your name first.',
        'pin_incorrect' => 'That PIN does not match. Try again.',
        'kringle_inactive' => 'Monito Monita is not running this week.',
        'kringle_already_spun' => 'You have already spun for this week.',
        'kringle_not_enough_guests' => 'Monito Monita needs at least two guests at the party.',
        'kringle_no_one_left' => 'Everyone else has been drawn this week. There is nobody left to draw.',
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
