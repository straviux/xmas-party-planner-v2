# Secret Santa HQ — Christmas Party Planner

A Christmas party app with three pillars: a Secret Santa gift exchange with a
blind, once-only reveal, shared wishlists with anonymous reservations, and
host-run voting polls. Guests never create accounts: the party invite code,
their name, and a self-created 4-digit PIN are all they need.

Built spec-first. The spec and the SDD pack live in `docs/sdd/` and are the
source of truth for behaviour and design.

**Build status:** Slice 1 of 6 (setup + draw) is complete. Slices: (1) setup +
draw, (2) entry/PIN/reveal, (3) wishlist, (4) polls, (5) host admin,
(6) polish + final README.

## Stack

- Laravel 13, PHP 8.3+, Inertia 3 + Vue 3, Vite 8
- MariaDB / MySQL in production (SQLite only for tests)
- Plain CSS with the spec's design tokens (no Tailwind), Lucide icons

## Run it locally

Requirements: PHP 8.3+ with GD (WebP support), Composer, Node 20+, and a
MySQL/MariaDB database (or SQLite for a quick look).

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
seeder never resets a password you have changed. **Change the seeded password
immediately after your first sign-in** (host dashboard, Admin controls; the
change-password control itself arrives in Slice 5, until then set a strong
`ADMIN_PASSWORD` before seeding).

## How it works, in plain language

For the host:

1. Sign in at `/host` with the seeded username and password.
2. Open **Party settings**, name the party, set the date and gift budget, and
   type the guest list (one name per line). Saving runs the Secret Santa draw
   automatically: everyone gets someone, nobody gets themselves.
3. From the dashboard, copy the invite link (or print the QR code) and share
   it with your guests.
4. Adding, editing, or removing guests re-runs the draw automatically, but
   only until the first guest locks their name. After that, guest changes are
   blocked until claims are reset from the dashboard.

For guests (full flow lands in Slice 2):

1. Open the invite link or tap "I'm a guest" on the home page.
2. Find your name, confirm "that's me", and create a 4-digit PIN.
3. Tear the strip on the reveal page to see who you are buying for. It is
   shown exactly once. Not even the host can see who got whom.

## Deployment

Document only, the owner deploys:

- Point the web server's document root at the app's `public/` folder.
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`,
  `APP_URL` set to the deployed origin, and your MariaDB credentials.
- Run `composer install --no-dev`, `npm run build`,
  `php artisan migrate --seed`.
- Make sure `storage/` and `bootstrap/cache/` are writable.

## Checks

```bash
php artisan test   # code-level checks (draw, API contract, secrecy scan)
npm run build      # frontend build
```

Device acceptance is done by the owner using the checklist in
`docs/sdd/SPEC-source.md`.
