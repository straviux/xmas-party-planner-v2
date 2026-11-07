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
     * needs_pin, revealed, has_secret_name. Never recipient_id or pin_hash.
     * The secret name's VALUE never ships in this shape either: only the
     * has_secret_name flag, so guests can be nudged to pick one without
     * anyone's codename leaking into the public list.
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
            'has_secret_name' => is_string($guest->secret_name) && $guest->secret_name !== '',
        ];
    }

    /**
     * Host guest shape: the public shape plus pin_set, the guest's secret
     * name (the host hands out nudges and checks coverage, so the value
     * itself is visible here), and a plain status. The host is blind to
     * recipients too, so still no recipient data.
     */
    public static function hostGuest(Guest $guest): array
    {
        return [
            ...self::publicGuest($guest),
            'pin_set' => ! empty($guest->pin_hash),
            'secret_name' => $guest->secret_name,
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
     * options [{id, text, votes}], my_vote, winners. Counts and percentages
     * only in the UI; never voter names or voter IDs.
     *
     * winners lists the option ids tied at the highest vote count, but
     * only once the poll is closed and at least one vote exists (A9);
     * it is empty while the poll is open.
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

        $total = array_sum(array_column($shapedOptions, 'votes'));

        $winners = [];
        if (! (bool) $poll->open && $total > 0) {
            $max = max(array_column($shapedOptions, 'votes'));
            $winners = array_values(array_map(
                fn ($option) => $option['id'],
                array_filter($shapedOptions, fn ($option) => $option['votes'] === $max),
            ));
        }

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'open' => (bool) $poll->open,
            'total' => $total,
            'options' => $shapedOptions,
            'my_vote' => $myVoteOptionId,
            'winners' => $winners,
        ];
    }

    /**
     * Reveal recipient shape: id, secret_name.
     *
     * This is the ONLY shape that ever carries a recipient. It is used
     * solely by the `reveal`, `reveal_again`, and
     * `guest_recipient_wishlist` responses, all scoped to the session
     * guest's own recipient. It carries the recipient's secret name
     * (their self-chosen codename), or null when they have not picked
     * one yet: the recipient's REAL name must never appear here, so
     * identities stay hidden until the gift exchange.
     */
    public static function revealRecipient(Guest $recipient): array
    {
        return [
            'id' => (int) $recipient->id,
            'secret_name' => is_string($recipient->secret_name) && $recipient->secret_name !== ''
                ? $recipient->secret_name
                : null,
        ];
    }

    /**
     * Roulette wheel names for the reveal page: the party's secret
     * names as a flat list of strings, shuffled, with no guest ids and
     * no mapping of any kind. The wheel cycles through codenames while
     * it spins; the list is the whole party's codenames (the same set
     * the wishlist board already shows any claimed guest), never the
     * live pool of unpicked recipients, so it cannot leak who has or
     * has not been drawn. Guests without a codename contribute
     * nothing. The recipient themselves only ever arrives via
     * revealRecipient(), in the reveal response.
     *
     * @return array<int, string>
     */
    public static function wheelNames(Party $party): array
    {
        $names = $party->guests()
            ->whereNotNull('secret_name')
            ->pluck('secret_name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values()
            ->all();

        shuffle($names);

        return $names;
    }

    /**
     * Wishlist member shape: id, secret_name.
     *
     * Guest-facing wishlist surfaces (the `wishlist_list` board entries
     * and the member-wishlist page's `member` prop) identify each wisher
     * by their secret name (codename) only: the wisher's real display
     * name and nickname must never ship in a wishlist payload, so the
     * board cannot out who is who before the gift exchange. A wisher who
     * has not picked a codename yet serializes as null, same as the
     * reveal shape. This is the one guest-facing context where the secret
     * name VALUE ships; everywhere else public payloads carry only the
     * has_secret_name flag (publicGuest).
     */
    public static function wishlistMember(Guest $guest): array
    {
        return [
            'id' => (int) $guest->id,
            'secret_name' => is_string($guest->secret_name) && $guest->secret_name !== ''
                ? $guest->secret_name
                : null,
        ];
    }

    /**
     * Wishlist board item shape (spec): id, text, link, notes, kind, image.
     * Wishlists are posting only, so there is no reserve state. Never
     * reserved_by_id.
     */
    public static function wishlistItem(WishlistItem $item): array
    {
        return [
            'id' => $item->id,
            'text' => $item->text,
            'link' => $item->link,
            'notes' => $item->notes,
            'kind' => $item->kind,
            'image' => $item->image,
        ];
    }
}
