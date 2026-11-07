# Kris Kringle: weekly themed exchange (DESIGN ONLY)

Status: designed 2026-11-07 with the owner. NOT built. Build only on
the owner's go-ahead; this note is the spec for that build.

## Concept

An open (non-secret) Kris Kringle alongside the Secret Santa flow.
No pairings, no draw, no codenames, nothing hidden. The app's whole
job is the weekly theme: it generates one theme every Monday, every
guest follows the same theme, everyone brings a gift matching it,
and the gifts are exchanged by lot at the meetup (a physical draw,
the app does not run it).

## Rules (owner's calls, 2026-11-07)

- NOT secret: no assigned recipients, openly announced themes.
- The app generates the theme, from a built-in pool, at random,
  never repeating a theme for a party until the pool has cycled.
- Fixed run: a theme for every Monday week until the party week.
  The anchor is the party's existing `date` field: the week
  containing the party date gets NO theme (the party is that week's
  event). If no party date is set, themes simply continue weekly.
- Exchange by lot happens at the meetup, in person. Out of scope
  for the app.

## Generation design

- Lazy, cron-free (matches this app's hosting): the first time the
  current week's theme is requested in a new week, it is generated
  once and stored. Unique (party_id, week_start) guarantees it can
  never double-generate or change mid-week, even with two guests
  opening the app at the same moment.
- Weeks are Monday-start, Asia/Manila. Only the current week is
  ever generated; there is no backfill of weeks before the feature
  ships. History is exactly the stored rows, newest first.
- Regenerate (host): replaces the current week's theme with a
  different unused one; the replaced theme returns to the pool for
  that party (it counts as unused again).

## Theme pool (24, shipped in code)

Something long; something soft; something red; something sweet;
something round; something shiny; something blue; something
useful; something that makes a sound; something that smells good;
something edible; something green; something you can wear;
something for the desk; something yellow; something hard;
something small; something warm; something with stripes;
something that lights up; something made of paper; something
minty; something that comes in pairs; something golden.

## Surfaces (planned)

- Guest action `kringle_current`: the current week's theme, its
  week range, the run state (weeks left until the party week when
  a date is set), and the history of past themes.
- Guest page `/guest/kringle`: Scor, the current theme big, the
  week dates, a line that gifts are exchanged by lot at the
  meetup, then the past-themes list.
- Guest home: a card showing this week's theme, linking to the
  page. No dock change.
- Host action `host_kringle_regenerate`; host dashboard card
  showing the current theme with the Regenerate button.

## Testing (planned, with the build)

Generation picks an unused theme; the party-week cutoff holds
(no theme generated for the party's week or later); regenerate
swaps to another unused theme and frees the old one; the guest
payload carries only theme data (nothing else to leak: the mode
has no secrets by design).
