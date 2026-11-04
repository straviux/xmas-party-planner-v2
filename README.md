# Secret Santa HQ: Christmas Party Planner

A Christmas party app with three pillars: a Secret Santa gift exchange with a
blind, once-only reveal, shared wishlists, and
host-run voting polls. Guests never create accounts: the party invite code,
their name, and a self-created 4-digit PIN are all they need.

Built spec-first. The spec and the design/build notes live in `docs/sdd/` and
are the source of truth for behaviour and design.

## Stack

- Laravel 13, PHP 8.3+, Inertia 3 + Vue 3, Vite 8
- MariaDB / MySQL in production (SQLite only for the test suite)
- Plain CSS with the spec's design tokens (no Tailwind), Lucide icons
- Guest screenshots are stored as WebP (converted on upload, never AVIF)

## Run it locally

Requirements: PHP 8.3+ with GD (WebP support required), Composer, Node 20+,
and a MySQL/MariaDB database (or SQLite for a quick look).

```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env: set DB_* to your database, and ADMIN_USERNAME / ADMIN_PASSWORD
npm install
npm run build
php artisan migrate --seed
php artisan serve
```

The seeder creates the first host user from `ADMIN_USERNAME` /
`ADMIN_PASSWORD` in `.env` (defaults `host` / `changeme`). Re-running the
seeder never resets a password you have changed. Change the seeded password
right after your first sign-in, from the host dashboard's Admin controls.

## How it works, in plain language

For the host:

1. Sign in at `/host` with your username and password.
2. Open **Party settings**, name the party, set the date and gift budget, and
   type the guest list (one name per line; nicknames, aliases, and an
   optional preset PIN can be added per guest later on the Guests page).
   Saving runs the Secret Santa draw automatically: everyone gets someone,
   nobody gets themselves.
3. From the dashboard, copy the invite link (or print the QR code) and share
   it with your guests.
4. Adding, editing, or removing guests re-runs the draw automatically, but
   only until the first guest locks their name. After that, guest changes
   are blocked until claims are reset.
5. Create polls on the Polls page (for example, voting on a party theme).
   Guests can change their vote until you close the poll.
6. The dashboard's Admin controls and Danger zone handle the fixes: reset
   one guest, reset a forgotten PIN, unclaim all names (this does not
   redraw), redraw recipients, clear wishlists, or delete the whole party.
   Each button explains exactly what it does and does not touch. You can
   never see who got whom, and neither can anyone else.

For guests:

1. Open the invite link, or tap "I'm a guest" on the home page and type the
   invite code.
2. Search for your name (nicknames and aliases work, and a small typo is
   fine), tap your name, and confirm it is you.
3. Create a 4-digit PIN you will remember. This locks your name to you.
4. Slide the strip on the reveal page to see who you are buying for, plus
   the gift budget. Write it down; if you forget, your home page can show
   it again after you re-enter your PIN.
5. Add your own wishlist under Gifts (text, a shop link, notes, and a photo
   if you like). To change or remove one of your wishes later, use the
   gear button on it and choose Edit wish or Delete wish. Browse everyone's wishlists from the Wishlists tab, by
   person or all together, and swipe through them in the full-screen
   viewer. Wishlists are for posting and browsing only; there is no
   reserving.
6. Vote in the host's polls under Vote. You can change your vote until the
   host closes a poll.

Signing out never unlocks a name. If you forget your PIN, ask the host to
reset it from the Guests page; you will create a new one on your next visit,
and your claim, wishlist, and votes stay exactly as they were.

If you revealed your person but forgot to write the name down, your home
page has a "See my person again" card: enter your PIN and the name is shown
to you again, in a popup. It only ever shows your own person, in your own
signed-in session, and only after your PIN checks out.

## Deployment

The owner deploys; this app ships with instructions only.

- Point the web server's document root at the app's `public/` folder.
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`
  (`php artisan key:generate`), `APP_URL` set to the deployed origin, your
  MariaDB credentials, and `ADMIN_USERNAME` / `ADMIN_PASSWORD` set to the
  first host login you want.
- Run `composer install --no-dev --optimize-autoloader`, `npm ci`,
  `npm run build`, then `php artisan migrate --seed`.
- Make sure `storage/` and `bootstrap/cache/` are writable by the web
  server user.
- PHP needs the GD extension with WebP support, or guests cannot upload
  wishlist screenshots.
- Sessions use the database driver; the sessions table is created by the
  migrations above, so no extra setup is needed on shared hosting.

## Checks

```bash
php artisan test   # draw, entry/reveal secrecy, wishlist, polls, admin scope
npm run build      # frontend build
```

The suite includes secrecy scans: no API response may contain recipient
mappings or PIN hashes, outside the reveal responses (the one-time reveal
and the PIN-gated reveal-again, both only ever to the guest themselves).

## What to test on your phone (and once on desktop)

Guest flow:

- [ ] Open the invite link: the home page loads with one obvious main button.
- [ ] Search by nickname and by alias; misspell a longer name by one letter.
      Tapping a result asks "Is this you?" before anything locks.
- [ ] Create a PIN (two fields, must match): your name locks, no recipient
      is shown, and you land on the reveal page.
- [ ] Refresh before tearing: you are back on the reveal page, name hidden.
- [ ] Slide the strip (drag it, or tap it): the cover slides away slowly and
      your person's name is uncovered inside the strip itself, shown exactly
      once, with the budget and a button to their wishlist below. Dragging
      the strip partway and letting go must spring it back without revealing
      anything. Write the name down.
- [ ] Sign out, enter the same name with a wrong PIN: plain error, nothing
      revealed. With the right PIN you go to your home page, not a reveal.
- [ ] On your home page, use "See my person again": a wrong PIN shows an
      inline error and no name; your real PIN shows the same person you
      revealed. The name must not stay visible after you close the popup.
- [ ] Add wishlist items: text only, a link, and a photo. The photo shows as
      a square tile in a shop-style grid, also in LibreWolf.
- [ ] In the grid, try search, the filter chips, and sorting: instant.
      Tap a photo for the big view, close it, and confirm you kept your
      scroll position.
- [ ] Vote in a poll, change your vote, and after the host closes it,
      confirm voting is refused and the winner is shown.

Host flow (desktop):

- [ ] While signed out, every host page shows only the sign-in form.
- [ ] Sign in, change the seeded password from Admin controls, sign out,
      and sign back in with the new password.
- [ ] Create a party with the guest list; the dashboard shows counts and
      no recipient information anywhere.
- [ ] Add a guest while nobody has locked a name: it just works. Lock a
      name as a guest, then try adding a guest again: a clear refusal.
- [ ] Forget a PIN on purpose: Guests page, Reset PIN; the guest creates a
      new PIN next visit and loses nothing.
- [ ] Unclaim all, check the subtitle matched what happened (no redraw, no
      PINs cleared), then Redraw and confirm it works now.
- [ ] Reset everything on a throwaway party and confirm the dashboard
      returns to the clean setup state.

Layout:

- [ ] On the phone the dock stays at the bottom and never covers content;
      main buttons are comfortably tall.
- [ ] On desktop the guest pages widen, navigation moves to a top bar with
      the same five stops, and the wishlist grid goes to four columns.
- [ ] If your system is set to reduce motion, the mascot and animations
      stay still.
