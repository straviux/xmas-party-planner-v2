# Xmas Party Planner

Christmas party app: Secret Santa with a blind once-only reveal, shared
wishlists (posting only, there is no reserving, and each guest is
capped at three wishes), and host polls (an option may carry one
photo, converted and stored as WebP by the same shared pipeline as
wishlist images; on edit an absent image key keeps the photo, null
removes it, a string replaces it).

**Spec is the source of truth.** The SDD pack lives in `docs/sdd/`
(SPEC-source.md, 00-spec-review.md, 01-proposal-and-design.md, 02-tasks.md).
Read it before changing behaviour; the spec's hard rules win over defaults
and best practices.

## Stack

- Laravel 13, PHP 8.3, Inertia Laravel 3 + Vue 3 (Inertia Vue 3.11), Vite 8
- MariaDB / MySQL in production; SQLite in-memory for tests only
- No Tailwind: plain CSS with custom properties for the spec's design tokens
- Lucide icons only (lucide-vue-next). No emojis in the UI, no text shadows

## Hard rules (summary, full text in the SDD pack)

1. No guest accounts, ever. Guests enter with the party invite code, their
   name, and a self-created 4-digit PIN (hashed; the session stores only
   `guest_id` + a `pin_verified` flag, never the plain PIN).
2. Recipient mappings never leak: `recipient_id`, `pin_hash`, and
   `reserved_by_id` must never appear in any API response, host view, log,
   or session. All responses are built through `App\Support\Serializers`.
   The only responses that ever carry a recipient are the single reveal
   and the two named exceptions, both scoped to a guest's own recipient,
   in that same guest's own session, and only once they have already
   revealed: `reveal_again` returns the recipient after fresh PIN
   verification, and `guest_recipient_wishlist` returns the recipient's
   codename plus that recipient's wishlist items. Unlike `reveal_again`,
   `guest_recipient_wishlist` needs no fresh PIN, because the session
   itself is PIN-verified at entry; that is the owner's requested
   behaviour. Reveal responses carry the recipient's secret name (the
   guest's self-chosen codename, set via `guest_set_secret_name`), never
   their real name, so
   real identities stay hidden until the gift exchange itself; a
   recipient who has not picked a secret name yet reveals as null. A
   guest's OWN secret name is under the same lock: it is never sent to
   the guest home page (the page gets only an `ownHasSecretName` flag)
   and is returned solely by `guest_secret_name_view`, after the guest
   re-enters their PIN. The Monito Monita weekly draws are a separate
   mapping governed by rule 8; they never involve the main exchange's
   `recipient_id`.
3. The host is blind too: dashboards show locked/awaiting counts and names,
   never who got whom.
4. Images are WebP only, never AVIF. Screenshots are converted to WebP on
   save (max side 1600px) and stored as data URLs. A wish carries up
   to three photos (2026-11-10): the store is the `images` JSON list
   of data URLs and the legacy `image` column mirrors the first
   (cover) photo. Adds/edits take an `images` array (fresh data URLs;
   on edit also "keep:N" markers for the Nth current photo; absent
   key keeps the set); more than three is `too_many_images` 422.
   Payloads never ship the stored blobs: `wishlistItem`'s `image`
   (cover) and `images` are the `/wishlist-items/{id}/image?v=<hash>` URL (cover; later photos add `/{index}`)
   URLs and that route serves the bytes with private immutable
   caching to signed-in guests, so the board payload stays small.
5. Guests changes (add/edit/remove) are blocked once any name is claimed;
   the draw is a derangement and re-runs automatically while unclaimed.
   The one sanctioned way to change assignments after claims is the
   host's reshuffle (`host_reshuffle` {except_guest_ids}, 2026-11-10):
   givers listed as exceptions keep their current recipient exactly;
   everyone else with a recipient is re-dealt from the remaining
   recipients (never themselves, never a kept recipient, still
   one-to-one). A single guest left over simply keeps their recipient.
   Claims and reveals are untouched, so a reshuffled guest who had
   revealed sees the new recipient via reveal-again. The response is
   counts only ({kept, reshuffled}); the host never sees a mapping.
6. API is a single dispatch route, `POST /api/{action}`; action names and
   error codes are a stable contract, do not rename them. The guest
   sign-in is the PIN-verified session (owner's framing, 2026-11-10):
   only `host_login`, `invite_check`, and `list` are public (the entry
   flow's own needs). `wishlist_list` requires the signed-in guest
   (never the host: the board carries codenames) and `poll_get` the
   signed-in guest or the host; both answer 422 `not_claimed` when
   signed out. The wishlist photo route follows the board: signed-in
   guests only, 404 otherwise, `private` caching. Guest secrets:
   `guest_set_secret_name` {secret_name} lets a claimed guest set their
   codename (trimmed, 2 to 30 characters, unique per party compared
   case-insensitively, enforced under a lock on the party row so
   simultaneous saves cannot duplicate it; errors
   `secret_name_invalid` 422 and `secret_name_taken` 409).
   Temporary secret names (2026-11-10): every guest is assigned a
   codename from the festive pair pools (`App\Support\TempSecretName`)
   when the host adds them, and migration 000013 backfilled all
   existing guests, so nobody displays as "still choosing"; the
   assignment is flagged (`guests.secret_name_temp`) until the guest
   picks their own via `guest_set_secret_name`, which clears the flag.
   `has_secret_name` in public and host payloads and the
   `secret_names_set` count mean PICKED, so the pick-one nudges keep
   reaching guests on a temporary name, while reveal and wishlist
   surfaces show the temporary value like any codename.
   `guest_secret_name_view` also returns `secret_name_temp` so the
   guest's own popup can label a temporary name. The secret name form also offers a
   "Suggest one for me" button that fills the field with a random
   client-side suggestion (festive word pairs); it is only a
   suggestion, and the same set action validates it like typed
   input. Public AND host payloads carry only the
   `has_secret_name` flag, never the value: the host is blind to
   secret names too (coverage comes from the `secret_names_set`
   count in `host_status`, and the host UI shows only picked or not
   picked). One exception:
   guest-facing wishlist surfaces identify wishers by codename, so
   `wishlist_list` entries and the member-wishlist page's `member` prop
   use `Serializers::wishlistMember` ({id, secret_name}, null when
   unset) and never carry the wisher's real display name or nickname.
   `guest_secret_name_view` {pin} returns the session guest's own secret
   name after PIN verification (same PIN failure codes as
   `reveal_again`); a claimed guest who has not picked one yet gets
   `secret_name_unset` 409, checked before the PIN. No failure response
   from it ever carries the value. `guest_recipient_wishlist` (no
   inputs) returns the session guest's recipient as the reveal shape
   (`Serializers::revealRecipient`), the party budget, and the
   recipient's wishlist items (the `wishlistItem` shape, newest first);
   gates are `not_claimed` 422 and `not_revealed` 409 (not yet revealed,
   or no recipient), and no failure response carries the codename or any
   item data. It is read-only and repeatable.
7. Testing is the owner's job on real devices: provide code-level checks
   (build, lint, `php artisan test`) and the acceptance checklist only.
   No browser automation or visual test servers.
8. Monito Monita (Kris Kringle) is a separate feature from the main
   exchange and never touches the main draw. Themes: one per party per
   Monday week (Asia/Manila), generated lazily and stored in
   `kringle_themes`, drawn at random from `KringleService::THEMES`
   without repeats until the pool cycles; the run ends at the party
   week (the `parties.date` anchor; no date, no end). Host regenerate
   (`host_kringle_regenerate`) swaps the current week's theme for an
   unused one and frees the old one. The roulette: each week, each
   guest spins once (`kringle_spin`, PIN-verified session, no fresh
   prompt) to draw that week's recipient live from the week's
   not-yet-drawn guests, never themselves, with the endgame guard in
   `KringleService::spin`; draws live in `kringle_draws`, unique per
   (party, week, giver) and per (party, week, recipient), and each
   week is independent. A week's pairings are never listed anywhere:
   a guest receives only their own recipient's real name
   (`Serializers::kringleRecipient`, {name}; Kringle is open by the
   owner's call, unlike the codename-only main exchange), and the
   host sees only the current theme and a spun count
   (`host_status.kringle`). The Kringle page's wheel names
   (`Serializers::wheelNames`) are the whole party's real display
   names as plain strings with no mapping, never the week's
   remaining pool. Guest navigation: the dock is Home / Monito
   (the Kringle page) / plus button / Wishlists / Vote; My wishes is a view
   inside the Wishlists page (`/guest/wishlists?view=mine`), and
   `/guest/gifts` redirects there. The roulette scroll and the gift
   reveal always animate: the owner exempted them from the
   reduced-motion freeze, like the mascot (2026-11-07).

## Working notes

- Delivery: pushed to the GitHub repo, the owner pulls, installs, builds,
  and runs the checklist. State plainly what you could not run.
- Build in the SDD pack's slices, in order, and list what to test after each.
