<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Guest entry, PIN handling, and the once-only reveal.
 *
 * Secrecy contract (Hard Rule 4): nothing in this service ever returns,
 * stores in the session, or logs a recipient, except reveal() — which is
 * the single response in the whole app that may carry a recipient, and
 * only once per guest. The session stores guest_id + a pin_verified flag
 * only (D2), never the plain PIN.
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
     * The single reveal. D1: there is no second PIN prompt; the endpoint
     * checks the session's pin_verified flag, set when the PIN was verified
     * or created at entry. Sets `revealed` in a locked transaction and
     * returns the recipient exactly once; a second attempt gets
     * already_claimed with no recipient data.
     *
     * @return array{recipient: array{id: int, display: string, nickname: ?string}|null, budget: ?string}
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
                'recipient' => $recipient === null ? null : [
                    'id' => (int) $recipient->id,
                    'display' => $recipient->display,
                    'nickname' => $recipient->nickname,
                ],
                'budget' => $guest->party?->budget,
            ];
        });
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
