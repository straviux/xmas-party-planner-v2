<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Party;
use App\Services\DrawService;
use App\Support\Serializers;
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
    ];

    /** Actions reachable while signed out. Everything else requires the host session. */
    private array $publicActions = ['host_login', 'invite_check', 'list'];

    public function __invoke(Request $request, string $action, DrawService $draw): JsonResponse
    {
        if (! isset($this->actions[$action])) {
            return $this->error('unknown_action', 404);
        }

        if (! in_array($action, $this->publicActions, true) && ! Auth::check()) {
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
                'counts' => ['guests' => 0, 'locked' => 0, 'awaiting' => 0, 'polls' => 0],
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
                'polls' => $party->polls()->count(),
            ],
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
