# Kris Kringle: weekly themed exchange (DESIGN ONLY)

Status: designed 2026-11-07 with the owner. NOT built. Build only on
the owner's go-ahead; this note is the spec for that build.
Amended 2026-11-07: the exchange is run by a roulette in the app
(the owner's calls that day), replacing the original in-person lot.

## Concept

A Kris Kringle (Monito Monita) alongside the Secret Santa flow,
with the main exchange gift completely untouched. The app generates
one theme every Monday, the same theme for everyone, openly
announced. Each week, every guest spins the roulette once to draw
who they are buying for that week: a fresh recipient for every
Monday theme. Everyone brings a gift matching the theme for the
person they drew.

## Rules (owner's calls, 2026-11-07)

- Themes are NOT secret: one theme per week, the same for all,
  openly announced. The weekly recipients are per-guest: you see
  only your own, shown by codename (secret name), and the week's
  pairings are never listed anywhere, the host included, matching
  the app's blindness rules.
- The app generates the theme, from a built-in pool, at random,
  never repeating a theme for a party until the pool has cycled.
- Fixed run: a theme for every Monday week until the party week.
  The anchor is the party's existing `date` field: the week
  containing the party date gets NO theme (the party is that week's
  event). If no party date is set, themes simply continue weekly.
- One spin per guest per week. The spin can happen any time during
  the theme's week; the result stays visible to that guest for the
  rest of the week, and past weeks keep their recipient in the
  guest's own history.
- Each week is independent: drawing the same person again in a
  later week is allowed and normal.

## Roulette draw design

- The spin IS the lot, drawn live per week (the live-draw rules
  worked out for the roulette apply here, scoped to the week):
  inside a transaction, the spinner picks at random from the
  guests not yet drawn as recipients THAT week, never themselves.
  Across a week's spins everyone is drawn exactly once.
- Endgame guard, per week: when exactly two givers are left in
  the week and the other giver is still undrawn, the spinner must
  take that other giver, so the last spinner of the week can never
  be stranded facing only themselves.
- Storage: kringle_draws (party_id, week_start, giver_id,
  recipient_id), unique per (party, week, giver) and per (party,
  week, recipient). Spinning needs a claimed guest session; any
  party guest can be drawn as a recipient, claimed or not. A party
  needs 2+ guests for anyone to spin.
- Known edge, accepted in the design: the week's counts include
  guests who may never spin. The no-self and no-repeat guarantees
  hold for everyone who does spin regardless; in the rare case a
  last spinner is also the week's last undrawn guest while another
  guest never spins at all, the spin reports that nobody is left
  to draw instead of ever assigning them themselves.

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
  a date is set), the caller's own week state (not spun yet, or
  their recipient's codename), and the history of past weeks
  (theme plus the caller's own recipient codename per week).
- Guest action `kringle_spin`: performs the caller's draw for the
  current week (once per week) and returns the recipient's
  codename only.
- Guest page `/guest/kringle`: Scor, the current theme big, the
  week dates, the roulette (spin button before the spin, the
  drawn codename after it), then the past-weeks list.
- Guest home: a card showing this week's theme and whether the
  guest has spun yet, linking to the page. No dock change.
- Host action `host_kringle_regenerate`; host dashboard card
  showing the current theme, how many guests have spun this week
  (a count only, never who drew whom), and the Regenerate button.

## Testing (planned, with the build)

Generation picks an unused theme; the party-week cutoff holds
(no theme generated for the party's week or later); regenerate
swaps to another unused theme and frees the old one. Spins:
a week's spins in any order draw everyone exactly once and never
the spinner; the second spin in a week is refused; the endgame
guard forces the saving pick; a new week starts with a clean
pool; payloads carry only the caller's own recipient codename,
never another guest's pairing, and no real names.
