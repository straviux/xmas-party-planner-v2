<?php

namespace App\Http\Controllers;

use App\Models\Party;
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

    public function enter(): Response
    {
        return Inertia::render('Enter', [
            'party' => Serializers::partyPublic($this->currentParty()),
        ]);
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
