# 00 — Spec Review

Source of truth: `xmas-party-recreate-prompt.md` (Arc, 2026-11-03)
Review date: 2026-11-03
Status: APPROVED by Arc 2026-11-03 — all recommendations in section D accepted
(D1 = Option A, D2 = pin_verified flag, D3 = Option A GitHub repo route).
Assumptions in section A apply as written. Slice 1 started 2026-11-03.

## What the spec nails down (no interpretation needed)

- Three pillars: Secret Santa (once-only blind reveal), shared wishlists with
  anonymous reserve, host polls with one changeable vote per guest.
- Hard rules 1-8, exact design tokens (guest purple/magenta, host teal),
  data model, error codes, acceptance checklist, build order (6 slices).
- Single dispatch API: `POST /api/{action}`.
- Guest flow order is exact: Landing → Who are you → PIN → Reveal → Guest home.
- Host is blind to recipient mappings everywhere, including API responses.

## D — Decisions for Arc (block Slice 2, not Slice 1)

### D1. Reveal: is there a second PIN prompt?
The flow shows one PIN screen before Reveal, but the API contract says
`reveal` "verifies the PIN" and the checklist has "Wrong PIN on reveal →
error, tear strip slides back".
- Option A (recommended): No second prompt in the UI. Entry verifies/creates
  the PIN, the server session carries a `pin_verified` flag, and the reveal
  endpoint still checks that flag + claimed + not-revealed server-side before
  releasing the recipient. The "wrong PIN on reveal" checklist item becomes an
  API-level check (a direct reveal call without a verified session fails).
  Keeps the under-a-minute flow and the exact screen order in the spec.
- Option B: Reveal page asks for the PIN again above the tear strip. One extra
  step, literally matches the checklist wording.

### D2. What the session stores
The spec's Sessions line says "guest ID + PIN" persists in the server session.
Storing the plain PIN, even server-side, is avoidable risk.
- Recommended: store `guest_id` + `pin_verified` (bool) + timestamp only,
  never the plain PIN. Behaviour is identical from the guest's side; the PIN
  itself exists only as `pin_hash` in the database.
- Say the word if you want the spec followed literally instead.

### D3. Delivery route for the code
This VM has Node 24 but no PHP/Composer installed. Ubuntu 24.04's apt ships
PHP 8.3, so installing the toolchain here is feasible, but VM replacements
wipe system installs (workspace files survive).
- Option A (recommended): New GitHub repo under straviux, same pattern as the
  scholarship app — Kana pushes via the connector, Arc pulls, installs PHP
  dependencies, builds, and runs the acceptance checklist locally. Kana cannot
  run `composer`/`artisan` checks in that route and will say so per slice.
- Option B: Install PHP 8.3 + Composer in this VM, build and run code-level
  checks here, hand over the finished project as a download. Toolchain may
  need reinstalling after VM replacements.
- Option C: A only for source, plus B later if a slice needs a runnable check.

## A — Assumptions (recommended resolution applied unless Arc objects)

- A1. Missing host poll actions. The API list has no poll management actions,
  yet host poll CRUD is a core feature. Add, following the existing naming:
  `host_poll_create`, `host_poll_update`, `host_poll_delete`,
  `host_poll_toggle` (close/reopen). No existing action is renamed.
- A2. Missing invite regen action. Add `host_invite_regen`.
- A3. Party create vs `party_update`. One action: `party_update` creates the
  party when none exists (upsert), edits details only when one does. This
  matches Party settings becoming "Create party" when empty. Guest list on
  creation goes through `host_guest_add` in the same setup flow, then the draw
  runs.
- A4. Single active party. The whole UI/API operates on the one party.
  `reset_all` deletes it; a new setup starts fresh. The schema still uses
  `party_id` FKs as specified.
- A5. QR Print. Host dashboard generates a QR code client-side from the
  invite link (SVG, `qrcode` npm package) and opens a print view. No image
  binaries committed, no server endpoint added. Guest "Scan QR code" stays a
  button into the entry flow — it does not claim to scan a camera (per spec).
- A6. Invite link format: `/join/{invite_code}` — validates the code, stores
  it in the session, and lands on the guest entry search. A wrong code shows
  the `invite_incorrect` inline error on the landing page.
- A7. Hard Rule 2 vs "only the guest themselves can add/delete their items".
  Resolution: add/delete checks the *session* guest (`wishlist_add` /
  `wishlist_delete` only ever write to the session guest's list). Anyone who
  entered as a name (and passed its PIN once set) *is* that guest for the
  session — that is what Hard Rule 2 accepts. Browsing everyone's wishlists
  stays open to any entered guest; reserving is governed by the reserve rules,
  not ownership.
- A8. One-guest party. A derangement is impossible for n=1. Draw requires
  2+ guests; with 1 guest the recipient stays null and setup shows a plain
  warning. With 2 guests they swap (spec-explicit).
- A9. Poll tie. A closed poll with tied top counts shows all tied options as
  winners. Spec says "the winner (highest votes)" and is silent on ties.
- A10. Centre dock button (star-plus) is an action, not a page: it goes to
  Gifts with the add form open (`/guest/gifts?add=1`). Same behaviour for the
  centre button in the desktop top nav.
- A11. Styling: no Tailwind. The spec gives exact tokens and never names
  Tailwind; use plain CSS with custom properties for the tokens, Vite-built.
  Fonts: Inter + Baloo 2 via Google Fonts with system fallbacks.
- A12. Image pipeline: upload arrives as base64 in the dispatch payload
  (14,000,000-char server cap as specified). Server converts to WebP with GD
  (`imagewebp`, max side 1600px, quality 85, alpha preserved) and stores the
  WebP data URL in `wishlist_items.image`. If GD/WebP is missing on the
  server, `wishlist_add` with an image returns a plain-language error instead
  of storing an unconverted image.
- A13. Fuzzy search: normalise (lowercase, strip accents via transliteration,
  collapse spaces), substring-match display/nickname/aliases, plus
  Levenshtein distance ≤ 1 on tokens of 4+ letters. Party lists are small, so
  this runs in PHP over the party's guests — no search service.
- A14. Versions pinned at scaffold (2026-11-03, verified locally): Laravel
  13.40, PHP 8.3.6, Inertia Laravel 3.7 / Inertia Vue 3.11, Vue 3.5,
  Vite 8, lucide-vue-next 1.0, qrcode 1.5. PHP 8.3 + Composer were installed
  in this VM solely to scaffold and run code-level checks; delivery remains
  the D3 GitHub route. Tailwind (Laravel skeleton default) removed per A11.

## Risks worth naming now

- Recipient secrecy is the whole product. Every serializer must be allowlist-
  based (public/host shapes exactly as specified); a single stray
  `recipient_id` in a response breaks Hard Rule 4. Each slice that touches a
  response gets a grep/check for forbidden keys.
- Race conditions: reveal-once, reserve, and vote all need transactions +
  `lockForUpdate` + the UNIQUE(poll_id, guest_id) constraint. These are in
  Slice 2/3/4 acceptance, not polish.
- Host `users` table has no email: Laravel auth must be configured for
  username-only credentials, and password reset flows stay disabled (host
  recovery is re-seed-proof `firstOrCreate` + change-password only).
