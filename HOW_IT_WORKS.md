# How This Application Works

A Laravel + Inertia (Svelte) app that lets you browse Phish's live performance
history tour-by-tour and see which songs have — or haven't — been played. All of
its data comes from the [phish.net v5 API](https://docs.phish.net/), but the app
never hits that API during a normal page load. Instead it mirrors phish.net into
a local database via a background sync loop and serves everything from there.

---

## The big picture

```
                       ┌───────────────────────────────────────────┐
                       │  Background sync (queue worker + loop job)  │
                       │                                             │
  phish.net v5 API ──► │  PhishNetClient ──► PhishNetImporter ──►    │ ──► local DB
   (only touched here) │                     (hash-gated writes)     │
                       └───────────────────────────────────────────┘
                                                                          │
                                                                          ▼
  Browser (Svelte)  ◄── JSON  ◄── PhishNetExamplesController ◄── PhishNetRepository
       │                              (web routes)              (cache in front of DB)
       │  useHttp() XHR calls
       └── SongChecker.svelte renders tours, played / not-played songs
```

Two independent halves:

- **Read path (user-facing):** browser → controller → repository → cache → DB.
  Fast, no API key involved, no external calls.
- **Write path (background):** a self-re-dispatching queue job pulls from
  phish.net and writes to the DB only when the data actually changed.

They meet only at the database and the cache.

---

## The backend

### Data model

The phish.net payloads are normalized into six tables (see
`database/migrations/2026_07_20_0138*`):

| Table                   | Model                | Holds                                            |
| ----------------------- | -------------------- | ------------------------------------------------ |
| `venues`                | `Venue`              | Venue name, city, state, country                 |
| `tours`                 | `Tour`               | Tour name and date range (`tourwhen`)            |
| `songs`                 | `Song`               | The full song catalog + all-time `times_played`  |
| `shows`                 | `Show`               | One row per show (date, year, venue, tour, artist) |
| `setlist_entries`       | `SetlistEntry`       | One row per song played at a show                |
| `phishnet_sync_states`  | `PhishNetSyncState`  | Bookkeeping: last-seen hash per data feed        |

`artistid === 1` means Phish itself; the endpoints also return side projects and
guests, which are filtered out where it matters.

### The PhishNet service (`app/Services/PhishNet/`)

Five small classes, each with one job:

- **`PhishNetClient`** — the *only* class that talks to the API. A thin wrapper
  over `Http` with retries and a 30s timeout. The API key is injected once in
  `AppServiceProvider` from `services.phishnet.key`, so it never leaks into a
  web response. Nothing in a page-load request path calls this class.

- **`PhishNetImporter`** — writes raw payloads into the DB. Every import is
  **idempotent**: rows are upserted by their upstream primary key, and rows that
  vanished from the payload are deleted (so upstream corrections propagate
  instead of leaving orphans).

- **`PhishNetSynchronizer`** — the orchestrator. Fetches a payload, and imports
  it **only if it changed** (see below). Also owns all the "is a show happening
  right now?" logic.

- **`PhishNetRepository`** — reads data back out of the DB for the frontend. Puts
  a forever-cache in front of already-serialized payloads; the cache is only
  busted when a sync detects a real change. The DB is the source of truth; the
  cache is just the serialization layer.

- **`VenueTimezone`** — maps a US state to an IANA timezone. phish.net venue data
  has no timezone, so it's derived from the state. Precision is loose on purpose
  (see the show-window logic below).

### Change detection (why the API isn't hammered)

phish.net exposes **no "last modified" timestamp**. So the synchronizer hashes
each payload and compares it to the hash from the previous sync
(`PhishNetSynchronizer::whenChanged()`):

```
$hash = sha256(json_encode($rows));
if ($state->hash === $hash) {
    // touch checked_at, do nothing else — no DB writes, no cache bust
    return false;
}
// hash differs → import, then record the new hash + changed_at
```

An unchanged payload costs **one API request and nothing else**. This is what
makes it safe to poll frequently.

### Getting data in

- **`php artisan phish:backfill`** — one-time historical import. Walks every show
  year from 1983 (`phishnet.sync.first_year`) to now, plus the song and venue
  catalogs. Historical years never change, so they're imported once and then read
  from the DB forever.
- **`php artisan phish:sync`** — a single manual sync of one year (defaults to
  the current tour's year); `--catalogs` also refreshes songs and venues.
- **`php artisan phish:watch`** — starts the continuous background loop (below).

### The background sync loop

`phish:watch` dispatches the `SyncPhishNetTour` job, which **re-dispatches itself**
after each run — a self-perpetuating loop. A queue worker must be running for it
to advance (`php artisan queue:work`).

Each run:

1. Checks whether a show is currently in its window (read *before* syncing, so a
   show that just started tightens the interval on this pass).
2. Syncs the current show year. If that year changed, it also re-syncs the song
   catalog (play counts may have moved).
3. Schedules the next run — the interval depends on whether a show is live:
   - **No show:** `phishnet.sync.interval` (default **3600s / 1 hour**).
   - **Show underway:** `phishnet.sync.active_interval` (default **360s / 6 min**;
     kept above 300 because phish.net asks clients not to poll faster than ~5 min).

Robustness details baked in:

- `ShouldBeUniqueUntilProcessing` + `uniqueId()` prevent two loop chains running
  at once; the lock releases when processing *starts* (not finishes), because the
  job re-dispatches itself from inside `handle()`.
- On failure, `failed()` still re-arms the loop, so one upstream outage doesn't
  silently kill all future syncing.
- Only the **live** show year is ever polled; historical years are never touched
  again.

---

## Detecting whether a show is in progress

This is the trickiest part, because the API gives us almost nothing to work with:
**no "show in progress" flag and no end-of-show marker.** A finished show keeps
its setlist forever, so "has a setlist" alone would read as *live* indefinitely.

So a live show is **inferred** from *a scheduled date* + *the wall clock*, in two
stages (all config lives in `config/phishnet.php` under `show_window`).

### Stage 1 — the outer gate (cheap, no API call)

`withinGate()` asks: could a US show *possibly* be running right now? Evaluated in
Eastern time (`gate_timezone`):

- Nothing starts before **6pm Eastern** (`gate_start_hour = 18`).
- A west-coast show is over by **4am Eastern** (`gate_end_hour = 4`).

Outside those hours — roughly 14 hours a day — the answer is instantly "no" and
**no schedule lookup hits the wire**.

### Stage 2 — the per-show window (venue-local)

Inside the gate, `showdateInWindow()` asks phish.net for the shows scheduled on
each candidate date. Two dates are checked:

- **today** — an evening show belongs to today's date, and
- **yesterday** — because after midnight a show that's still running belongs to
  *yesterday's* showdate.

`fetchShowsForDate()` is the one endpoint that returns *scheduled* (not-yet-played)
shows, which is what makes live detection possible. Only Phish's own shows
(`artistid === 1`) are considered.

For each candidate show, the venue's timezone is resolved from its state
(`VenueTimezone`) and the window is evaluated in that **local** time
(`nowIsInsideWindowFor()`):

- **Opens 7pm** local (`start_hour = 19`) — an hour before a typical 8pm downbeat,
  to cover early starts.
- **Closes 1am** local the next morning (`end_hour = 1`) — covers a long second
  set plus encore.

The timezone only needs to be roughly right: a ~6-hour window around a ~3-hour
show means a zone that's an hour off still lands inside the window.

### Two distinct questions

The synchronizer exposes two related-but-different checks:

- **`inShowWindow()`** → *is the clock inside a scheduled show's window?* This is
  the **pacing signal** for the sync loop. It goes true an hour before downbeat,
  so the loop is already polling on the fast interval by the time setlist entries
  start landing. This is what the loop actually uses.
- **`showInProgress()`** → *is a show window open **and** does the setlist already
  have entries upstream?* A stricter "songs are actively being played right now"
  signal. The window is what bounds it — without the window a finished show's
  setlist would make this true forever.

---

## Live updates in the browser

An open page keeps itself current during a show without ever reaching the
phish.net API — it only ever polls **our own** cheap endpoint.

### The version flag

After every run, the sync loop publishes a small snapshot to the cache
(`PhishNetSynchronizer::publishLiveState()` → `PhishNetRepository`, cache key
`phishnet.live`):

```json
{ "version": "<hash>", "inShowWindow": true, "year": 2026, "showdate": "2026-07-19", "updatedAt": "..." }
```

`version` is simply the current show-year's setlist payload hash — the same hash
the change-detection already stores in `phishnet_sync_states`. So it **moves
exactly when new setlist data lands** and is free to read (no API call, no query
beyond one cache/row lookup). `showdate` names the show whose window is currently
open (or `null`), which the sync loop already resolved while pacing itself — it
lets a page tell whether it is looking at the show being played right now.

### The endpoint

`GET /data/live` (`PhishNetExamplesController@liveStatus`) returns the snapshot
plus a `pollInterval` and reads **only from the cache**:

```json
{ "data": { "version": "<hash>", "year": 2026, "showdate": "2026-07-19", "inShowWindow": true, "pollInterval": 60 } }
```

### The client loop (`resources/js/lib/live-poll.svelte.ts`)

Three pages — **Song Checker**, **Recent Setlists**, and **Setlist Browser** —
share one composable, `createLivePoll()`. It runs a **self-pacing** poll (a
`setTimeout` loop, not a fixed `setInterval`), mirroring the server's own
idle/active split:

1. Poll `GET /data/live`.
2. If `version` changed from the last seen value, call the page's `onStale(status)`
   callback, which silently refetches the relevant setlists and re-derives the
   view — no navigation, no flash of a loading state over existing content.
3. Schedule the next poll:
   - **`CLIENT_SYNC_ACTIVE_INTERVAL`** (default **60s**) when `inShowWindow` is true,
   - **`CLIENT_SYNC_INTERVAL`** (default **3600s / 1 hour**) otherwise.

The window decision comes from the server (which is the only place that knows),
so the client never reimplements the show-window logic — it just obeys the flag
and picks one of its two intervals. The loop is torn down on component unmount.

Each page decides what "stale" means for it:

- **Recent Setlists** refetches the whole current year and re-groups the shows.
- **Song Checker** refetches the live year and rebuilds the selected tour in place.
- **Setlist Browser** refetches **only** when the date on screen equals the
  snapshot's `showdate` — i.e. you're viewing the show being played — since every
  other date is historical and never changes.

The composable exposes reactive values the UI reads:

- **`secondsRemaining`** — a per-second countdown to the next poll, rendered as
  the "Next update: …" indicator (`formatCountdown()` turns it into `45s` /
  `12:05`). It appears on Recent Setlists, in the Song Checker's "setlists for
  tour" section, and on the Setlist Browser when viewing the live show.
- **`inShowWindow`** / **`activeShowdate`** — gate the indicator and are passed to
  the relevant `SetlistView` as `awaitingNextSong`, which renders a small spinning
  `LoaderCircle` where the next song will appear.

Note the client can only react as fast as the **server** refreshes the snapshot:
during a show the loop re-publishes every `active_interval` (≈6 min). Polling
faster than that just means the page reflects each server update promptly — and
keeps the countdown ticking so the page always shows when it will next check.

---

## The frontend and how it talks to the backend

### Rendering

The app is an Inertia SPA with Svelte 5 pages in `resources/js/pages/`. Routing is
server-side: `web.php` maps `/` to `PhishNetExamplesController@songChecker`, which
does `Inertia::render('SongChecker', [...])`. Inertia mounts the matching Svelte
component (`SongChecker.svelte`) and passes props.

The initial page load carries only two small props from the server —
`excludedSongs` and `defaultMinPlayed` (from config). **Everything else is fetched
client-side** after mount.

### Two kinds of routes

`routes/web.php` has two groups:

- **Page routes** (`/`, `/recent-setlists`, `/setlist-browser`) → return an
  Inertia page.
- **Data routes** under the `data/` prefix → return **plain JSON** for the
  frontend's XHR calls:

  | Route                        | Controller method       | Returns                          |
  | ---------------------------- | ----------------------- | -------------------------------- |
  | `data/show-years`            | `showYears`             | Distinct list of show years      |
  | `data/setlists/year/{year}`  | `setlistsForYear`       | Every setlist row for a year     |
  | `data/setlists/{showdate}`   | `setlistForDate`        | One show's setlist               |
  | `data/songs`                 | `songs`                 | The full song catalog            |
  | `data/live`                  | `liveStatus`            | Version hash + poll interval (see below) |

Each JSON method just delegates to `PhishNetRepository` (cache → DB) and wraps the
result as `{ "data": [...] }`.

### Type-safe calls with Wayfinder

The frontend doesn't hardcode URLs. Laravel **Wayfinder** generates TypeScript
functions from the controller (`resources/js/actions/.../PhishNetExamplesController.ts`),
imported in `SongChecker.svelte`:

```ts
import { setlistsForYear, showYears, songs as songsRoute }
    from '@/actions/App/Http/Controllers/PhishNetExamplesController';
```

Calling e.g. `setlistsForYear.url(year)` yields the correct URL, type-checked
against the backend route.

### The data-loading flow (`SongChecker.svelte`)

Fetches use Inertia v3's `useHttp()` hook (a plain XHR client, not a full Inertia
visit — so no page navigation, just JSON).

On mount:

1. `GET data/show-years` → builds the year list (badges), then auto-selects a year
   (the saved one from a cookie, else the latest).
2. `GET data/songs` → the full catalog, needed for the "not played" view.

When a year is selected, `loadYear()` lazily fetches
`GET data/setlists/year/{year}` **once** and memoizes it in a `SvelteMap`
(`yearData`), so re-visiting a year is instant.

### Deriving what's shown

Everything the UI displays is computed **client-side** from those payloads using
Svelte `$derived` runes — no extra server round-trips for filtering:

- `buildToursForYear()` groups a year's rows into its distinct tours.
- `tourRows` → the rows for the selected tour (Phish-only, matching `tourid`).
- `songCounts` → per-song play counts within the tour (the **Played** view).
- `notPlayed` → catalog songs **not** in `songCounts`, filtered by the
  "all-time play count" slider (`minTimesPlayed`), the "Only Phish Songs" toggle,
  and the `excludedSongs` list (the **Not Played** view).
- Clicking any song opens a dialog showing its catalog entry and every performance
  in the current tour.

### Persisted preferences

User choices (selected year/tour, slider value, view mode, toggles, panel state)
are written to a `tour-explorer-prefs` cookie via a `$effect`, and read back on the
next visit so the app reopens where you left off.

---

## Configuration reference

| Setting                              | Env var                          | Default | Meaning                                        |
| ------------------------------------ | -------------------------------- | ------- | ---------------------------------------------- |
| `services.phishnet.key`              | `PHISHNET_API_KEY`               | —       | API key (server-side only)                     |
| `phishnet.sync.interval`             | `PHISHNET_SYNC_INTERVAL`         | 3600    | Seconds between checks when idle               |
| `phishnet.sync.active_interval`      | `PHISHNET_SYNC_ACTIVE_INTERVAL`  | 360     | Seconds between checks during a show (>300)     |
| `phishnet.sync.first_year`           | `PHISHNET_FIRST_YEAR`            | 1983    | Earliest year for `phish:backfill`             |
| `phishnet.show_window.gate_*`        | `PHISHNET_SHOW_GATE_*`           | 18 / 4  | Eastern-time outer gate hours                  |
| `phishnet.show_window.start/end_hour`| `PHISHNET_SHOW_START/END_HOUR`   | 19 / 1  | Venue-local show window hours                  |
| `phishnet.client.interval`           | `CLIENT_SYNC_INTERVAL`           | 3600    | Browser poll interval when idle (seconds)      |
| `phishnet.client.active_interval`    | `CLIENT_SYNC_ACTIVE_INTERVAL`    | 60      | Browser poll interval during a show (seconds)  |
| `app.default_min_played`             | `DEFAULT_MINPLAYED`             | 10      | Default "not played" play-count threshold      |

## Running it locally

```bash
php artisan migrate            # create the tables
php artisan phish:backfill     # one-time historical import (needs PHISHNET_API_KEY)
composer run dev               # serve app + vite + queue worker together
php artisan phish:watch        # start the live sync loop (needs a queue worker running)
```
