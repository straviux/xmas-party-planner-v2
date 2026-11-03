# 02 — Task Slices (build order fixed by the spec)

Each slice ends with: code-level checks (build/lint/unit where available) +
the "test this before we move on" list for Arc's phone and desktop.
No visual test servers or browser automation (Hard Rule 8).

## Slice 1 — Setup + draw (critical path start)
Status: DONE locally 2026-11-03 (Laravel 13.40 scaffold in
workspace/xmas-party-planner/app; 21 tests passed, 1410 assertions; frontend
build passes; fresh-DB migrate+seed verified; live smoke: / and /host 200,
signed-out host props carry authed:false only). Push to GitHub pending a repo
target from Arc (the GitHub App cannot create user repos, 403 verified; the
existing straviux/xmas-party-planner repo is the OLD app, still actively
committed by Arc on 2026-11-03, and must not be overwritten). Push manifest
prepared (97 files, ~718 KB).
- Laravel + Inertia + Vue 3 scaffold, Vite, design-token CSS, tab title.
- Migrations for all 7 tables (spec data model, cascades, UNIQUE vote key).
- `users` seeder: `ADMIN_USERNAME` / `ADMIN_PASSWORD`, first-or-create.
- Models + allowlist serializers (public guest, board item, poll shapes).
- `DrawService`: derangement, n=2 swap, n<2 guard (A8), claimed-guard error.
- Actions: `host_login`, `host_logout`, `host_status` (no party →
  200 `exists:false`), `party_update` (A3 upsert), `host_guest_add`,
  `host_guest_update`, `host_guest_remove` (auto re-draw while unclaimed,
  blocked while claimed; removing a guest removes their wishlist),
  `host_invite_regen` (A2), `invite_check`, `list`.
- Landing home page: mascot, one primary "I'm a guest", one secondary
  "Scan QR code", one muted Host sign-in link. No-party empty states.
- Test before moving on: seed a host, sign in, create a party with 5 guests,
  confirm nobody drew themselves; edit a guest and confirm the draw changed;
  open `/` signed out and confirm no party data leaks.

## Slice 2 — Entry / PIN / reveal
Status: DONE 2026-11-04 (36 tests, 1,535 assertions, all passing; frontend
build passes; live end-to-end run against a real server verified: host setup,
guest enter with PIN creation, /guest redirecting to /reveal before the tear,
reveal returning a non-self recipient with budget exactly once, second reveal
409 already_claimed with no recipient data, /reveal redirecting to /guest
afterwards, reveal after logout returning not_claimed). One new error code:
pin_mismatch (create-PIN confirmation mismatch).
- Guest search (A13 fuzzy matching), results tap-only (no free-typed names),
  "Is this you?" confirm.
- `enter`: one transaction — lock name, create PIN (two 4-digit fields, must
  match) or verify existing PIN; returns `{ok:true}`, never a recipient.
  Error codes: `pin_incorrect`, `pin_required_create`, `pin_already_set`,
  `pin_must_be_4_digits`.
- Session guest identity per D2; refresh routing: entered-not-revealed →
  reveal page, revealed → guest home.
- Reveal page + tear control (dashed pill, lime knob, slide + pop-in,
  reduced-motion safe). `reveal`: row lock, sets `revealed`, returns
  recipient name/nickname/budget once; second attempt → `already_claimed`.
- `guest_verify_pin`, `guest_set_pin`, `guest_logout`.
- Guest home dashboard: party name, locked x/total, budget, own wish count,
  fixed dock, mascot above dock.
- Forbidden-key check: grep API responses/fixtures for `recipient_id`,
  `pin_hash`, `reserved_by_id` outside the reveal service.
- Test: full checklist "Guest flow" items 1–7 on a real phone.

## Slice 3 — Wishlists (shopping-app layout)
- `wishlist_add` (text/link/notes/image; kind derived; 10 MB client limit,
  14M-char server cap; WebP conversion per A12; broken image hides itself),
  `wishlist_delete`, `wishlist_list` board.
- My Gifts page: header card + product grid below it (2/3/4 columns),
  square image tiles, Reserve as full-width primary button, Reserved badge
  overlay, tiles not stretched by row height.
- Grid controls: search (text/notes/link), filter chips
  (All / With photos / Text only / Available / Reserved), sort
  (Newest / Oldest / A–Z) — all instant, client-side over the loaded list.
- Quick view: product-detail overlay, Reserve/Unreserve inside it, closing
  restores exact scroll position.
- Everyone's board: search by name/nickname/alias with "(also: …)", wish
  counts / "no wishlist yet", member page in the same grid.
- `wishlist_reserve`: transaction + row lock, conflict if reserved by
  another, own items refused, anonymity preserved (`reserved_by_me` only).
- Test: checklist wishlist + reserve items, in Chrome and LibreWolf.

## Slice 4 — Polls
- Host actions A1: `host_poll_create`, `host_poll_update`,
  `host_poll_delete`, `host_poll_toggle`.
- `poll_get` (counts + `my_vote`), `poll_vote` (upsert on UNIQUE key,
  change allowed while open, closed refuses).
- Guest Vote page: radio-style options, percentages, progress bars, totals;
  closed poll shows winner(s) (A9 tie rule).
- Host Polls table: newest first, manage/close/reopen/delete.
- Test: vote, change vote, close, confirm changes refused and winner shown;
  confirm no voter names anywhere in responses.

## Slice 5 — Host admin (full)
- Sidebar layout (260px, teal, non-collapsible), header per page.
- Dashboard: 4 stat cards, locked progress, Party panel (Copy/Regen invite,
  QR Print per A5), Claimed table with per-guest Reset, Quick actions,
  Admin controls, Danger zone.
- Guests table (search across name/nickname/alias, PIN-set indicator).
- Party settings two-column form; "Create party" mode with guest list.
- Admin controls, each scoped exactly per spec: `reset_one`,
  `host_unclaim_all` (with its explanatory subtitle), `host_redraw`
  (blocked while claimed), `host_reset_pin`, `host_clear_wishlists`,
  `reset_all`, `host_change_password`. Custom confirm modals stating what
  will and will not be deleted.
- Signed-out host pages: empty props + inline sign-in form only.
- Test: full "Host flow" checklist, including re-seed not resetting a
  changed password.

## Slice 6 — Final polish + README
- Desktop guest reflow pass against the checklist (top nav, 1120px
  container, dashboard grid), reduced-motion audit, empty-state copy pass,
  toast coverage, inline-error pass.
- README: local run, deployment (docroot `public/`, env, `composer install`,
  `migrate --seed`, build), host first sign-in + password change, guest flow
  in plain language, "what to test on your phone" list copied from the
  acceptance checklist.
- Full acceptance checklist sweep (code-level); hand the checklist to Arc
  for the real phone + desktop pass.
