<?php

namespace App\Support;

use App\Models\Guest;
use App\Models\Party;
use App\Models\Poll;
use App\Models\WishlistItem;

/**
 * Allowlist response shapes.
 *
 * Hard Rule 4: recipient mappings and PIN hashes must never leak. Models are
 * never returned raw; every API payload is built here, key by key. If a field
 * is not listed in this class, it does not leave the server.
 */
class Serializers
{
    /**
     * Public guest shape (spec): id, display, nickname, aliases, claimed,
     * needs_pin, revealed. Never recipient_id or pin_hash.
     */
    public static function publicGuest(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'display' => $guest->display,
            'nickname' => $guest->nickname,
            'aliases' => $guest->aliases ?? [],
            'claimed' => (bool) $guest->claimed,
            'needs_pin' => empty($guest->pin_hash),
            'revealed' => (bool) $guest->revealed,
        ];
    }

    /**
     * Host guest shape: the public shape plus pin_set and a plain status.
     * The host is blind to recipients too, so still no recipient data.
     */
    public static function hostGuest(Guest $guest): array
    {
        return [
            ...self::publicGuest($guest),
            'pin_set' => ! empty($guest->pin_hash),
            'status' => $guest->claimed ? 'locked' : 'awaiting',
        ];
    }

    public static function partyPublic(?Party $party): ?array
    {
        if ($party === null) {
            return null;
        }

        return [
            'id' => $party->id,
            'name' => $party->name,
            'date' => $party->date?->format('Y-m-d'),
            'budget' => $party->budget,
        ];
    }

    public static function partyHost(?Party $party): ?array
    {
        if ($party === null) {
            return null;
        }

        return [
            ...self::partyPublic($party),
            'invite_code' => $party->invite_code,
        ];
    }

    /**
     * Poll shape (spec): id, question, open, total,
     * options [{id, text, votes}], my_vote. Counts and percentages only in
     * the UI; never voter names or voter IDs.
     *
     * @param  int|null  $myVoteOptionId  poll_option_id the viewing guest voted for, if any.
     */
    public static function pollShape(Poll $poll, ?int $myVoteOptionId = null): array
    {
        $options = $poll->relationLoaded('options')
            ? $poll->options
            : $poll->options()->withCount('votes')->get();

        $shapedOptions = $options->map(fn ($option) => [
            'id' => $option->id,
            'text' => $option->text,
            'votes' => (int) ($option->votes_count ?? $option->votes()->count()),
        ])->values()->all();

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'open' => (bool) $poll->open,
            'total' => array_sum(array_column($shapedOptions, 'votes')),
            'options' => $shapedOptions,
            'my_vote' => $myVoteOptionId,
        ];
    }

    /**
     * Wishlist board item shape (spec): id, text, link, notes, kind, image,
     * reserved, reserved_by_me. Never reserved_by_id.
     */
    public static function wishlistItem(WishlistItem $item, ?int $viewerGuestId = null): array
    {
        return [
            'id' => $item->id,
            'text' => $item->text,
            'link' => $item->link,
            'notes' => $item->notes,
            'kind' => $item->kind,
            'image' => $item->image,
            'reserved' => $item->reserved_by_id !== null,
            'reserved_by_me' => $viewerGuestId !== null
                && $item->reserved_by_id !== null
                && (int) $item->reserved_by_id === $viewerGuestId,
        ];
    }
}
