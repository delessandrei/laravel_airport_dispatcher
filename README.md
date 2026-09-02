# Airport Dispatcher - POC

Dispatch management application for airport ground operations, built on
Laravel 13 with MongoDB. The entire development environment runs in Docker —
no PHP, Composer or database installation is required on the host.

Browse airports by country, inspect their terminals and gates, review the
arrivals and departures recorded for any given day, and see which gate each
flight was allocated to.

## Stack

| Component | Version | Notes |
|---|---|---|
| PHP | 8.5 | via the Laravel Sail runtime image |
| Laravel | 13.x | |
| MongoDB | 8 | through `mongodb/laravel-mongodb` |
| Redis | alpine | sessions and cache |
| mongo-express | 1.x | database web UI |

Two extra containers reuse the same application image and run background
processes, neither exposing a port. `scheduler` (`schedule:work`) is the cron
equivalent and drives everything. `queue` (`queue:work`) is wired up but idle:
nothing in the application dispatches jobs, because every scheduled task is
quick enough to run inline.

## Requirements

Docker with the Compose plugin. Nothing else.

## Getting started

```bash
git clone https://github.com/delessandrei/laravel_airport_dispatcher.git
cd laravel_airport_dispatcher

cp .env.example .env
# Set WWWUSER / WWWGROUP to your own ids so files created inside the
# container stay editable from the host:
sed -i "s/^WWWUSER=.*/WWWUSER=$(id -u)/;s/^WWWGROUP=.*/WWWGROUP=$(id -g)/" .env

docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app npm install
docker compose exec app npm run build
```

| Service | URL |
|---|---|
| Application | http://localhost:6030 |
| mongo-express | http://localhost:6031 (`admin` / `admin`) |
| MongoDB | `localhost:6032` (no web UI, use mongo-express) |
| Redis | `localhost:6033` (no web UI) |

Ports are set in `.env` and can be changed freely if they collide with
something else on your machine.

## Flight data

Flights come from the [OpenSky Network](https://openskynetwork.github.io/opensky-api/rest.html)
`/flights/arrival` and `/flights/departure` endpoints, keyed by **ICAO** code
(`LROP`), not the IATA code passengers see (`OTP`). Both are stored per airport.

This API requires an OAuth2 client. Register at opensky-network.org, then fill in:

```
OPENSKY_CLIENT_ID=
OPENSKY_CLIENT_SECRET=
```

**Without credentials the application still works**: it generates deterministic
demo traffic and labels every board with a visible "Demo data" banner. Adding
credentials switches to live data with no code change.

### Commands

```bash
php artisan gates:allocate                                   # place what has no gate
php artisan gates:validate [--all]                           # re-check current allocations
php artisan gates:report                                     # hourly statistics
php artisan gates:close --gate=A5 --reason="repairs" [--from=] [--until=]
php artisan gates:open  --gate=A5
php artisan gates:closures [--all]
```

All take `--airport`, defaulting to `EDDF`.

**Closing a gate moves the flights standing there.** Their allocation is
cleared and the allocator runs again for them alone; any that fit nowhere are
left unallocated with a reason, which the validator and the report pick up.
Reopening lets them through on the next allocation pass.

TODO: some terminals only handle arrivals — Cluj-Napoca (LRCL) is one. Gate
allocation should be restricted to the gates a flight's direction can actually
use, rather than treating every gate of an airport as interchangeable.

### Scheduled collection

A scheduled command collects one day of traffic and writes it into MongoDB:

```bash
php artisan flights:fetch [--airport=EDDF] [--date=2026-08-31] [--direction=arrival|departure]
```

The options exist for manual testing; omitted, they default to Frankfurt, today
and both directions. **The scheduler always runs the command
with its defaults**, and the airport is deliberately not exposed to the
environment — only the schedule is:

```
DISPATCH_CRON="0 * * * *"
```

Any five-field cron expression works. Hourly collection spends 1,440 of the
4,000 daily credits and lets today's board fill as the day goes on, which is
what gate planning needs. It is read through `config('dispatch.cron')`
rather than `env()` directly, so it survives `config:cache`. The `scheduler`
container picks changes up within a minute — no restart needed.

The default day is **today**, because gate planning is about the traffic being
handled now. Pass `--date` to collect a completed day instead.

Be aware that OpenSky reports flights it has *already observed*: a day still in
progress returns only the part that has happened so far, so a run early in the
morning collects very little.

### Staying inside the rate limit

The allowance is **4,000 credits per day** for the `/flights/*` endpoints, and
OpenSky charges by the number of calendar days a query spans. A local-midnight
window crosses one UTC boundary, so it counts as two partitions: **30 credits
per request, 60 to collect one airport-day** (arrivals plus departures).

Where that goes:

| | Cost |
|---|---|
| Hourly cron, re-collecting today at EDDF | 24 × 60 = **1,440 / day** |
| Pressing *Collect from OpenSky* | 60, per airport-day |
| Browsing, however many pages | **0** |

That leaves roughly 2,500 credits, about 40 new airport-days of browsing.

The cron re-collects the *whole* current day every hour, paying 60 to pick up
the handful of flights that appeared since. Asking only for the hour since the
last run would cost 4 rather than 30, because a window inside a single UTC day
spans one partition — but OpenSky attributes some flights hours after they
happen, and narrow windows would miss those. Re-collecting the whole day is the
deliberate trade.

- **MongoDB is the cache.** There is no response cache. An airport-day already
  in `flights` is served from the database for good. `flight_imports` records
  that a day was collected, so a day that genuinely had no traffic is not
  refetched on every page view.
- **Opening a page never calls the API.** Collection is an explicit action: the
  airport board carries a *Collect from OpenSky* button beside the arrivals and
  departures tabs, and only that button, or the scheduler, spends credits.
- **A stored day is never refetched** on its own, current or not. The scheduler
  keeps Frankfurt up to date; everything else waits to be pulled.
- **Future dates never reach the API** — there is nothing to observe yet.
- **A lock** prevents two collections of the same airport-day from overlapping,
  whether they come from the button or from the scheduler.
- **Each direction costs 30 credits**, so `--direction` is the cheap way to
  test. `flight_imports` records which halves of a day were collected, and the
  board only serves a day from the database once both are present.
- **Tests never call the API.** `phpunit.xml` blanks the credentials, forcing
  the demo provider. Running the suite costs nothing.

The remaining allowance is read from the `X-Rate-Limit-Remaining` header on
every response and printed under the flight board.

### What is stored per flight

Every field OpenSky returns is kept, including the six that describe how
confident its own estimate is:

| Stored as | From OpenSky | Meaning |
|---|---|---|
| `icao24`, `callsign` | same | aircraft and flight identity |
| `departure_airport`, `arrival_airport` | `estDepartureAirport`, `estArrivalAirport` | both ends, estimated |
| `first_seen`, `last_seen` | `firstSeen`, `lastSeen` | first and last radar contact |
| `departure_horiz_distance`, `departure_vert_distance` | `estDepartureAirport*Distance` | metres from the departure airport when first tracked |
| `arrival_horiz_distance`, `arrival_vert_distance` | `estArrivalAirport*Distance` | metres from the arrival airport when last tracked |
| `departure_candidates`, `arrival_candidates` | `*AirportCandidatesCount` | how many airports were weighed |
| `gate_code`, `gate_terminal` | — | filled by the allocation step |

### Data flow

```
scheduler ─────> flights:fetch ─┐
                                ├─> FlightImporter ─> OpenSky ─> MongoDB
"Collect from OpenSky" button ──┘                                   │
                                                                    │
web ────────────────────────────────────────────────────────────────┘
                                        reads only; never calls the API
```

## Gate allocation

Flights are placed at gates automatically. A flight holds a gate for
`GATE_OCCUPANCY_MINUTES` (90 by default), and a gate holds at most one flight at
a time.

### Where the occupancy window sits

T is the moment the flight touches this airport: landing for an arrival,
take-off for a departure. The window sits around T by a configured offset,
because a departing aircraft holds its gate *before* it leaves:

```
arrival    T = landing     window [T, T+90m)          offset 0
departure  T = take-off    window [T-90m, T)          offset -90
```

Set `GATE_OCCUPANCY_OFFSET_DEPARTURE_MINUTES=0` to read the requirement
literally instead, holding the gate for the 90 minutes after departure.

A caveat that shapes this: OpenSky reports *observed* traffic, so `first_seen`
for a departure is the take-off itself — measured over a full day at Frankfurt,
aircraft were on average 87 m above the airport and 3.8 km out at that instant,
and a third were still below 30 m. There is no scheduled departure time in the
data. A schedule provider can be added behind the same interface.

### Closed gates in the interface

A closed gate is drawn dark red and struck through in the gate grid, counted
under the Gates figure, and clicking it opens a panel with the reason and the
dates. Anything the database does not hold — a closure recorded without a
reason, or one with no end — reads as **Unknown**.

What counts as closed depends on the day being viewed. On the **current day** a
gate is shown closed only if it is closed *right now*: one reopened this morning
is available again. On any **other day** there is no "now" inside it, so any
closure overlapping that day counts.

### The two monitoring crons

| Command | Frequency | Writes to | Purpose |
|---|---|---|---|
| `gates:validate` | every 30s | `allocation_issues` | is each current allocation still valid? |
| `gates:report` | hourly | `allocation_reports` | movements, gate use, what could not be placed |

The validator checks three things, each with a real trigger:

| Check | Fires when |
|---|---|
| `double_booked` | two allocations overlap on one gate — the allocator has a bug, or two passes raced |
| `closed_gate` | a closure appeared outside `gates:close`, which would have relocated the flights |
| `malformed_window` | the occupancy no longer matches `GATE_OCCUPANCY_MINUTES`, usually because it changed |

Only `double_booked` tests an invariant of this code; the other two catch the
world changing underneath it.

It only examines allocations whose window overlaps a band around now —
`GATE_VALIDATE_GRACE_MINUTES` back, `GATE_VALIDATE_HORIZON_MINUTES` forward.
Finished occupancies are settled. Note that the band is expressed on the
occupancy window, not on T: with a 90-minute occupancy, a symmetric ±45 minutes
around T would miss 40% of the allocations actually in force, because a flight
that started 80 minutes ago is still at its gate.

### Design

`GateAllocator` knows nothing about the database, the clock or configuration —
gates, closures, demands and existing placements all arrive as arguments, and
the same input always yields the same output. That is what makes the allocation
rules testable on their own, in `tests/Unit/GateAllocatorTest.php`, which does
not even boot Laravel.

Everything it handles is a plain array, in the shape it is stored in:

```
demand     ['flight_id' => .., 'from' => CarbonImmutable, 'until' => ..]
gate       ['code' => 'A1', 'terminal' => 'T1', 'type' => 'jetbridge']
closure    ['gate_code' => 'A1', 'from' => ..|null, 'until' => ..|null]
allocation ['flight_id' => .., 'gate_code' => .., 'gate_terminal' => .., ..]
```

Four classes carry the whole feature: the rules, the persistence around them,
and the two crons that watch the result.

Gates are tried in a stable order — terminal, then gate number naturally, so A2
comes before A10 — and the first one that is neither closed nor taken wins.
`sortGates()` is the single method a smarter policy replaces; overlap detection
and persistence stay untouched.

## Routes

| Route | Purpose |
|---|---|
| `/` | Romanian airports (default) |
| `/?scope=europe` | country picker |
| `/?scope=europe&country=DE` | airports in one country |
| `/airports/{icao}` | terminals, gates and the flight board |
| `/airports/{icao}?date=YYYY-MM-DD&board=departures` | a specific day and board |
| `POST /airports/{icao}/collect` | pull that airport-day from OpenSky |

## MongoDB notes

MongoDB is schemaless, so this project deviates from a stock Laravel setup:

1. **Session, cache and queue run on Redis.
2. **Migrations only create indexes.** Collections are created implicitly on
   first write; `database/migrations` exists to declare indexes such as the
   unique constraint on `users.email`, which MongoDB would not enforce
   otherwise.
3. **Scheduled work needs the `scheduler` container.** The Sail image runs
   only the web server, so `routes/console.php` would never fire without it.
   The `queue` container is there for the same reason, should anything start
   dispatching jobs.
4. **Eloquent models must extend the MongoDB base classes.** See
   `app/Models/User.php` — it extends `MongoDB\Laravel\Auth\User` rather than
   the framework's `Authenticatable`. Primary keys are `_id` (ObjectId), not
   auto-incrementing integers.

## License

Proprietary — see [LICENSE](LICENSE). The source is published for evaluation
only; no rights to use or redistribute are granted.
