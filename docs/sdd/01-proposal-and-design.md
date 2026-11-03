# 01 — Proposal and Design

## Proposal

Build a single-party Christmas party app (Laravel + Inertia + Vue 3,
MariaDB/MySQL) with three pillars — blind once-only Secret Santa reveal,
shared wishlists with anonymous reserve, and host polls — exactly per
`xmas-party-recreate-prompt.md`. Guest-first responsive layout (mobile dock,
real desktop reflow with top nav), separate desktop-first host admin
(sidebar, teal accent). No guest accounts, ever; invite code + name + PIN
is the entire guest identity model.

In scope: everything in the spec's Features, Layout, and Acceptance sections.
Out of scope: the spec's "What NOT to build" list, verbatim, plus multi-party
hosting (A4) and any deployment action (documented only).

## Architecture

- Pages (Inertia/Vue, real routes):
  - Guest: `/` landing, `/join/{code}` invite landing, `/enter` (search +
    confirm + PIN), `/reveal`, `/guest` home, `/guest/gifts`,
    `/guest/wishlists`, `/guest/wishlists/{guest}`, `/guest/vote`,
    `/guest/logout` (POST).
  - Host: `/host`, `/host/guests`, `/host/polls`, `/host/settings` — each
    renders only the sign-in screen when signed out, with empty host props.
- API: one dispatch route `POST /api/{action}` → `ApiController` dispatching
  to action methods/services. All actions and error codes per the spec's API
  contract, plus A1/A2 additions. Every response shape is an explicit
  allowlist serializer — models are never returned raw.
- Services:
  - `DrawService` — derangement (rejection-sampled shuffle, no fixed points;
    n=2 swap falls out naturally), re-draw triggers, claimed-guard.
  - `GuestEntryService` — search (A13), lock + PIN create/verify in one
    transaction with `lockForUpdate` on the guest row.
  - `RevealService` — verifies session `pin_verified` (D2) or PIN (D1-B),
    one transaction, sets `revealed`, returns recipient shape once;
    `already_claimed` otherwise, with no recipient in the payload.
  - `WishlistService` — kind derivation, WebP conversion (A12), reserve /
    unreserve inside a transaction with a row lock; exposes only `reserved` /
    `reserved_by_me`.
  - `PollService` — vote upsert on the UNIQUE(poll_id, guest_id) constraint,
    closed-poll guard, counts/percentages only.
  - `PartyService` — setup/upsert (A3), invite code generate/regen,
    admin resets (`reset_one`, `host_unclaim_all`, `host_reset_pin`,
    `host_clear_wishlists`, `reset_all`) each scoped to exactly what the
    spec says it touches.
- Auth: Laravel session guard reconfigured for username-only login against
  `users` (no email column required). Host middleware gates every host
  action (403) and host pages (empty props + sign-in screen).
- Guest session: `guest_id`, `pin_verified`, `invite_code`, timestamps.
  The recipient is never written to the session, a cookie, or a log.

## Data model

Exactly the spec's minimum (parties, guests, wishlist_items, polls,
poll_options, votes, users), with:
- `guests.recipient_id` nullable self-FK, `nullOnDelete`.
- `votes` UNIQUE(poll_id, guest_id).
- Cascades as specified so `reset_all` leaves no orphans.
- `pin_hash` via Laravel's hasher; never serialized.

## Design system

Tokens copied verbatim from the spec into CSS custom properties:
guest background gradient `#2E335A → #1C1B33` (156deg) with purple/teal radial
glows, glass cards (`rgba(72,49,157,0.35) → rgba(31,29,71,0.55)`, white 14%
border, 20–28px radius), purple primary gradient `#5936B4 → #362A84`,
magenta `#C427FB` dock-active, gold `#B8860B` centre button, host teal
`#01B7C5` on `#1E2138` sidebar / `#2C2F48` panels. Inter UI font, Baloo 2 for
mascot bubbles only. Lucide icons only, no emojis, no text shadows.
Pure-CSS mascot (guest pages only) with float/wave animations fully disabled
under `prefers-reduced-motion`, along with slide/pop animations.
Tab title: "Secret Santa HQ".

## Layout rules carried into every slice

- Guest mobile: centred column max ~672px, fixed bottom dock, bottom padding
  reserved on every page, primary buttons ≥48px, icon buttons ≥36px.
- Guest desktop (≥1024px): container ~1120px, top nav replaces the dock
  (same 5 destinations, same order), dashboard becomes a multi-column grid,
  wishlist grid 4 columns (2 mobile / 3 tablet), tile hover lift.
- Host: 260px fixed sidebar, content min 960px, horizontal scroll below that.
- One global dark-glass toast (~3s); custom confirm modals for destructive
  actions; inline plain-language errors; no `alert()`/`confirm()`.
