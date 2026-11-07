<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;
use App\Models\WishlistItem;
use App\Support\Serializers;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Guest entry, PIN handling, secret names, and the once-only reveal.
 *
 * Secrecy contract (Hard Rule 4): nothing in this service ever returns,
 * stores in the session, or logs a recipient, except reveal() — the
 * response that carries a recipient on first reveal, only once per
 * guest — revealAgain(), the named exception: the same guest, in their
 * own session, after PIN verification, and only once they have already
 * revealed — and recipientWishlist(), the second named exception: the
 * same guest's recipient codename plus that recipient's wishlist items,
 * in their own session, only once they have already revealed (no fresh
 * PIN: the session itself is PIN-verified at entry). Even those
 * responses carry only the recipient's secret name, never their real
 * name. A guest's OWN secret name is kept
 * under the same lock: it is never sent to the guest home page, and only
 * viewSecretName() returns it, after PIN verification. The session
 * stores guest_id + a pin_verified flag only (D2), never the plain PIN.
 */
class GuestEntryService
{
    /**
     * Lowercase, strip accents, collapse whitespace, trim. Used on both the
     * search query and the names it is compared against, so matching is
     * case-, accent-, and spacing-insensitive (A13).
     */
    public function normalize(string $value): string
    {
        $value = Str::transliterate($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Find a party's guests by display name, nickname, or alias.
     *
     * A guest matches when the normalized query is a substring of any of
     * those names, or when it is one letter off (Levenshtein distance <= 1)
     * from a name token of 4+ letters. An empty query returns everyone.
     * Results are ordered by display name.
     *
     * @return Collection<int, Guest>
     */
    public function search(Party $party, string $query): Collection
    {
        $guests = $party->guests()->orderBy('display')->get();

        $needle = $this->normalize($query);
        if ($needle === '') {
            return $guests;
        }

        return $guests
            ->filter(fn (Guest $guest) => $this->matches($guest, $needle))
            ->values();
    }

    private function matches(Guest $guest, string $needle): bool
    {
        $names = array_filter(
            [$guest->display, $guest->nickname, ...($guest->aliases ?? [])],
            fn ($name) => is_string($name) && $name !== '',
        );

        foreach ($names as $name) {
            $normalized = $this->normalize($name);

            if ($normalized !== '' && str_contains($normalized, $needle)) {
                return true;
            }

            if ($this->oneLetterOff($needle, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the query (or any of its words) is within one edit of a
     * name token of 4+ letters, tolerating a single-letter typo.
     */
    private function oneLetterOff(string $needle, string $normalizedName): bool
    {
        $nameTokens = array_filter(
            explode(' ', $normalizedName),
            fn (string $token) => mb_strlen($token) >= 4,
        );

        $needles = [$needle, ...explode(' ', $needle)];

        foreach ($needles as $candidate) {
            if ($candidate === '' || mb_strlen($candidate) < 3) {
                continue;
            }

            foreach ($nameTokens as $token) {
                if (abs(mb_strlen($candidate) - mb_strlen($token)) <= 1
                    && levenshtein($candidate, $token) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isValidPinFormat(?string $pin): bool
    {
        return $pin !== null && preg_match('/^\d{4}$/', $pin) === 1;
    }

    /**
     * Check a PIN against a guest's stored hash. A guest without a hash,
     * or a malformed PIN, never verifies.
     */
    public function verifyPin(Guest $guest, ?string $pin): bool
    {
        if (empty($guest->pin_hash) || ! $this->isValidPinFormat($pin)) {
            return false;
        }

        return Hash::check($pin, $guest->pin_hash);
    }

    /**
     * The entry transaction: verify the existing PIN, or create one when
     * the guest has none, and lock the name on first entry.
     *
     * Creating a PIN here also covers a claimed guest returning after the
     * host reset their PIN, and a legacy guest claimed before PINs existed:
     * both have no pin_hash, so they set one now and carry on.
     *
     * @return array{claimed: bool, revealed: bool} never any recipient data
     *
     * @throws GuestEntryException
     */
    public function enter(Request $request, Guest $guest): array
    {
        $pin = $this->stringInput($request->input('pin'));
        $confirmation = $this->stringInput($request->input('pin_confirmation'));

        $entered = DB::transaction(function () use ($guest, $pin, $confirmation) {
            $guest = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            if (! empty($guest->pin_hash)) {
                if ($pin === null || $pin === '') {
                    throw GuestEntryException::make('pin_incorrect');
                }
                if (! $this->isValidPinFormat($pin)) {
                    throw GuestEntryException::make('pin_must_be_4_digits');
                }
                if (! Hash::check($pin, $guest->pin_hash)) {
                    throw GuestEntryException::make('pin_incorrect');
                }
            } else {
                if ($pin === null || $pin === '' || $confirmation === null || $confirmation === '') {
                    throw GuestEntryException::make('pin_required_create');
                }
                if (! $this->isValidPinFormat($pin) || ! $this->isValidPinFormat($confirmation)) {
                    throw GuestEntryException::make('pin_must_be_4_digits');
                }
                if ($pin !== $confirmation) {
                    throw GuestEntryException::make('pin_mismatch');
                }

                $guest->pin_hash = Hash::make($pin);
            }

            if (! $guest->claimed) {
                $guest->claimed = true;
                $guest->claimed_at = now();
            }

            $guest->save();

            return $guest;
        });

        // The session carries identity only: guest_id + the verified flag.
        $request->session()->put('guest_id', (int) $entered->id);
        $request->session()->put('pin_verified', true);

        return [
            'claimed' => true,
            'revealed' => (bool) $entered->revealed,
        ];
    }

    /**
     * Set a PIN for the session guest who has none yet.
     *
     * @throws GuestEntryException
     */
    public function setPin(Request $request, Guest $guest): void
    {
        $pin = $this->stringInput($request->input('pin'));
        $confirmation = $this->stringInput($request->input('pin_confirmation'));

        DB::transaction(function () use ($guest, $pin, $confirmation, $request) {
            $guest = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            if (! empty($guest->pin_hash)) {
                throw GuestEntryException::make('pin_already_set', 409);
            }
            if ($pin === null || $pin === '' || $confirmation === null || $confirmation === '') {
                throw GuestEntryException::make('pin_required_create');
            }
            if (! $this->isValidPinFormat($pin) || ! $this->isValidPinFormat($confirmation)) {
                throw GuestEntryException::make('pin_must_be_4_digits');
            }
            if ($pin !== $confirmation) {
                throw GuestEntryException::make('pin_mismatch');
            }

            $guest->pin_hash = Hash::make($pin);
            $guest->save();

            $request->session()->put('pin_verified', true);
        });
    }

    /**
     * Set (or change) the session guest's secret name: the codename their
     * Secret Santa sees at reveal time instead of their real name.
     *
     * The guest must have claimed their name first. The name is trimmed,
     * must be 2 to 30 characters, and must be unique within the party
     * under a case-insensitive comparison (re-setting your own current
     * value, in any letter case, is always fine). The trimmed value is
     * stored as typed, display case preserved.
     *
     * @return array{secret_name: string}
     *
     * @throws GuestEntryException
     */
    public function setSecretName(Request $request): array
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            throw GuestEntryException::make('not_claimed');
        }

        $name = trim($this->stringInput($request->input('secret_name')) ?? '');
        $length = mb_strlen($name);
        if ($length < 2 || $length > 30) {
            throw GuestEntryException::make('secret_name_invalid');
        }

        return DB::transaction(function () use ($guest, $name) {
            $guest = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            $taken = $guest->party->guests()
                ->whereKeyNot($guest->id)
                ->get(['secret_name'])
                ->contains(fn (Guest $other) => is_string($other->secret_name)
                    && mb_strtolower(trim($other->secret_name)) === mb_strtolower($name));

            if ($taken) {
                throw GuestEntryException::make('secret_name_taken', 409);
            }

            $guest->secret_name = $name;
            $guest->save();

            return ['secret_name' => $name];
        });
    }

    /**
     * The single reveal. D1: there is no second PIN prompt; the endpoint
     * checks the session's pin_verified flag, set when the PIN was verified
     * or created at entry. Sets `revealed` in a locked transaction and
     * returns the recipient exactly once; a second attempt gets
     * already_claimed with no recipient data. The recipient is carried as
     * their secret name only (or null when they have not picked one);
     * their real name never appears in this response.
     *
     * @return array{recipient: array{id: int, secret_name: ?string}|null, budget: ?string}
     *
     * @throws GuestEntryException
     */
    public function reveal(Request $request): array
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            throw GuestEntryException::make('not_claimed');
        }

        if ($request->session()->get('pin_verified') !== true) {
            throw GuestEntryException::make('pin_incorrect');
        }

        return DB::transaction(function () use ($guest) {
            $guest = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            if ($guest->revealed) {
                throw GuestEntryException::make('already_claimed', 409);
            }

            $guest->revealed = true;
            $guest->save();

            $recipient = $guest->recipient;

            return [
                'recipient' => $recipient === null ? null : Serializers::revealRecipient($recipient),
                'budget' => $guest->party?->budget,
            ];
        });
    }

    /**
     * Reveal again with a PIN (owner-directed exception to the once-only
     * rule): a guest who has already revealed can see their recipient
     * again by proving their PIN, so a name that was not written down is
     * not lost. The first reveal stays once-only; this is read-only and
     * changes no state.
     *
     * The recipient is derived strictly from the session guest's own
     * recipient_id, so this can only ever return that guest's recipient.
     * Every failure response is built before the recipient is loaded into
     * the payload, so a wrong PIN never carries recipient data.
     *
     * PIN failures mirror the entry flow's conventions: an absent PIN or
     * a wrong PIN against a set hash is pin_incorrect, a malformed PIN is
     * pin_must_be_4_digits, and a guest with no PIN hash at all must
     * create one first (pin_required_create).
     *
     * @return array{recipient: array{id: int, secret_name: ?string}|null, budget: ?string}
     *
     * @throws GuestEntryException
     */
    public function revealAgain(Request $request): array
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            throw GuestEntryException::make('not_claimed');
        }

        if (! $guest->revealed) {
            throw GuestEntryException::make('not_revealed', 409);
        }

        if (empty($guest->pin_hash)) {
            throw GuestEntryException::make('pin_required_create');
        }

        $pin = $this->stringInput($request->input('pin'));

        if ($pin === null || $pin === '') {
            throw GuestEntryException::make('pin_incorrect');
        }
        if (! $this->isValidPinFormat($pin)) {
            throw GuestEntryException::make('pin_must_be_4_digits');
        }
        if (! Hash::check($pin, $guest->pin_hash)) {
            throw GuestEntryException::make('pin_incorrect');
        }

        $recipient = $guest->recipient;

        return [
            'recipient' => $recipient === null ? null : Serializers::revealRecipient($recipient),
            'budget' => $guest->party?->budget,
        ];
    }

    /**
     * Your recipient's wishlist (owner-directed feature): a guest who has
     * revealed can shop from their person's list without the board ever
     * naming who it belongs to. Returns the recipient's codename (the
     * reveal shape, null when unset), the party budget, and the
     * recipient's wishlist items only, newest first — the same item
     * shape and ordering as the public board.
     *
     * Second named exception to the once-only secrecy rule, alongside
     * revealAgain(). It deliberately needs NO fresh PIN: the guest
     * session is itself PIN-verified at entry, and the owner asked for
     * the list to be one tap away after the reveal. Read-only and
     * repeatable; changes no state.
     *
     * The recipient is derived strictly from the session guest's own
     * recipient_id, so this can only ever return that guest's recipient.
     * Both gates run before the recipient is loaded into the payload, so
     * no failure response carries the codename, the real name, or any
     * item data: an unclaimed session guest is not_claimed, and a guest
     * who has not revealed yet (or somehow has no recipient) is
     * not_revealed.
     *
     * @return array{recipient: array{id: int, secret_name: ?string}, budget: ?string, items: array<int, array<string, mixed>>}
     *
     * @throws GuestEntryException
     */
    public function recipientWishlist(Request $request): array
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            throw GuestEntryException::make('not_claimed');
        }

        $recipient = $guest->revealed ? $guest->recipient : null;

        if ($recipient === null) {
            throw GuestEntryException::make('not_revealed', 409);
        }

        $items = $recipient->wishlistItems()
            ->latest('id')
            ->get()
            ->map(fn (WishlistItem $item) => Serializers::wishlistItem($item))
            ->values()
            ->all();

        return [
            'recipient' => Serializers::revealRecipient($recipient),
            'budget' => $guest->party?->budget,
            'items' => $items,
        ];
    }

    /**
     * View your own secret name, behind your PIN: the codename is never
     * sent to the guest home page, so a guest who forgot what they picked
     * proves their PIN here and gets the value back. Read-only; changes
     * no state.
     *
     * Gates run before any PIN check: a session guest who has not claimed
     * their name is not_claimed, and a claimed guest who has not picked a
     * secret name yet is secret_name_unset. PIN failures then mirror
     * revealAgain(): a guest with no PIN hash must create one first
     * (pin_required_create), a missing or malformed PIN is
     * pin_must_be_4_digits, and a wrong PIN is pin_incorrect. No failure
     * response carries the secret name value: it is only read off the
     * guest after every gate has passed.
     *
     * @return array{secret_name: string}
     *
     * @throws GuestEntryException
     */
    public function viewSecretName(Request $request): array
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            throw GuestEntryException::make('not_claimed');
        }

        if (! is_string($guest->secret_name) || $guest->secret_name === '') {
            throw GuestEntryException::make('secret_name_unset', 409);
        }

        if (empty($guest->pin_hash)) {
            throw GuestEntryException::make('pin_required_create');
        }

        $pin = $this->stringInput($request->input('pin'));

        if (! $this->isValidPinFormat($pin)) {
            throw GuestEntryException::make('pin_must_be_4_digits');
        }
        if (! Hash::check($pin, $guest->pin_hash)) {
            throw GuestEntryException::make('pin_incorrect');
        }

        return ['secret_name' => $guest->secret_name];
    }

    /**
     * The guest the session points at, scoped to the current party so a
     * stale session can never resolve into a later party's guest list.
     */
    public function sessionGuest(Request $request): ?Guest
    {
        $id = $request->session()->get('guest_id');
        if (! is_numeric($id)) {
            return null;
        }

        $partyId = Party::query()->orderBy('id')->value('id');
        if ($partyId === null) {
            return null;
        }

        return Guest::query()
            ->whereKey((int) $id)
            ->where('party_id', $partyId)
            ->first();
    }

    private function stringInput(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
