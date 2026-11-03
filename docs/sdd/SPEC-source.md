# Full Prompt: Recreate the Christmas Party Planner App — With a Better Layout and Flow

Copy everything below the line into your builder / AI assistant. It is self-contained: no prior context, files, or screenshots are required.

---

## Role and goal

You are building a complete, production-ready Christmas party app from scratch. It is NOT a Secret Santa-only app. It is a general party app with three pillars:

1. **Secret Santa gift exchange** — one invite QR/link for everyone, guests find their own name, reveal their recipient exactly once.
2. **Wishlists** — every guest posts wishes, everyone can browse everyone else's wishes, and reserve a wish anonymously so nobody buys the same gift twice.
3. **Voting polls** — the host creates polls (for example, Christmas theme voting), guests vote once per poll and can change their vote until the host closes the poll.

Build it guest-first, because guests will use it on their phones at a party — but guest view must be optimised for BOTH desktop and mobile, not mobile-only. A guest on a desktop browser must get a properly reflowed desktop layout, not a stretched phone column and not a broken mobile reflow. Host management is desktop-first. Everything must work end-to-end without a tutorial: a guest who has never seen the app should be able to scan, find their name, and reveal in under a minute.

## Hard rules — do not change these, even if they seem unusual

These are deliberate product decisions, not oversights. If a rule conflicts with a "best practice" you would normally apply, the rule wins.

1. **No per-guest sign-in, ever.** Do NOT add guest accounts, guest passwords, guest email login, or guest registration. Many guests, especially older guests, find sign-in too hard. The invite code is the only gate for guests.
2. **Anyone with the invite code can pick any name and add/edit that name's wishlist.** This is accepted by design. Do NOT add ownership checks that prevent this, and do NOT warn guests about it in the UI.
3. **A guest name locks at entry.** The first time a guest confirms "Yes, that's me", that name is locked. Nobody else can re-enter that name and discover its recipient. A second entry attempt on a locked name must NOT show the recipient again.
4. **The recipient is revealed exactly once, and never shown to anyone else — not even the host.** The host dashboard must be completely blind: it shows locked/awaiting counts and names, never who got whom. Recipient mappings must never appear in any API response except the single reveal response, never be stored in a browser session, and never be logged.
5. **No emojis in the app UI.** Use Lucide icons only, and only icon names that actually exist in the installed Lucide library. No text shadows anywhere in the UI.
6. **No automatic migration of old party data.** If a previous version stored parties elsewhere, a new party must be set up fresh. Never silently import old data.
7. **Images must be WebP, never AVIF.** AVIF previews render as blank squares in some browsers (notably LibreWolf) while still taking up layout space. Convert every uploaded screenshot to WebP on upload (max side 1600px, quality ~85, alpha preserved). Never store AVIF. If an image fails to load, hide it — do not leave a blank square reserving its space.
8. **Testing is the owner's job.** Do not add visual test servers, browser automation, or manual-takeover verification steps to the build. Provide code-level checks only (build, lint, unit checks) and a clear acceptance checklist the owner can run on their own phone and desktop.

## Tech stack (use this, do not substitute without asking)

- **Backend:** Laravel (PHP 8.3+) with Inertia.js.
- **Frontend:** Vue 3, single-page feel with real routes (not hash URLs).
- **Database:** MariaDB / MySQL. No JSON file store, no SQLite for production data. Use Eloquent models, database transactions, and row locks (`lockForUpdate`) wherever two devices could race on the same record. Tables: `parties`, `guests`, `wishlist_items`, `polls`, `poll_options`, `votes`, `users`.
- **IDs:** Numeric database IDs. Treat IDs as opaque values in the frontend — never assume a string format.
- **Sessions:** Guest identity (guest ID + PIN, see below) persists in the server session across refresh. Host identity persists as a host user session. The recipient itself is never stored in a session.
- **Deployment (document it, do not deploy it):** Document root must be `public/`. Requires `composer install`, a real `APP_KEY`, `APP_URL` set to the deployed origin, production environment settings, writable `storage/` and `bootstrap/cache/`, a frontend build, and `php artisan migrate --seed`. The owner deploys themselves.

## Users and access model

### Guests (no account)

- Entry point: a single QR code / direct link for the whole party, containing the invite code. Scanning it lands on the guest entry page.
- The guest searches for their name. Search must match display name, nickname, AND aliases, case-insensitively, ignoring accents and extra spaces, and tolerating one-letter typos. Guests never type a name to create an entry — they tap an exact entry from search results, so spelling cannot mismatch.
- Before any lock, show an "Is this you?" confirmation. Only confirming locks the name.
- **Guest PIN — guest-created, not host-assigned:**
  - On first entry, if the guest has no PIN yet, ask them to create a 4-digit PIN and confirm it (two fields, both exactly 4 digits, must match). Creating the PIN and locking the name happen in the same transaction.
  - If a PIN already exists (set by the host optionally, or created by the guest earlier), ask for that PIN instead.
  - A returning locked guest must enter their PIN. A wrong PIN shows an error and does NOT lock them out, does NOT reveal anything, and does NOT unlock the name.
  - A locked guest who claimed before PINs existed creates a PIN on return instead of entering one.
  - The host may optionally set a PIN when adding a guest, but blank means "guest creates their own on first entry" — editing a guest must never force a PIN.
  - PINs are stored hashed only, never in plain text, never returned by any API.
- **Reveal — separate from entry, and only once:**
  - Entering a name does NOT reveal the recipient. Entry only locks the name and verifies/creates the PIN, then sends the guest to a dedicated reveal page with the name hidden.
  - The reveal page uses a tear-to-reveal control: a dashed pill with a lime-green circular knob containing a white right arrow, labelled "Tear". Tapping it slides the knob across the pill, then the recipient name pops in with a pop-in animation. If reveal fails (wrong PIN, already revealed), the strip slides back and can be tried again.
  - Reveal is the ONLY endpoint that returns a recipient. It verifies the PIN, sets a `revealed` flag, and returns the recipient once. A second reveal attempt returns `already_claimed` with no recipient.
  - Refreshing before tearing must send the guest back to the reveal page, not to guest home. Only a guest who has already revealed goes to guest home on return.
  - After revealing, show the recipient's name, nickname, and gift budget, with a clear warning to write it down, and a button to view that recipient's wishlist.
- **Guest home (after revealing):** A dashboard showing party name, locked count (locked / total), gift budget, and the guest's own wish count. Navigation is a fixed bottom dock: Home, Gifts, a raised centre create-wishlist button (star-plus icon, dark gold background, white icon, gold glow), Wishlists, Vote. The dock must stay fixed at the bottom on mobile and must not overlap content — reserve bottom padding on every guest page.
- **Guest logout:** Clears the guest identity and PIN from the session and returns to the landing home page. It does NOT unlock the name or clear the revealed flag.

### Host (username/password only — not PIN, not email)

- Host sign-in is username/password, backed by the `users` table. A seeder creates an initial host user from environment variables (`ADMIN_USERNAME` / `ADMIN_PASSWORD`), using first-or-create so re-seeding never resets a changed password. The host must change the seeded password immediately after first sign-in, via the host dashboard.
- Host pages are gated server-side AND client-side: when signed out, host routes return empty host props only (no guests, wishlists, polls, party name, budget, or date) and render only a sign-in-required screen with an inline username/password form. After sign-in, the user stays on the intended page. Sign-out goes to guest home.
- The landing home page has exactly one small, muted, underlined "Host sign-in" link below the Scan QR area, linking to the host dashboard. Guests cannot create a party from the home page. Host setup and management are reached only by host URLs.

## Features in detail

### 1. Party setup and Secret Santa draw

- Host creates a party in one flow: party name, date, gift budget, and the full guest list together. Each guest has a display name (required), nickname (optional), aliases (optional, for search), and an optional PIN (blank = guest creates their own).
- Editing party details later edits details only — it must not touch guests, claims, wishlists, or votes.
- The draw is a derangement: nobody draws themselves. With two guests, they swap. Draw happens on setup and automatically re-draws whenever guests are added, edited, or removed — but ONLY while no name is claimed. If any name is claimed, guest changes are blocked with a clear error ("Cannot change guests after names have been locked — reset claims first").
- The invite code is generated at setup. The host can copy it and regenerate it from the dashboard. Regenerating does not change guests, claims, or the draw.

### 2. Wishlists

- **My wishlist (Gifts):** The guest adds items with text, optional link, optional notes (where to buy, shop, size/colour), and an optional screenshot image. Kind is derived: image if an image is present, otherwise link if a link is present, otherwise text. Link and screenshot may coexist. Only the guest themselves can add or delete their own items (via their session PIN). Frontend upload limit: 10 MB. Server base64 cap: 14,000,000 characters. Accepted formats on upload: PNG, JPEG, GIF, WebP (converted to WebP on save, see Hard Rule 7).
- **Layout — this is a layout requirement, not a suggestion, and the design idea is a shopping app:** Wishlist viewing should feel like browsing a shopping app, not reading a list. The wishlist page has a header card (mascot, title, add button, short explainer, empty state). Wishes themselves are displayed BELOW that card, in a responsive product grid, optimised for images. Each tile is a product card: the image fills the top of the tile as a square, cropped to fill, with the wish text as the "product name", notes underneath like a product description, a link icon that opens the shop link like a "view product" action, and a prominent Reserve button that works like an "add to cart" button (full-width, primary colour, inside the card). A reserved item shows a clear "Reserved" badge overlaid on its image and its Reserve button becomes disabled. Text-only wishes use the same tile with no image area, so they still read as products. Tiles must not stretch to the tallest tile in their row.
  - **Shopping-app controls, above the grid:** a search box (searches wish text, notes, and link), filter chips (All / With photos / Text only / Available / Reserved), and a sort control (Newest first / Oldest first / A–Z). Filtering and sorting must update the grid instantly, without a page reload, and must work on both mobile and desktop.
  - **Grid breakpoints:** 2 columns on phones, 3 columns on tablets, 4 columns on desktop. On desktop the tiles get larger and the grid fills the wider content area — do not just centre a 2-column phone grid on a desktop screen. On desktop, hovering a tile shows a subtle lift/shadow and reveals the Reserve button more prominently, exactly like a product card hover state.
  - **Quick view:** Tapping a tile's image (on any screen size) opens a larger detail view of that wish — big image on top, full text, full notes, link, Reserved state, and the Reserve/Unreserve action — the equivalent of a shopping app's product detail page. Closing it returns to the exact scroll position in the grid.
- **Everyone's wishlists:** A searchable board (search by name, nickname, or alias, showing "(also: …)" for aliases). Tapping a member opens their wishlist in the same 2-column image grid. The board shows each member's wish count or "no wishlist yet".
- **Anonymous reserve:** Any guest except the owner can reserve an item. Everyone else sees only "Reserved" — never who reserved it. Only the reserver can unreserve it. An item already reserved by someone else cannot be reserved by another guest (return a conflict). A guest cannot reserve their own items. Reserving must happen inside a database transaction with a row lock on the item.

### 3. Polls

- Host creates, edits, closes, reopens, and deletes polls. Each poll has a question and a list of options. Multiple polls are supported.
- Guests vote once per poll and can change their vote until the host closes that poll. Enforce one vote per guest per poll with a UNIQUE constraint on (poll_id, guest_id) — do not rely on frontend checks alone.
- Results show counts and percentages only, never voter names. Closed polls show the final winner (highest votes) and refuse further changes.
- Guest poll view shows each poll, its open/closed state, total votes, each option with a radio-style selector, percentage and vote count, and a progress bar per option.

### 4. Host admin

Layout: fixed left sidebar, 260px wide, full viewport height, dark, not collapsible — brand "Host" + party name + "Admin" label, navigation Dashboard / Guests / Polls / Party settings with Lucide icons and a clear active state, and at the bottom "Signed in as [username]" + Sign out + "Back to guest home". Main content sits to the right of the sidebar, minimum 960px wide, so the whole admin area is at least 1220px and scrolls horizontally on narrower windows instead of reflowing for mobile. Each admin page has a header with title, subtitle, and its primary action on the right.

- **Dashboard:** Four stat cards (Guests, Locked, Awaiting, Polls), a locked/awaiting progress indicator, a Party panel (name, date, budget, invite Copy/Regen, QR Print), a Claimed table (Name, Nickname, Status, Reset per locked name, Refresh), Quick actions, Admin controls, and a Danger zone. No recipient data anywhere.
- **Guests:** A real table (Name, Nickname, Aliases, PIN set, Status, Actions) with a search box matching name/nickname/alias. Add, edit, and remove guests (with the claimed-block rule and automatic re-draw above). Removing a guest also removes their wishlist.
- **Polls:** A real table of all polls, newest first, with manage/close/reopen/delete actions.
- **Party settings:** A two-column panel form for party details. When no party exists, this page is titled "Create party" and includes the guest list table in the same panel; after creation, the draft is discarded.
- **Admin controls (host only):**
  - **Reset one:** Clears claimed + revealed for a single guest, so they can enter and reveal again. Does not clear their PIN, wishlist, or votes.
  - **Unclaim all:** Clears claimed + revealed for ALL guests. Does NOT redraw recipients, does NOT clear PINs, does NOT delete guests, wishlists, or polls. This is the prerequisite for Redraw.
  - **Redraw:** Re-draws the derangement. Blocked while any name is claimed.
  - **Reset PIN (per guest):** Clears ONLY that guest's PIN hash — claimed status, recipient, wishlist, and votes are untouched. Allowed even when the guest is claimed. This is the main recovery for a locked guest who forgot their PIN: after reset, they create a new PIN on return.
  - **Clear wishlists:** Deletes wishlist items only.
  - **Reset everything:** Deletes the entire party (party row, with cascades cleaning up guests, wishlist items, polls, options, and votes).
  - **Change password:** Host changes their own password from Admin controls.
- **Empty states are normal, not errors:** "No party yet" must be a normal empty state for reads (setup prompt, empty lists), never a 404. Public reads (polls, guest list, wishlist board) return empty data when no party exists. Writes still require a party.

## Layout and flow — the "better" part (fix the weaknesses of a naive version)

Design the flow so the guest never wonders "what do I do next", and the host never wonders "what does this button destroy":

1. **One clear next step per screen.** Landing home has exactly one primary action ("I'm a guest") and one secondary action ("Scan QR code"). No competing buttons, no back/close buttons where a dock already provides navigation. Titles live inside cards, not above them. Action buttons live inside cards, compactly sized.
2. **Mobile-first guest flow, in this exact order:** Landing home (mascot + one primary action) → Who are you? (search, tap exact entry, "Is this you?" confirm) → Create/enter PIN (one screen, two fields only on first entry) → Reveal (tear strip, once only) → Guest home (dashboard + dock) → Gifts / Wishlists / Vote (dock). Never show the recipient before the tear, never send a revealed guest back to reveal, never send an entered-but-not-revealed guest to guest home.
3. **Guest home is a dashboard, not a menu.** Stats first, then the dock does the navigating. The mascot sits fixed just above the dock on guest home, not floating over content.
4. **Wishlist is a gallery, not a list.** Image-led 2-column grid below the header card (see Wishlists above). On a phone, two columns of square images is the whole point — do not fall back to a single-column text list on mobile.
5. **Guest view is optimised for BOTH desktop and mobile — this is a requirement, not a stretch goal.** Do not ship a mobile-only guest view, and do not ship a desktop guest view that is just the phone column stretched wider. Mobile: single centred column (max about 672px), bottom dock, large touch targets (minimum 48px for primary buttons, 36px for icon buttons), bottom padding so the dock never covers content. Desktop: content reflows into a wider centred container (about 1120px), the guest home dashboard becomes a multi-column card grid, the wishlist grid goes to 4 columns (see Wishlists above), and navigation moves from the bottom dock to a centred top navigation bar with the same five destinations (Home, Gifts, centre create-wishlist button, Wishlists, Vote) — same labels, same icons, same order, so a guest who learned the app on their phone instantly recognises it on desktop. Host remains a separate desktop sidebar app (sidebar + wide tables + panels); guest desktop and host desktop are different layouts and must not be confused with each other.
6. **Destructive actions are visually separated and explicitly worded.** Danger zone actions (Reset everything, Clear wishlists) live in their own bordered section, with confirm dialogs that state exactly what will and will not be deleted. "Unclaim all" must be explained in its own subtitle: it does not redraw, does not clear PINs, and is the prerequisite for Redraw — do not leave the user guessing what it does.
7. **Every empty state tells the user what to do next.** No blank screens, no bare "No data". Examples: "No wishes yet — tap + to add your first one", "No party set up yet — the host needs to set it up first", "No polls yet".
8. **Toasts, not alerts.** One global dark-glass toast (about 3 seconds) for success and error feedback. Never use native `alert()` or `confirm()` for app feedback. Confirm dialogs are custom modals, not browser dialogs.
9. **Loading and error states are part of the flow.** A wrong PIN, a wrong invite code, a full wishlist, or a closed poll must show an inline, plain-language error in place — never a blank screen, never a raw server error string, never a redirect to a generic error page.

## Design system — use these exact tokens, do not invent a new palette

This is a dark app. Do not ship a light theme unless asked.

- **Page background (guests):** A full-viewport night-sky photograph is acceptable as a background, but only behind a dark indigo overlay (about 45–65% opacity) so cards and text stay readable. If no photograph is supplied, use the gradient below as the background. Do NOT use a flat black background.
- **Background gradient:** Linear, 156 degrees, `#2E335A` → `#1C1B33`. Add soft purple and teal radial glows behind content, never on top of it.
- **Text (dark scheme):** Primary `#FFFFFF`, Secondary / Tertiary / Quaternary `#EBEBF5` (use lower opacity for secondary/tertiary/quaternary, not a different grey). Body text must never be pure grey like `#71717A` on dark — it washes out.
- **Components:** Solids `#48319D` (purple), `#1F1D47` (dark navy), `#C427FB` (magenta accent), `#E0D9FF` (light lavender for links and secondary accents). Gradients: `#5936B4` → `#362A84` for primary buttons, `#3658B1` → `#C159EC` for progress bars and secondary gradients, `#2E335A` → `#1C1B33` for page background, `#F7CBFD` → `#7758D1` for radial glows. Cards: a purple-tinted glass gradient (`rgba(72,49,157,0.35)` → `rgba(31,29,71,0.55)`), 1px border at white 14% opacity, 20–28px corner radius, deep shadow. Primary buttons: purple gradient, white text, fully rounded, with a soft purple shadow. Secondary buttons: dark glass (white 10% opacity). Inputs: dark glass (white 10% opacity, white text, `#EBEBF5` at 60% opacity for placeholders) — never white-background inputs on a dark page.
- **Guest dock:** Fixed bottom pill, dark navy glass, fully rounded, with a raised centre button: 56px circle, dark gold `#B8860B` background, white icon, gold glow, and a dark ring so it reads as raised above the dock. Dock active item: magenta `#C427FB`.
- **Host accent (different from guest, on purpose):** Host primary buttons and active sidebar item use teal `#01B7C5` on a dark navy `#1E2138` sidebar, with host panels in `#2C2F48`, 20px radius, and shadow. Guests keep purple/magenta; hosts keep teal. Do not unify them into one accent — the colour difference is how a user instantly knows which side of the app they are on.
- **Mascot:** A pure-CSS mascot character (no image files, no video) with a beige chat bubble, brown text, teal offset shadow, and gentle float/wave animations. The mascot appears on guest pages only, never on host pages. Respect `prefers-reduced-motion`: disable float, wave, slide, and pop animations for users who prefer reduced motion. The mascot must never cover the dock or primary buttons.
- **Typography:** Inter for UI text. A rounded display font (for example, Baloo 2) only for mascot bubbles, uppercase, bold, centred. Titles inside cards are extra-bold, tight letter-spacing. Section labels are 10px, bold, 0.2em letter-spacing, uppercase.
- **Browser tab title:** "Secret Santa HQ".

## Data model (minimum)

- **Party:** id, name, budget (string, nullable), date (date, nullable), invite_code (string, unique), timestamps.
- **Guest:** id, party_id (FK, cascade delete), display, nickname (nullable), aliases (JSON, nullable), pin_hash (nullable), recipient_id (nullable self-FK to guests, null on delete), claimed (bool, default false), claimed_at (nullable timestamp), revealed (bool, default false), timestamps. Index on party_id.
- **WishlistItem:** id, guest_id (FK, cascade delete), text (nullable), link (nullable), notes (nullable), kind (string, default 'text'), image (long text, nullable — WebP data URL), reserved_by_id (nullable FK to guests, null on delete), timestamps. Index on guest_id. Reserved state is only ever exposed as `reserved` / `reserved_by_me`, never as a reserver ID.
- **Poll:** id, party_id (FK, cascade), question, open (bool, default true), timestamps.
- **PollOption:** id, poll_id (FK, cascade), text, sort_order, timestamps.
- **Vote:** id, poll_id, guest_id, poll_option_id (all FK, cascade), timestamps, UNIQUE(poll_id, guest_id).
- **User (host):** id, username (unique), password (hashed), timestamps. Email is not used for login and must not be required.

## API contract (keep it stable — the frontend depends on it)

Use a single dispatch route, for example `POST /api/{action}`, with these actions and error codes. Do not rename actions casually; the frontend calls them by name.

- Public reads: `list` (guests, public shape only), `wishlist_list` (board), `poll_get` (polls with counts and my_vote), `invite_check`.
- Guest actions: `enter` (locks name, verifies/creates PIN, returns `{ok:true}` with NO recipient), `reveal` (verifies PIN, sets revealed, returns recipient once, otherwise `already_claimed`), `guest_verify_pin`, `guest_set_pin`, `wishlist_add`, `wishlist_delete`, `wishlist_reserve`, `poll_vote`, `guest_logout`.
- Host actions (all require host session, otherwise 403): `host_login`, `host_logout`, `host_change_password`, `host_status`, `party_update`, `host_guest_add`, `host_guest_update`, `host_guest_remove`, `host_redraw`, `reset_one`, `host_unclaim_all`, `host_reset_pin`, `host_clear_wishlists`, `reset_all`, `host_status` with no party returns 200 with `exists:false` (not 403, not 404).
- Error codes to preserve exactly: `already_claimed`, `not_claimed`, `pin_incorrect`, `pin_required_create`, `pin_already_set`, `pin_must_be_4_digits`, `invite_incorrect`, `not_set_up` (only where a write requires a party; reads return empty data instead).
- Public guest shape: id, display, nickname, aliases, claimed, needs_pin, revealed. Never include recipient_id, recipient name, or pin_hash in a public or host response.
- Board item shape: id, text, link, notes, kind, image, reserved, reserved_by_me. Never include reserved_by_id.
- Poll shape: id, question, open, total, options [{id, text, votes}], my_vote. Never include voter names or voter IDs.

## Acceptance checklist — the app is not done until every line passes on a real phone and a real desktop

Guest flow:
- [ ] Scan QR / open invite link → landing home loads, one primary action is obvious.
- [ ] Search by nickname and by alias finds the guest; one-letter typo still finds them; tapping an entry asks "Is this you?" before any lock.
- [ ] First entry: create PIN (two fields, must match, both 4 digits) → name locks, NO recipient shown, guest lands on reveal page.
- [ ] Refresh before tearing → back on reveal page, not guest home, recipient still hidden.
- [ ] Wrong PIN on reveal → error, no recipient, tear strip slides back and can be tried again.
- [ ] Correct PIN + tear → recipient pops in once, with budget, with a button to their wishlist.
- [ ] Second reveal attempt (fresh entry, same name) → `already_claimed`, no recipient, guest goes to guest home only if already revealed.
- [ ] Returning guest with a forgotten PIN → host Reset PIN → guest creates a new PIN on return, claims/wishlist/votes untouched.
- [ ] My wishlist: add text, link, and image items; images display as square tiles in a shopping-style product grid below the header card, in WebP, in LibreWolf as well as Chrome/Safari; delete works; empty state guides the user.
- [ ] Wishlist as a shopping app: search, filter chips (All / With photos / Text only / Available / Reserved), and sort (Newest / Oldest / A–Z) all update the grid instantly; tapping an image opens a product-detail-style quick view with Reserve/Unreserve, and closing it returns to the same scroll position.
- [ ] Everyone's wishlists: search by alias works; reserve shows only "Reserved" to others; only the reserver can unreserve; own items cannot be reserved; already-reserved by another guest is refused.
- [ ] Polls: vote, change vote while open, closed poll refuses changes and shows the winner, results show counts/percentages only.

Host flow:
- [ ] Signed-out host pages show only the sign-in screen and leak no party data.
- [ ] Sign in with seeded credentials, change password immediately, re-sign-in with the new password works, re-seeding does not reset it.
- [ ] Create party + guest list together; draw gives nobody themselves; dashboard shows no recipient mappings anywhere.
- [ ] Add/edit/remove a guest while unclaimed → automatic re-draw, no recipient shown.
- [ ] Add/edit/remove a guest while claimed → blocked with a clear error.
- [ ] Unclaim all → all names can enter again, PINs/wishlists/polls untouched, no redraw until Redraw is tapped.
- [ ] Reset one, Reset PIN, Clear wishlists, Reset everything each do exactly what their names say and nothing more (verify Reset everything leaves no orphan guests, items, polls, or votes).
- [ ] Invite copy and regen work; regen does not change guests, claims, or the draw.
- [ ] No-party state shows a setup prompt, not a 404, on every host and guest page.

Layout / design:
- [ ] Guest pages work on both mobile and desktop: mobile is a single centred column with a bottom dock that never covers content, primary buttons are at least 48px tall; desktop reflows to a wider centred container with a top navigation bar (same five destinations, same order) and a multi-column guest home dashboard.
- [ ] Wishlist grid is shopping-style and responsive: 2 columns on mobile, 3 on tablet, 4 on desktop, image-led product cards with square tiles, Reserve as a full-width "add to cart" style button, Reserved badge on the image, no blank squares for failed images.
- [ ] Host pages are desktop sidebar + tables + panels, horizontal scroll on narrow windows instead of a broken mobile reflow.
- [ ] No emojis in UI, Lucide icons only, no text shadows, tab title is "Secret Santa HQ".
- [ ] Reduced-motion users see no float/wave/slide/pop animations.

## What NOT to build

- No guest sign-in, guest accounts, or guest email verification.
- No host view of recipient mappings, no "admin can see everything" override.
- No AVIF image handling, no image files committed to the repo as binaries (embed images as data URLs if a build system requires it, otherwise use CSS/SVG).
- No per-guest private wishlist visibility rules beyond reserve anonymity — wishlists are shared by design.
- No real QR camera scanner unless explicitly asked for later — a Scan QR button that opens the guest entry flow is acceptable as a first version, but do not claim it scans a camera if it does not.
- No light theme, no desktop guest layout that is just the phone column stretched to full width with no reflow, no tabbed viewer or named sessions unless asked for later.

## Deliverables

1. A runnable app (backend + frontend + migrations + seeder) that passes the acceptance checklist above.
2. A README with: local run instructions, deployment instructions (docroot, env, migrate + seed, build), the host first-sign-in + password-change steps, and the guest flow in plain language for a non-technical host.
3. A short "what to test on your phone" list, copied from the acceptance checklist, so the owner can verify without you.

Start by scaffolding the database, the guest entry/PIN/reveal flow, and the landing home page — that is the critical path. Do not start with host polish or extra features. Build in slices, in this order: (1) setup + draw, (2) entry/PIN/reveal, (3) wishlist, (4) polls, (5) host admin, (6) final polish and README. After each slice, list exactly what to test before moving on.
