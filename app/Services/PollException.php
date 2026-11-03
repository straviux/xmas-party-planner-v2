<?php

namespace App\Services;

use DomainException;

/**
 * A poll failure carrying one of the stable error codes.
 *
 * The ApiController turns these into the {ok:false, error:code, message}
 * JSON contract, same as GuestEntryException and WishlistException.
 * Messages are plain language for direct display in the UI.
 */
class PollException extends DomainException
{
    /** @var array<string, string> error code => plain-language message */
    private const MESSAGES = [
        'poll_question_required' => 'Type a question for the poll first.',
        'poll_needs_options' => 'A poll needs at least two options to choose from.',
        'poll_not_found' => 'That poll could not be found.',
        'option_not_found' => 'That option could not be found in this poll.',
        'poll_closed' => 'This poll is closed, so votes can no longer be changed.',
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
