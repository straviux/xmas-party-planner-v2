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
Status: DONE 2026-11-04 (49 tests, 1,664 assertions; build passes; live E2E
verified: text/link/image kinds, PNG upload stored as a WebP data URL, bare
links normalised to https, own-item reserve refused, anonymous reserve with
reserved/reserved_by_me only, conflict for a second reserver, toggle
unreserve, non-owner delete forbidden, owner delete works). New error codes:
wish_empty, image_too_large, image_invalid, cannot_reserve_own,
already_reserved, wish_not_found.
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
Status: DONE 2026-11-04 (61 tests, 1,824 assertions; build passes; live E2E
verified: create validation, vote, change of vote keeping the total at 1,
per-guest my_vote, close refusing votes with poll_closed, winner reported on
the closed poll, reopen, delete). New error codes: poll_question_required,
poll_needs_options, poll_not_found, option_not_found, poll_closed.
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
Status: DONE 2026-11-04 (71 tests, 2,191 assertions; build passes; live E2E
verified: redraw blocked while claimed, unclaim all leaving the draw mapping
byte-identical at the DB level, redraw after unclaim producing a derangement,
reset one, reset PIN with re-entry creating a new PIN, clear wishlists count,
change password with old-password login refused, reset everything leaving
zero rows in all six tables). New error codes: password_incorrect,
password_too_short, password_mismatch, password_same.
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
Status: DONE 2026-11-04. Build complete: all six slices shipped. Polish
audit fixed the desktop top-nav centre button clipping, gave Guest Home a
real 3-column desktop grid, closed two reduced-motion gaps and two touch
targets under the minimums, and removed em dashes from host empty states.
Favicon added. Final README rewritten (run, deploy, host and guest guides,
phone test checklist). Automated sweep: all 32 API actions and 29 error
codes present, zero emojis, zero non-none text shadows, zero forbidden keys
in any serializer output. Full suite 71 tests / 2,191 assertions passing,
build passing, core guest-flow E2E re-verified on the final code. Remaining
verification is the owner's real-device acceptance pass per Hard Rule 8.

Completion round (2026-11-04, owner-requested): the Landing "Scan QR code"
action was a plain link, not a scanner. It now opens a real camera scanner
(getUserMedia + jsQR): accepts /join/{code} URLs, ?code= URLs, and bare
codes; camera denial/absence and non-invite codes get plain inline states
with a type-the-code fallback. Enter gained a "Scan QR code instead" entry
running the same invite_check in place, and the invite auto-check now maps
error codes properly (it previously labelled every failure as a wrong
code). The camera path can only be tested over HTTPS, so it is verified on
the deployed Hostinger site during the owner's acceptance pass.
- Desktop guest reflow pass against the checklist (top nav, 1120px
  container, dashboard grid), reduced-motion audit, empty-state copy pass,
  toast coverage, inline-error pass.
- README: local run, deployment (docroot `public/`, env, `composer install`,
  `migrate --seed`, build), host first sign-in + password change, guest flow
  in plain language, "what to test on your phone" list copied from the
  acceptance checklist.
- Full acceptance checklist sweep (code-level); hand the checklist to Arc
  for the real phone + desktop pass.

Owner tweak (2026-11-04): the Enter name search no longer lists all names
up front. Results appear only once the search has at least 2 letters;
below that a hint says so. Matching rules (nickname/alias, one-letter typo
tolerance) are unchanged.

Owner-directed change (2026-11-04, supersedes the Slice 2 tap-only tear):
the reveal is now a slide. The recipient name sits inside the slide track
under a sliding cover; dragging scrubs the cover (release before ~72%
springs back and consumes nothing), passing the threshold or tapping
commits the once-only reveal, and the cover then slides away slowly
(~1.25s) uncovering the name progressively. The name still only reaches
the page in the reveal response, so pre-commit dragging can never show
or burn it. Keyboard Enter/Space still commits; reduced motion shortens
the completion slide.

Owner feature (2026-11-04): reveal again using PIN. New API action
reveal_again (33rd action): the session guest, only if already revealed,
re-enters their PIN and gets their own recipient back (new error code
not_revealed for claimed-but-not-revealed). Guest Home carries a "See my
person again" card with a PIN popup; the name renders only inside the
popup and is cleared on close. First reveal stays once-only. AGENTS.md
hard rule 2 now names reveal_again as the single exception to the
reveal-only recipient rule. Suite: 80 tests / 2,251 assertions, live E2E
verified (wrong PIN leaks nothing, repeatable, per-guest isolation).

Owner UX round (2026-11-04): the reveal strip is now a shared
RevealStrip component used by both the Reveal page and the reveal-again
popup (PIN first, then slide to uncover the name inside the popup).
Forms moved into modals: host poll create/edit, host guest add/edit,
dashboard change password, and the guest add-a-wish form (dock centre
button opens the modal via ?add=1). Party Settings stays a page. Every
record delete now confirms through the shared ConfirmModal: guest remove
(was an inline row expansion), poll delete (was a one-off modal), and
guest wishlist deletes (grid tiles used a two-tap arm, quick view too;
the arm is gone, the popup is the single gate, on both the Gifts page and
the member wishlist page in mine mode).

Owner features (2026-11-04): the Wishlists board gained an "All wishes"
view beside "By person": one combined grid of every guest's items, each
tile labelled with the wisher's name (search matches wisher names too),
reserve works in place, own items show "Your wish" instead of a Reserve
button. Tapping a wish in All wishes opens a reels-style full-screen
viewer: one wish at a time, slide left/right (drag with spring-back,
arrows and keyboard on desktop, no wraparound), wisher header, counter,
reserve in place. By person browsing keeps the quick-view popup.

Owner removal (2026-11-04): the wishlist reserve (claim) feature is
removed entirely. Wishlists are posting only. The wishlist_reserve action
is gone (unknown_action now), payloads no longer carry reserved or
reserved_by_me, the Reserved badge/buttons and the Available/Reserved
filter chips are removed from grid, quick view, and reels, and the
already_reserved / cannot_reserve_own codes are deleted. The
reserved_by_id column stays in the schema, dormant and still never
exposed (a test proves even a populated legacy value surfaces nothing).
Suite: 81 tests / 2,203 assertions.

Owner addition (2026-11-04): wish editing. New wishlist_update action
(owner only, same validation and WebP pipeline as add; image absent =
keep, null = remove, string = replace; a wish still cannot be saved
empty). The add form is now a shared WishFormModal with an edit mode.
On tiles and in the quick view (own wishes only), the separate Delete
button is replaced by a single gear icon-only button opening a small
menu with Edit wish and Delete wish; Delete still goes through the
confirmation popup. Suite: 87 tests / 2,295 assertions.

Production fix (2026-11-04, from Arc's deployed pass): (1) the QR print
sheet rendered the 420px QR SVG inside a fixed 340px box with no size
constraint, so the code overflowed and printed over the link text;
the sheet now scales the SVG to its box and uses margin 4 for a proper
quiet zone. (2) /join/{code} stored the code only in the session before
redirecting to /enter, and the entry page never read it back, so a
dropped session cookie (scanner webviews) stranded guests at the start;
join now redirects to /enter?code={code} (which the entry page
auto-checks) and entry also receives the session code as a prop.
Suite: 88 tests / 2,299 assertions.

Mobile sizing round (2026-11-04, from Arc's production phone pass):
zoom disabled in the viewport meta (maximum-scale 1, user-scalable no)
plus viewport-fit=cover, which also makes the dock's safe-area padding
real on iPhones; every form control is 16px on small screens so iOS no
longer auto-zooms on focus (the PIN input keeps its large size);
popups cap at 100dvh - 32px and scroll internally; the reels viewer is
fully opaque and its media fills the slide's middle row; shell heights
gained dvh fallbacks.

Mobile round 2 (2026-11-04): the reels viewer is teleported to <body>
(it was confined to the board card's box because .card has a
backdrop-filter, which becomes the containing block for fixed
descendants) and the slide is exactly one viewport tall. The grid
toolbar is reordered everywhere: sort select (right) above the search
bar above the chips. Phone size pass at <=767.98px: buttons, chips, the
board view toggle, helper subtitles, counts line, and tile text all
step down ~10-15%; form-control font sizes untouched (16px anti-zoom
rule still wins); dock untouched.

Owner change (2026-11-04): the guest dock moves to the TOP on mobile
too (it was already the top nav on desktop). The mobile bar is now
flush to the top with rounded bottom corners and safe-area-inset-top
padding; the centre button sits inline instead of raised; page content
reserves top space instead of bottom; toasts move to the bottom edge
on mobile so they never cover the nav.
