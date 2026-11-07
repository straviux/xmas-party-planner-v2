<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Party;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Host admin controls (spec, Host admin section).
 *
 * Every control is scoped to exactly what its name says and nothing more.
 * The differences between them are the product: Reset one does not touch
 * the PIN, Unclaim all does not redraw, Reset PIN does not unclaim, and
 * Clear wishlists does not touch claims, polls, or votes. Recipient
 * mappings are never read for a response here and never changed except by
 * an explicit Redraw.
 */
class AdminService
{
    /**
     * Reset one: clears claimed + revealed for a single guest so they can
     * enter and reveal again. The PIN, the recipient mapping, the
     * wishlist, and the guest's votes are all untouched.
     */
    public function resetOne(Guest $guest): Guest
    {
        $guest->update([
            'claimed' => false,
            'claimed_at' => null,
            'revealed' => false,
        ]);

        return $guest->fresh();
    }

    /**
     * Unclaim all: clears claimed + revealed for every guest of the party.
     * Does NOT redraw recipients (this is the prerequisite for Redraw,
     * not a redraw itself), does NOT clear PINs, and does NOT delete
     * guests, wishlist items, polls, or votes.
     *
     * Returns how many guests were claimed before the reset.
     */
    public function unclaimAll(Party $party): int
    {
        return DB::transaction(function () use ($party) {
            $claimed = $party->guests()->where('claimed', true)->count();

            $party->guests()->update([
                'claimed' => false,
                'claimed_at' => null,
                'revealed' => false,
            ]);

            return $claimed;
        });
    }

    /**
     * Redraw: a fresh derangement for the party, via DrawService. Blocked
     * while any name is claimed: DrawService::redraw throws a
     * DomainException with message 'guests_locked', which the controller
     * maps to the same 409 response as the guest-management actions.
     */
    public function redraw(Party $party, DrawService $draw): void
    {
        $draw->redraw($party);
    }

    /**
     * Reset PIN: clears ONLY the guest's PIN hash, so they create a new
     * PIN on return. Claimed status, the recipient, the wishlist, and
     * votes are untouched, and it is allowed even when the guest is
     * claimed: this is the recovery path for a forgotten PIN.
     */
    public function resetPin(Guest $guest): Guest
    {
        $guest->update(['pin_hash' => null]);

        return $guest->fresh();
    }

    /**
     * Clear wishlists: deletes the party's wishlist items only. Guests,
     * claims, PINs, polls, and votes are untouched.
     *
     * Returns how many items were deleted.
     */
    public function clearWishlists(Party $party): int
    {
        return DB::transaction(function () use ($party) {
            $items = WishlistItem::query()
                ->whereHas('guest', fn ($query) => $query->where('party_id', $party->id));

            $count = $items->count();
            $items->delete();

            return $count;
        });
    }

    /**
     * Reset everything: deletes the party row itself. The foreign keys
     * cascade, so no guests, wishlist items, polls, options, or votes are
     * left behind.
     */
    public function resetAll(Party $party): void
    {
        $party->delete();
    }

    /**
     * Change the signed-in host's password. The host stays signed in.
     *
     * Checks run in a fixed order and the first failure wins:
     *  1. password_incorrect — the current password does not match.
     *  2. password_too_short — the new password is under 8 characters.
     *  3. password_mismatch — the confirmation differs from the new one.
     *  4. password_same — the new password is the current one.
     *
     * @throws AdminException
     */
    public function changePassword(User $user, ?string $current, ?string $new, ?string $confirmation): void
    {
        $current ??= '';
        $new ??= '';
        $confirmation ??= '';

        if ($current === '' || ! Hash::check($current, $user->password)) {
            throw AdminException::make('password_incorrect');
        }

        if (strlen($new) < 8) {
            throw AdminException::make('password_too_short');
        }

        if ($new !== $confirmation) {
            throw AdminException::make('password_mismatch');
        }

        if (Hash::check($new, $user->password)) {
            throw AdminException::make('password_same');
        }

        $user->update(['password' => Hash::make($new)]);
    }
}
