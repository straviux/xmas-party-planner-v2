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
   and `reveal_again`, the one named exception: it returns a guest's own
   recipient to that same guest, in their own session, only after PIN
   verification, and only when they have already revealed. Reveal
   responses carry the recipient's secret name (the guest's self-chosen
   codename, set via `guest_set_secret_name`), never their real name, so
   real identities stay hidden until the gift exchange itself; a
   recipient who has not picked a secret name yet reveals as null.
3. The host is blind too: dashboards show locked/awaiting counts and names,
   never who got whom.
4. Images are WebP only, never AVIF. Screenshots are converted to WebP on
   save (max side 1600px) and stored as data URLs.
5. Guests changes (add/edit/remove) are blocked once any name is claimed;
   the draw is a derangement and re-runs automatically while unclaimed.
6. API is a single dispatch route, `POST /api/{action}`; action names and
   error codes are a stable contract, do not rename them. Guest secrets:
   `guest_set_secret_name` {secret_name} lets a claimed guest set their
   codename (trimmed, 2 to 30 characters, unique per party compared
   case-insensitively; errors `secret_name_invalid` 422 and
   `secret_name_taken` 409). Public payloads carry only the
   `has_secret_name` flag, never the value; the host shape carries the
   value, and `host_status` counts `secret_names_set`.
7. Testing is the owner's job on real devices: provide code-level checks
   (build, lint, `php artisan test`) and the acceptance checklist only.
   No browser automation or visual test servers.

## Working notes

- Delivery: pushed to the GitHub repo, the owner pulls, installs, builds,
  and runs the checklist. State plainly what you could not run.
- Build in the SDD pack's slices, in order, and list what to test after each.
