<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Party;
use App\Services\GuestEntryService;
use App\Support\Serializers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia page routes.
 *
 * Host pages receive only `authed` and `username`: when signed out they get
 * NO party data at all (spec), and when signed in the page fetches its data
 * from the host_status API action. Guest pages receive public party info
 * only (name, date, budget), never the invite code or any guest data.
 */
class PageController extends Controller
{
    public function landing(): Response
    {
        return Inertia::render('Landing', [
            'party' => Serializers::partyPublic($this->currentParty()),
        ]);
    }

    public function enter(Request $request): Response|RedirectResponse
    {
        $guest = $this->sessionGuest($request);

        // A guest who already entered never lands on entry again: the
        // tear comes first, guest home only once they have revealed.
        if ($guest !== null && $guest->revealed) {
            return redirect()->route('guest.home');
        }
        if ($guest !== null && $guest->claimed) {
            return redirect()->route('reveal');
        }

        return Inertia::render('Enter', [
            'party' => Serializers::partyPublic($this->currentParty()),
        ]);
    }

    public function reveal(Request $request): Response|RedirectResponse
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null || ! $guest->claimed) {
            return redirect()->route('enter');
        }
        if ($guest->revealed) {
            return redirect()->route('guest.home');
        }

        return Inertia::render('Reveal', [
            'party' => Serializers::partyPublic($guest->party),
            'guest' => Serializers::publicGuest($guest),
        ]);
    }

    public function guestHome(Request $request): Response|RedirectResponse
    {
        $guest = $this->guardedGuest($request);
        if ($guest instanceof RedirectResponse) {
            return $guest;
        }

        $party = $guest->party;

        return Inertia::render('Guest/Home', [
            'party' => Serializers::partyPublic($party),
            'guest' => Serializers::publicGuest($guest),
            'counts' => [
                'locked' => $party->guests()->where('claimed', true)->count(),
                'total' => $party->guests()->count(),
                'wishes' => $guest->wishlistItems()->count(),
            ],
        ]);
    }

    public function guestGifts(Request $request): Response|RedirectResponse
    {
        return $this->guestPage($request, 'Guest/Gifts');
    }

    public function guestWishlists(Request $request): Response|RedirectResponse
    {
        return $this->guestPage($request, 'Guest/Wishlists');
    }

    /**
     * One member's wishlist, linked from the everyone's-wishlists board.
     * An unknown member just goes back to the board: nothing about other
     * parties or missing ids is worth surfacing here.
     */
    public function guestMemberWishlist(Request $request, mixed $member): Response|RedirectResponse
    {
        $guest = $this->guardedGuest($request);
        if ($guest instanceof RedirectResponse) {
            return $guest;
        }

        $memberGuest = is_numeric($member)
            ? $guest->party->guests()->whereKey((int) $member)->first()
            : null;

        if ($memberGuest === null) {
            return redirect()->route('guest.wishlists');
        }

        return Inertia::render('Guest/MemberWishlist', [
            'party' => Serializers::partyPublic($guest->party),
            'guest' => Serializers::publicGuest($guest),
            'member' => Serializers::publicGuest($memberGuest),
        ]);
    }

    public function guestVote(Request $request): Response|RedirectResponse
    {
        return $this->guestPage($request, 'Guest/Vote');
    }

    /**
     * Shared guard for the post-reveal guest pages: no guest session goes
     * to entry, a guest who has entered but not yet torn the strip goes
     * back to the reveal page.
     */
    private function guardedGuest(Request $request): Guest|RedirectResponse
    {
        $guest = $this->sessionGuest($request);

        if ($guest === null) {
            return redirect()->route('enter');
        }
        if ($guest->claimed && ! $guest->revealed) {
            return redirect()->route('reveal');
        }

        return $guest;
    }

    private function guestPage(Request $request, string $component): Response|RedirectResponse
    {
        $guest = $this->guardedGuest($request);
        if ($guest instanceof RedirectResponse) {
            return $guest;
        }

        return Inertia::render($component, [
            'party' => Serializers::partyPublic($guest->party),
            'guest' => Serializers::publicGuest($guest),
        ]);
    }

    private function sessionGuest(Request $request): ?Guest
    {
        return app(GuestEntryService::class)->sessionGuest($request);
    }

    /**
     * Invite link /join/{code} (A6): remember the code in the session when it
     * matches, then land on the entry page either way; a wrong code surfaces
     * there through invite_check.
     */
    public function join(Request $request, string $code): RedirectResponse
    {
        $party = $this->currentParty();

        if ($party !== null && strcasecmp($party->invite_code, $code) === 0) {
            $request->session()->put('invite_code', $party->invite_code);
        }

        return redirect()->route('enter');
    }

    public function hostDashboard(): Response
    {
        return $this->hostPage('Host/Dashboard');
    }

    public function hostGuests(): Response
    {
        return $this->hostPage('Host/Guests');
    }

    public function hostPolls(): Response
    {
        return $this->hostPage('Host/Polls');
    }

    public function hostSettings(): Response
    {
        return $this->hostPage('Host/Settings');
    }

    private function hostPage(string $component): Response
    {
        $user = Auth::user();

        return Inertia::render($component, [
            'authed' => $user !== null,
            'username' => $user?->username,
        ]);
    }

    private function currentParty(): ?Party
    {
        return Party::query()->orderBy('id')->first();
    }
}
