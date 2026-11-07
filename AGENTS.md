# Xmas Party Planner

Christmas party app: Secret Santa with a blind once-only reveal, shared
wishlists (posting only, there is no reserving), and host polls.

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
   re-enters their PIN.
3. The host is blind too: dashboards show locked/awaiting counts and names,
   never who got whom.
4. Images are WebP only, never AVIF. Screenshots are converted to WebP on
   save (max side 1600px) and stored as data URLs.
5. Guest changes (add/edit/remove) are blocked once any name is claimed.
   There is no pre-draw and no redraw: a guest's recipient is drawn live
   by the roulette at their first reveal (DrawService::assignForGuest,
   inside the reveal transaction; a random pick from the not-yet-picked
   guests, never themselves, with one forced pick on the second-to-last
   spin so the last guest can never be stranded with only themselves).
   Across all spins the result is still a derangement, and a recipient
   once assigned never changes (the hybrid path also keeps recipients
   pre-drawn before the roulette shipped). The `host_redraw` action is
   retired and removed from the dispatch map at the owner's direction;
   do not restore it.
6. API is a single dispatch route, `POST /api/{action}`; action names and
   error codes are a stable contract, do not rename them. Guest secrets:
   `guest_set_secret_name` {secret_name} lets a claimed guest set their
   codename (trimmed, 2 to 30 characters, unique per party compared
   case-insensitively; errors `secret_name_invalid` 422 and
   `secret_name_taken` 409). Public payloads carry only the
   `has_secret_name` flag, never the value; the host shape carries the
   value, and `host_status` counts `secret_names_set`. One exception:
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

## Working notes

- Delivery: pushed to the GitHub repo, the owner pulls, installs, builds,
  and runs the checklist. State plainly what you could not run.
- Build in the SDD pack's slices, in order, and list what to test after each.
