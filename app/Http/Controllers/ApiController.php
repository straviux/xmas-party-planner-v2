<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Party;
use App\Models\Poll;
use App\Models\WishlistItem;
use App\Services\AdminException;
use App\Services\AdminService;
use App\Services\DrawService;
use App\Services\GuestEntryException;
use App\Services\GuestEntryService;
use App\Services\KringleException;
use App\Services\KringleService;
use App\Services\PollException;
use App\Services\PollService;
use App\Services\WishlistException;
use App\Services\WishlistService;
use App\Support\Serializers;
use App\Support\TempSecretName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single dispatch endpoint: POST /api/{action}.
 *
 * Action names are a stable contract (spec); do not rename them. Every
 * payload that includes guest or party data is built through
 * App\Support\Serializers so recipient_id / pin_hash / reserved_by_id can
 * never leak.
 */
class ApiController extends Controller
{
    /** @var array<string, string> action => method */
    private array $actions = [
        'host_login' => 'hostLogin',
        'host_logout' => 'hostLogout',
        'host_status' => 'hostStatus',
        'party_update' => 'partyUpdate',
        'host_guest_add' => 'hostGuestAdd',
        'host_guest_update' => 'hostGuestUpdate',
        'host_guest_remove' => 'hostGuestRemove',
        'host_invite_regen' => 'hostInviteRegen',
        'invite_check' => 'inviteCheck',
        'list' => 'publicList',
        'enter' => 'guestEnter',
        'reveal' => 'guestReveal',
        'reveal_again' => 'guestRevealAgain',
        'guest_verify_pin' => 'guestVerifyPin',
        'guest_set_pin' => 'guestSetPin',
        'guest_set_secret_name' => 'guestSetSecretName',
        'guest_secret_name_view' => 'guestSecretNameView',
        'guest_recipient_wishlist' => 'guestRecipientWishlist',
        'kringle_current' => 'kringleCurrent',
        'kringle_spin' => 'kringleSpin',
        'host_kringle_regenerate' => 'hostKringleRegenerate',
        'guest_logout' => 'guestLogout',
        'wishlist_add' => 'wishlistAdd',
        'wishlist_update' => 'wishlistUpdate',
        'wishlist_delete' => 'wishlistDelete',
        'wishlist_list' => 'wishlistList',
        'host_poll_create' => 'hostPollCreate',
        'host_poll_update' => 'hostPollUpdate',
        'host_poll_delete' => 'hostPollDelete',
        'host_poll_toggle' => 'hostPollToggle',
        'poll_get' => 'pollGet',
        'poll_vote' => 'pollVote',
        'reset_one' => 'resetOne',
        'host_unclaim_all' => 'hostUnclaimAll',
        'host_redraw' => 'hostRedraw',
        'host_reshuffle' => 'hostReshuffle',
        'host_reset_pin' => 'hostResetPin',
        'host_clear_wishlists' => 'hostClearWishlists',
        'reset_all' => 'resetAll',
        'host_change_password' => 'hostChangePassword',
    ];

    /** Actions reachable while signed out: only what the entry flow
     * itself needs before anyone can sign in. Everything else requires
     * the host session or (for guest actions) the PIN-verified guest
     * session, which is the guest sign-in (owner's call, 2026-11-10:
     * the wishlist board and the polls used to be public reads; they
     * now sit behind the same sign-in as everything else). */
    private array $publicActions = ['host_login', 'invite_check', 'list'];

    /**
     * Guest actions are gated by the guest session (guest_id + pin_verified),
     * never by host auth: guests have no accounts (Hard Rule 1).
     */
    private array $guestActions = [
        'enter', 'reveal', 'reveal_again', 'guest_verify_pin', 'guest_set_pin',
        'guest_set_secret_name', 'guest_secret_name_view', 'guest_recipient_wishlist', 'guest_logout',
        'kringle_current', 'kringle_spin',
        'wishlist_add', 'wishlist_update', 'wishlist_delete', 'poll_vote',
        'wishlist_list', 'poll_get',
    ];

    public function __invoke(Request $request, string $action, DrawService $draw): JsonResponse
    {
        if (! isset($this->actions[$action])) {
            return $this->error('unknown_action', 404);
        }

        if (! in_array($action, $this->publicActions, true)
            && ! in_array($action, $this->guestActions, true)
            && ! Auth::check()) {
            return $this->error('forbidden', 403);
        }

        return $this->{$this->actions[$action]}($request, $draw);
    }

    // ------------------------------------------------------------------
    // Host auth
    // ------------------------------------------------------------------

    private function hostLogin(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === ''
            || ! Auth::attempt(['username' => $username, 'password' => $password])) {
            return $this->error('invalid_credentials', 401);
        }

        $request->session()->regenerate();

        return $this->ok(['username' => Auth::user()->username]);
    }

    private function hostLogout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->regenerateToken();

        return $this->ok();
    }

    // ------------------------------------------------------------------
    // Host reads
    // ------------------------------------------------------------------

    private function hostStatus(Request $request): JsonResponse
    {
        $party = $this->currentParty();

        if ($party === null) {
            // No party yet is a normal empty state, never a 404 (spec).
            return $this->ok([
                'exists' => false,
                'party' => null,
                'guests' => [],
                'counts' => ['guests' => 0, 'locked' => 0, 'awaiting' => 0, 'secret_names_set' => 0, 'polls' => 0],
            ]);
        }

        $guests = $party->guests()->orderBy('id')->get();
        $locked = $guests->where('claimed', true)->count();

        return $this->ok([
            'exists' => true,
            'party' => Serializers::partyHost($party),
            'guests' => $guests->map(fn (Guest $guest) => Serializers::hostGuest($guest))->all(),
            'counts' => [
                'guests' => $guests->count(),
                'locked' => $locked,
                'awaiting' => $guests->count() - $locked,
                'secret_names_set' => $guests
                    ->filter(fn (Guest $guest) => is_string($guest->secret_name) && $guest->secret_name !== '' && ! $guest->secret_name_temp)
                    ->count(),
                'polls' => $party->polls()->count(),
            ],
            // Monito Monita card data: current theme + this week's
            // spun count. Counts only, never any pairing.
            'kringle' => app(KringleService::class)->hostSummary($party),
        ]);
    }

    // ------------------------------------------------------------------
    // Party setup
    // ------------------------------------------------------------------

    private function partyUpdate(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->error('validation_error', 422, [
                'message' => 'A party name is required.',
            ]);
        }

        $date = $request->input('date');
        $date = is_string($date) && $date !== '' ? $date : null;
        if ($date !== null && strtotime($date) === false) {
            return $this->error('validation_error', 422, [
                'message' => 'The party date is not a valid date.',
            ]);
        }

        $budget = $request->input('budget');
        $budget = is_string($budget) && $budget !== '' ? $budget : null;

        $party = $this->currentParty();

        if ($party === null) {
            // Upsert (A3): party_update creates the party when none exists.
            // Only details are set here; guests and the draw are untouched
            // when editing an existing party.
            $party = Party::create([
                'name' => $name,
                'date' => $date,
                'budget' => $budget,
                'invite_code' => $this->generateInviteCode(),
            ]);
        } else {
            $party->update([
                'name' => $name,
                'date' => $date,
                'budget' => $budget,
            ]);
        }

        return $this->ok(['party' => Serializers::partyHost($party)]);
    }

    private function hostInviteRegen(Request $request): JsonResponse
    {
        $party = $this->currentParty();
        if ($party === null) {
            return $this->error('not_set_up', 422);
        }

        // Only the code changes: guests, claims, and the draw are untouched.
        $party->update(['invite_code' => $this->generateInviteCode($party->id)]);

        return $this->ok([
            'invite_code' => $party->invite_code,
            'party' => Serializers::partyHost($party),
        ]);
    }

    // ------------------------------------------------------------------
    // Host guest management (locked once any name is claimed)
    // ------------------------------------------------------------------

    private function hostGuestAdd(Request $request, DrawService $draw): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        if ($locked = $this->lockedResponse($draw, $party)) {
            return $locked;
        }

        $display = trim((string) $request->input('display', ''));
        if ($display === '') {
            return $this->error('validation_error', 422, [
                'message' => 'A guest name is required.',
            ]);
        }

        $guest = DB::transaction(function () use ($request, $party, $display, $draw) {
            $guest = $party->guests()->create([
                'display' => $display,
                'nickname' => $this->nullableString($request->input('nickname')),
                'aliases' => $this->normalizeAliases($request->input('aliases')),
                'pin_hash' => $this->hashPin($request->input('pin')),
            ]);

            // Everyone starts with a temporary secret name so no guest
            // is ever left showing as "still choosing"; they replace
            // it with their own after claiming (TempSecretName).
            TempSecretName::assign($guest);

            $draw->redraw($party);

            return $guest;
        });

        return $this->ok(['guest' => Serializers::hostGuest($guest->fresh())]);
    }

    private function hostGuestUpdate(Request $request, DrawService $draw): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        if ($locked = $this->lockedResponse($draw, $party)) {
            return $locked;
        }

        $guest = $this->findGuest($party, $request->input('id'));
        if ($guest === null) {
            return $this->error('guest_not_found', 404);
        }

        if ($request->has('display') && trim((string) $request->input('display')) === '') {
            return $this->error('validation_error', 422, [
                'message' => 'A guest name is required.',
            ]);
        }

        DB::transaction(function () use ($request, $party, $guest, $draw) {
            $updates = [];

            if ($request->has('display')) {
                $updates['display'] = trim((string) $request->input('display'));
            }
            if ($request->has('nickname')) {
                $updates['nickname'] = $this->nullableString($request->input('nickname'));
            }
            if ($request->has('aliases')) {
                $updates['aliases'] = $this->normalizeAliases($request->input('aliases'));
            }
            // A PIN is only ever set when a non-empty one is provided.
            // Editing a guest must never force a PIN (spec).
            $pinHash = $this->hashPin($request->input('pin'));
            if ($pinHash !== null) {
                $updates['pin_hash'] = $pinHash;
            }

            if ($updates !== []) {
                $guest->update($updates);
            }

            $draw->redraw($party);
        });

        return $this->ok(['guest' => Serializers::hostGuest($guest->fresh())]);
    }

    private function hostGuestRemove(Request $request, DrawService $draw): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        if ($locked = $this->lockedResponse($draw, $party)) {
            return $locked;
        }

        $guest = $this->findGuest($party, $request->input('id'));
        if ($guest === null) {
            return $this->error('guest_not_found', 404);
        }

        DB::transaction(function () use ($party, $guest, $draw) {
            // The guest's wishlist items and votes cascade away with the row;
            // anyone who drew this guest has recipient_id set to null by the
            // FK, and the redraw below reassigns everyone.
            $guest->delete();

            $draw->redraw($party);
        });

        return $this->ok();
    }

    // ------------------------------------------------------------------
    // Public reads
    // ------------------------------------------------------------------

    private function inviteCheck(Request $request): JsonResponse
    {
        $party = $this->currentParty();
        $code = (string) $request->input('code', '');

        if ($party !== null && $code !== '' && strcasecmp($party->invite_code, $code) === 0) {
            return $this->ok();
        }

        return $this->error('invite_incorrect', 422);
    }

    private function publicList(Request $request): JsonResponse
    {
        $party = $this->currentParty();

        if ($party === null) {
            return $this->ok(['party' => null, 'guests' => []]);
        }

        return $this->ok([
            'party' => Serializers::partyPublic($party),
            'guests' => $party->guests()->orderBy('id')->get()
                ->map(fn (Guest $guest) => Serializers::publicGuest($guest))->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Guest entry, PIN, and reveal (guest session, never host auth)
    // ------------------------------------------------------------------

    private function guestEnter(Request $request): JsonResponse
    {
        $entry = app(GuestEntryService::class);

        $party = $this->currentParty();
        if ($party === null) {
            return $this->error('not_set_up', 422, [
                'message' => 'No party has been set up yet.',
            ]);
        }

        $guest = $this->findGuest($party, $request->input('guest_id'));
        if ($guest === null) {
            return $this->error('guest_not_found', 404);
        }

        try {
            $result = $entry->enter($request, $guest);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    private function guestReveal(Request $request): JsonResponse
    {
        try {
            $result = app(GuestEntryService::class)->reveal($request);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    private function guestRevealAgain(Request $request): JsonResponse
    {
        try {
            $result = app(GuestEntryService::class)->revealAgain($request);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    private function guestVerifyPin(Request $request): JsonResponse
    {
        $entry = app(GuestEntryService::class);

        $guest = $entry->sessionGuest($request);
        if ($guest === null) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        $pin = $request->input('pin');
        $pin = is_string($pin) ? $pin : (is_int($pin) ? (string) $pin : null);

        if (! $entry->isValidPinFormat($pin)) {
            return $this->error('pin_must_be_4_digits', 422, [
                'message' => 'Your PIN must be exactly 4 digits.',
            ]);
        }

        if (! $entry->verifyPin($guest, $pin)) {
            return $this->error('pin_incorrect', 422, [
                'message' => 'That PIN does not match. Try again.',
            ]);
        }

        return $this->ok();
    }

    private function guestSetPin(Request $request): JsonResponse
    {
        $entry = app(GuestEntryService::class);

        $guest = $entry->sessionGuest($request);
        if ($guest === null) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        try {
            $entry->setPin($request, $guest);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok();
    }

    private function guestSetSecretName(Request $request): JsonResponse
    {
        try {
            $result = app(GuestEntryService::class)->setSecretName($request);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    private function guestSecretNameView(Request $request): JsonResponse
    {
        try {
            $result = app(GuestEntryService::class)->viewSecretName($request);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    private function guestRecipientWishlist(Request $request): JsonResponse
    {
        try {
            $result = app(GuestEntryService::class)->recipientWishlist($request);
        } catch (GuestEntryException $e) {
            return $this->guestError($e);
        }

        return $this->ok($result);
    }

    // ------------------------------------------------------------------
    // Monito Monita (guest session; the caller's own weekly state only)
    // ------------------------------------------------------------------

    private function kringleCurrent(Request $request): JsonResponse
    {
        $guest = app(GuestEntryService::class)->sessionGuest($request);
        if ($guest === null || ! $guest->claimed) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        return $this->ok([
            'kringle' => app(KringleService::class)->guestState($guest->party, $guest),
        ]);
    }

    private function kringleSpin(Request $request): JsonResponse
    {
        $guest = app(GuestEntryService::class)->sessionGuest($request);
        if ($guest === null || ! $guest->claimed) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        // The spin is a draw, so it carries the reveal's gate: the
        // session must be PIN-verified (set at entry), no fresh prompt.
        if ($request->session()->get('pin_verified') !== true) {
            return $this->error('pin_incorrect', 422, [
                'message' => 'That PIN does not match. Try again.',
            ]);
        }

        $kringle = app(KringleService::class);

        try {
            $kringle->spin($guest);
        } catch (KringleException $e) {
            return $this->error($e->errorCode, $e->status, [
                'message' => $e->getMessage(),
            ]);
        }

        // The full updated week state rides along, so the page can
        // settle the roulette on the result and swap state wholesale.
        return $this->ok([
            'kringle' => $kringle->guestState($guest->party, $guest),
        ]);
    }

    private function hostKringleRegenerate(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        $kringle = app(KringleService::class);
        $kringle->regenerate($party);

        return $this->ok([
            'kringle' => $kringle->hostSummary($party),
        ]);
    }

    private function guestLogout(Request $request): JsonResponse
    {
        // Clears the guest identity only: the name stays locked and the
        // revealed flag stays set (spec). The invite code is kept so the
        // guest can get back in from this device.
        $request->session()->forget(['guest_id', 'pin_verified']);

        return $this->ok();
    }

    private function guestError(GuestEntryException $e): JsonResponse
    {
        return $this->error($e->errorCode, $e->status, [
            'message' => $e->getMessage(),
        ]);
    }

    // ------------------------------------------------------------------
    // Wishlists (guest session; items always belong to the session guest)
    // ------------------------------------------------------------------

    private function wishlistAdd(Request $request): JsonResponse
    {
        $guest = $this->sessionGuestOrError($request);
        if ($guest instanceof JsonResponse) {
            return $guest;
        }

        try {
            // Only these fields are read. A guest_id in the payload is
            // ignored on purpose: items always land on the session
            // guest's own list (A7).
            $item = app(WishlistService::class)->addItem($guest, [
                'text' => $request->input('text'),
                'link' => $request->input('link'),
                'notes' => $request->input('notes'),
                'image' => $request->input('image'),
            ]);
        } catch (WishlistException $e) {
            return $this->wishlistError($e);
        }

        return $this->ok(['item' => Serializers::wishlistItem($item)]);
    }

    private function wishlistUpdate(Request $request): JsonResponse
    {
        $guest = $this->sessionGuestOrError($request);
        if ($guest instanceof JsonResponse) {
            return $guest;
        }

        $item = $this->findPartyItem($request->input('item_id'));
        if ($item === null) {
            return $this->error('wish_not_found', 404);
        }

        try {
            // Only keys actually present in the payload reach the service
            // ($request->only keeps explicit nulls, e.g. image: null to
            // remove the photo); absent keys keep their current values.
            // A guest_id in the payload is ignored, same as wishlist_add.
            $item = app(WishlistService::class)->updateItem(
                $guest,
                $item,
                $request->only(['text', 'link', 'notes', 'image']),
            );
        } catch (WishlistException $e) {
            return $this->wishlistError($e);
        }

        return $this->ok(['item' => Serializers::wishlistItem($item)]);
    }

    private function wishlistDelete(Request $request): JsonResponse
    {
        $guest = $this->sessionGuestOrError($request);
        if ($guest instanceof JsonResponse) {
            return $guest;
        }

        $item = $this->findPartyItem($request->input('id'));
        if ($item === null) {
            return $this->error('wish_not_found', 404);
        }

        try {
            app(WishlistService::class)->deleteItem($guest, $item);
        } catch (WishlistException $e) {
            return $this->wishlistError($e);
        }

        return $this->ok();
    }

    /**
     * The everyone's-wishlists board: a public read like `list`. Wishlists
     * are posting only, so items carry no per-viewer state. Wishers are
     * identified by codename only (Serializers::wishlistMember): real
     * display names and nicknames never ship on the board.
     */
    private function wishlistList(Request $request): JsonResponse
    {
        // The board carries every wisher's codename, so it is NOT a
        // host read (the host stays blind to codenames) and not a
        // public one either: a signed-in guest only.
        if ($this->signedInGuest($request) === null) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        $party = $this->currentParty();

        if ($party === null) {
            return $this->ok(['party' => null, 'guests' => []]);
        }

        $guests = $party->guests()
            ->with(['wishlistItems' => fn ($query) => $query->latest('id')])
            ->orderBy('id')
            ->get();

        return $this->ok([
            'party' => Serializers::partyPublic($party),
            'guests' => $guests->map(fn (Guest $guest) => [
                ...Serializers::wishlistMember($guest),
                'wish_count' => $guest->wishlistItems->count(),
                'items' => $guest->wishlistItems
                    ->map(fn (WishlistItem $item) => Serializers::wishlistItem($item))
                    ->values()
                    ->all(),
            ])->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Polls (host manages, guests vote; results are counts only)
    // ------------------------------------------------------------------

    private function hostPollCreate(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        try {
            $poll = app(PollService::class)->create(
                $party,
                $request->input('question'),
                $this->inputArray($request->input('options')),
            );
        } catch (PollException $e) {
            return $this->pollError($e);
        }

        return $this->ok(['poll' => app(PollService::class)->shape($poll, null)]);
    }

    private function hostPollUpdate(Request $request): JsonResponse
    {
        $poll = $this->findPartyPoll($request->input('id'));
        if ($poll === null) {
            return $this->error('poll_not_found', 404);
        }

        try {
            $poll = app(PollService::class)->update(
                $poll,
                $request->input('question'),
                $this->inputArray($request->input('options')),
            );
        } catch (PollException $e) {
            return $this->pollError($e);
        }

        return $this->ok(['poll' => app(PollService::class)->shape($poll, null)]);
    }

    private function hostPollDelete(Request $request): JsonResponse
    {
        $poll = $this->findPartyPoll($request->input('id'));
        if ($poll === null) {
            return $this->error('poll_not_found', 404);
        }

        app(PollService::class)->delete($poll);

        return $this->ok();
    }

    private function hostPollToggle(Request $request): JsonResponse
    {
        $poll = $this->findPartyPoll($request->input('id'));
        if ($poll === null) {
            return $this->error('poll_not_found', 404);
        }

        $poll = app(PollService::class)->toggle($poll);

        return $this->ok(['poll' => app(PollService::class)->shape($poll, null)]);
    }

    /**
     * The guest poll view: a public read like `poll_get`'s siblings. A
     * viewing guest session personalizes my_vote only; nothing in the
     * payload identifies any voter.
     */
    private function pollGet(Request $request): JsonResponse
    {
        // Polls are read by signed-in guests (Vote page) and by the
        // host (Polls page); counts carry no codenames, so the host
        // allowance costs nothing.
        if (! Auth::check() && $this->signedInGuest($request) === null) {
            return $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
        }

        $party = $this->currentParty();

        if ($party === null) {
            return $this->ok(['polls' => []]);
        }

        $viewer = app(GuestEntryService::class)->sessionGuest($request);
        $service = app(PollService::class);

        $polls = $party->polls()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $this->ok([
            'polls' => $polls->map(fn (Poll $poll) => $service->shape($poll, $viewer))->all(),
        ]);
    }

    private function pollVote(Request $request): JsonResponse
    {
        $guest = $this->sessionGuestOrError($request);
        if ($guest instanceof JsonResponse) {
            return $guest;
        }

        $poll = $this->findPartyPoll($request->input('poll_id'));
        if ($poll === null) {
            return $this->error('poll_not_found', 404);
        }

        try {
            $shape = app(PollService::class)->vote($guest, $poll, $request->input('option_id'));
        } catch (PollException $e) {
            return $this->pollError($e);
        }

        return $this->ok(['poll' => $shape]);
    }

    // ------------------------------------------------------------------
    // Host admin controls (each scoped to exactly what its name says)
    // ------------------------------------------------------------------

    private function resetOne(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        $guest = $this->findGuest($party, $this->guestIdInput($request));
        if ($guest === null) {
            return $this->error('guest_not_found', 404);
        }

        // Claimed + revealed only: the PIN, the recipient mapping, the
        // wishlist, and the guest's votes are untouched.
        $guest = app(AdminService::class)->resetOne($guest);

        return $this->ok(['guest' => Serializers::hostGuest($guest)]);
    }

    private function hostUnclaimAll(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        // Claims only: no redraw, no PIN changes, nothing deleted.
        $unclaimed = app(AdminService::class)->unclaimAll($party);

        return $this->ok(['unclaimed' => $unclaimed]);
    }

    private function hostRedraw(Request $request, DrawService $draw): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        try {
            app(AdminService::class)->redraw($party, $draw);
        } catch (\DomainException $e) {
            // DrawService refuses while any name is claimed; map it exactly
            // like the guest-management lock (409 guests_locked, plain
            // locked message).
            if ($e->getMessage() === 'guests_locked') {
                return $this->error('guests_locked', 409, [
                    'message' => DrawService::LOCKED_MESSAGE,
                ]);
            }

            throw $e;
        }

        // No recipient data in the response: the host stays blind.
        return $this->ok();
    }

    private function hostReshuffle(Request $request, DrawService $draw): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        // Guests whose current recipient must be kept (for example,
        // one who already bought the gift). Ids that do not belong to
        // this party are ignored by the service's party-scoped query.
        $except = $request->input('except_guest_ids', []);
        $exceptIds = is_array($except)
            ? array_values(array_filter(array_map('intval', $except)))
            : [];

        $result = $draw->reshuffle($party, $exceptIds);

        // Counts only: the host stays blind to who got whom.
        return $this->ok($result);
    }

    private function hostResetPin(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        $guest = $this->findGuest($party, $this->guestIdInput($request));
        if ($guest === null) {
            return $this->error('guest_not_found', 404);
        }

        // The PIN hash only: claimed status, recipient, wishlist, and
        // votes are untouched, even when the guest is claimed.
        $guest = app(AdminService::class)->resetPin($guest);

        return $this->ok(['guest' => Serializers::hostGuest($guest)]);
    }

    private function hostClearWishlists(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        $deleted = app(AdminService::class)->clearWishlists($party);

        return $this->ok(['deleted' => $deleted]);
    }

    private function resetAll(Request $request): JsonResponse
    {
        $party = $this->requireParty();
        if ($party instanceof JsonResponse) {
            return $party;
        }

        // Deletes the party row; the cascades clear guests, wishlist
        // items, polls, options, and votes with it.
        app(AdminService::class)->resetAll($party);

        return $this->ok(['exists' => false]);
    }

    private function hostChangePassword(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $string = fn (mixed $value): ?string => is_string($value)
            ? $value
            : (is_int($value) ? (string) $value : null);

        try {
            app(AdminService::class)->changePassword(
                $user,
                $string($request->input('current_password')),
                $string($request->input('new_password')),
                $string($request->input('new_password_confirmation')),
            );
        } catch (AdminException $e) {
            return $this->error($e->errorCode, $e->status, [
                'message' => $e->getMessage(),
            ]);
        }

        // The host stays signed in with the new password.
        return $this->ok();
    }

    /** Admin actions name the guest as guest_id (id is accepted too). */
    private function guestIdInput(Request $request): mixed
    {
        return $request->input('guest_id', $request->input('id'));
    }

    private function pollError(PollException $e): JsonResponse
    {
        return $this->error($e->errorCode, $e->status, [
            'message' => $e->getMessage(),
        ]);
    }

    /** A poll counts only when it belongs to the current party. */
    private function findPartyPoll(mixed $id): ?Poll
    {
        $party = $this->currentParty();
        if ($party === null || ! is_numeric($id)) {
            return null;
        }

        return $party->polls()->whereKey((int) $id)->first();
    }

    /** @return array<int, mixed> */
    private function inputArray(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function wishlistError(WishlistException $e): JsonResponse
    {
        return $this->error($e->errorCode, $e->status, [
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * The guest sign-in: a session guest whose PIN has been verified.
     * The entry flow sets guest_id and pin_verified together, so this
     * is the state every entered guest is in (owner's framing,
     * 2026-11-10: the PIN is the sign-in session).
     */
    private function signedInGuest(Request $request): ?Guest
    {
        if ($request->session()->get('pin_verified') !== true) {
            return null;
        }

        return app(GuestEntryService::class)->sessionGuest($request);
    }

    private function sessionGuestOrError(Request $request): Guest|JsonResponse
    {
        return app(GuestEntryService::class)->sessionGuest($request)
            ?? $this->error('not_claimed', 422, [
                'message' => 'Find and confirm your name first.',
            ]);
    }

    /** An item counts only when its owner belongs to the current party. */
    private function findPartyItem(mixed $id): ?WishlistItem
    {
        $party = $this->currentParty();
        if ($party === null || ! is_numeric($id)) {
            return null;
        }

        return WishlistItem::query()
            ->whereKey((int) $id)
            ->whereHas('guest', fn ($query) => $query->where('party_id', $party->id))
            ->first();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** The app hosts a single active party (A4). */
    private function currentParty(): ?Party
    {
        return Party::query()->orderBy('id')->first();
    }

    private function requireParty(): Party|JsonResponse
    {
        return $this->currentParty()
            ?? $this->error('not_set_up', 422, [
                'message' => 'No party has been set up yet.',
            ]);
    }

    private function lockedResponse(DrawService $draw, Party $party): ?JsonResponse
    {
        if (! $draw->anyClaimed($party)) {
            return null;
        }

        return $this->error('guests_locked', 409, [
            'message' => DrawService::LOCKED_MESSAGE,
        ]);
    }

    private function findGuest(Party $party, mixed $id): ?Guest
    {
        if (! is_numeric($id)) {
            return null;
        }

        return $party->guests()->whereKey((int) $id)->first();
    }

    private function generateInviteCode(?int $exceptPartyId = null): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Party::query()
            ->where('invite_code', $code)
            ->when($exceptPartyId !== null, fn ($query) => $query->whereKeyNot($exceptPartyId))
            ->exists());

        return $code;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Aliases arrive as an array of strings (a JSON string is also accepted
     * and decoded). Anything else becomes no aliases.
     *
     * @return array<int, string>|null
     */
    private function normalizeAliases(mixed $aliases): ?array
    {
        if (is_string($aliases) && $aliases !== '') {
            $decoded = json_decode($aliases, true);
            $aliases = is_array($decoded) ? $decoded : [$aliases];
        }

        if (! is_array($aliases)) {
            return null;
        }

        $aliases = array_values(array_filter(
            array_map(fn ($alias) => is_string($alias) ? trim($alias) : '', $aliases),
            fn (string $alias) => $alias !== '',
        ));

        return $aliases === [] ? null : $aliases;
    }

    private function hashPin(mixed $pin): ?string
    {
        if (! is_string($pin) || $pin === '') {
            return null;
        }

        return Hash::make($pin);
    }

    private function ok(array $payload = []): JsonResponse
    {
        return response()->json(['ok' => true, ...$payload]);
    }

    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, ...$extra], $status);
    }
}
