# Fuel Detour

**Enter a start and destination (e.g. Holyhead → Manchester). See the cheapest petrol or diesel near the route, and whether a detour is actually worth it once the extra miles are paid for.**

It pairs two public APIs that were never designed to work together:

| | Provides | Can't do on its own |
|---|---|---|
| **[UK Fuel Finder](https://www.gov.uk/guidance/access-the-latest-fuel-prices-and-forecourt-data-via-api-or-email)** (statutory open data scheme, live since Feb 2026) | Every forecourt's location and current prices | No routing, no "near me" search: bulk paginated dumps only, one request at a time |
| **[openrouteservice](https://openrouteservice.org)** (HeiGIT, free plan) | Geocoding, driving routes, a road distance matrix | Knows nothing about fuel prices |

The interesting part is the **seams** between them; they're listed [below](#the-seams), each linked to the code that handles it.

---

## Run it (about 5 minutes)

**Requirements:** PHP 8.3+ with the `pdo_sqlite` extension, and Composer. No Node, no database server, no Docker.

```bash
git clone <this repo> fuel-detour && cd fuel-detour
composer setup          # install, .env, SQLite database, migrations, load sample data
```

Then add a **free openrouteservice key** (sign up at <https://openrouteservice.org/dev/#/signup>, about 2 minutes) to `.env`:

```dotenv
ORS_API_KEY=your-key-here
```

```bash
php artisan serve       # http://localhost:8000
```

Open <http://localhost:8000/?from=Holyhead&to=Manchester&fuel=E10>.

### Sample data vs. live data

Out of the box `FUEL_FINDER_DRIVER=fixture`, so **no Fuel Finder account is needed to review this**. The fixtures in [tests/Fixtures/fuel-finder](tests/Fixtures/fuel-finder) are **fictional "Demo Fuels" stations with made-up prices** along the real A55/M56 corridor. They're shaped exactly like real API responses and deliberately contain every data defect described below. The UI shows a "SAMPLE DATA" banner in this mode.

To use real prices, register at the [Fuel Finder developer portal](https://www.developer.fuel-finder.service.gov.uk/access-latest-fuelprices) (needs a GOV.UK One Login), then:

```dotenv
FUEL_FINDER_DRIVER=live
FUEL_FINDER_CLIENT_ID=...
FUEL_FINDER_CLIENT_SECRET=...
```

```bash
php artisan fuel:sync        # first full load: ~35 requests, ~1-2 minutes
php artisan schedule:work    # keeps it fresh (stations hourly, prices every 15 min)
```

### Tests

```bash
php artisan test             # 77 tests, ~2 seconds, fully offline
```

Every HTTP call is faked (`Http::preventStrayRequests()`), so tests never touch real APIs or quotas. `phpunit.xml` pins the fixture driver so a reviewer's `.env` can't leak in.

> **Windows note:** if you see `could not find driver`, enable `extension=pdo_sqlite` in your `php.ini` (it ships with PHP but is often commented out).

---

## How it works

```
                 every 15 min / hourly (scheduler)
 Fuel Finder ───────────────────────────────────────▶ local SQLite copy
   (OAuth, 500/batch,          FuelDataSync            stations, prices,
    1 request at a time)     + FuelFinderNormaliser    sync_runs (audit)
                                                              │
 User: "Holyhead → Manchester"                                │
   │                                                          ▼
   ├─▶ ORS geocode ×2 (cached 7d) ─▶ ORS route (cached 24h) ─▶ CorridorSearch
   │                                                          │  bbox in SQL, then exact
   │                                                          │  point-to-polyline in PHP
   │                                                          ▼
   └─◀ verdict + table + map ◀── DetourCalculator ◀── ORS matrix ×1
                     (whole result cached 5 min, invalidated by the next price sync)
```

A user's search **never calls Fuel Finder**. It costs at most 4 openrouteservice requests, and 0 when cached.

**"Is the detour worth it?"** For each station:
*total cost = litres bought × price + (extra miles ÷ MPG × 4.54609 L/gal) × price*.
This is compared against the *convenient* station (the one needing the least detour). A detour is recommended only if it saves at least £0.50 (configurable). Extra minutes are shown, but time isn't given a cash value.

### Endpoints

| | |
|---|---|
| `GET /?from=&to=&fuel=&mpg=&litres=&corridor_miles=` | Web page (GET, so every search is a shareable URL) |
| `GET /api/journey?…same params…` | The same result as JSON |
| `GET /api/status` | Sync audit: requests used, rows rejected or repaired, and why, per run |

`fuel` is one of `E10`, `E5`, `B7_STANDARD`, `B7_PREMIUM`. Errors from either upstream come back as
`{"error": {"source": "openrouteservice", "code": "quota_exhausted", "message": "…"}}` with a meaningful status (422 for bad input, 503 for upstream unavailable).

---

## The seams

### Authentication
| Seam | Handling |
|---|---|
| Fuel Finder uses OAuth2 client credentials; tokens last 1h; the token response is wrapped `{success, data}` but data endpoints return bare arrays | Token cached and refreshed 2 min early; one automatic re-auth on a 401 ([FuelFinderClient](app/Services/FuelFinder/FuelFinderClient.php)) |
| ORS uses a plain API key header | Missing or invalid key gives a specific message, not a stack trace |
| Fuel Finder reportedly returns **403 to non-UK IPs** | A 403 is reported as "check the server has a UK IP", not "bad credentials" |

### Rate limits & concurrency
| Seam | Handling |
|---|---|
| Fuel Finder: 1 concurrent request per client (a second one gets a 429) | All syncs share one cache lock ([FuelDataSync](app/Services/FuelFinder/FuelDataSync.php)); requests are sequential and spaced out |
| Fuel Finder: 100/min per official docs, 30/min per a community copy of the spec | Defaults to a conservative 2.1s gap (configurable). A full sync is ~35 requests, so it costs about a minute |
| 429 / 5xx | Retried with exponential backoff, honouring `Retry-After`; 401/403/404 are never retried blindly |
| ORS: **403 = daily quota used up**, 429 = per-minute limit | The two are told apart and explained; `x-ratelimit-remaining` is surfaced in responses |
| Protecting the shared ORS quota | Geocodes/routes/searches cached; per-IP throttle on our own endpoints |

### Pagination
| Seam | Handling |
|---|---|
| `batch-number` from 1, 500 per batch, **no total count** | Stop on a short batch, an empty batch, or a 404 past the end |
| A paginator that never ends | Hard cap on batches, which fails the run loudly ([test](tests/Feature/FuelFinderClientTest.php)) |
| Incremental prices via `effective-start-timestamp` (`YYYY-MM-DD HH:MM:SS`) | Window starts at the last *successful* run minus a 10-min overlap; full refresh nightly |

### Data mismatches ([FuelFinderNormaliser](app/Services/FuelFinder/FuelFinderNormaliser.php))
| Seam | Handling |
|---|---|
| ORS speaks GeoJSON `[lon, lat]`; Fuel Finder uses `latitude`/`longitude` fields | Converted at the API boundary; internally always `[lat, lon]` (the test asserts the order sent) |
| Some stations have **lat/lon swapped** or a **wrong-sign longitude** | Repaired only if the fix lands inside the UK; otherwise rejected. Every repair is counted and shown in the UI |
| Prices are zero-padded strings in pence (`"0120.0000"`), sometimes **in pounds** (`"1.429"`), sometimes null | Normalised to pence; implausible values (<50p or >350p) rejected |
| The same fuel has different codes: API `B7_STANDARD`, CSV `B7S`, old CMA feeds `B7`/`SDV` | All mapped to one enum ([FuelType](app/Enums/FuelType.php)) |
| Three timestamp formats (ISO without a zone; `Y-m-d H:i:s`; JS `Sat Mar 07 2026 15:34:42 GMT+0000 (…)`) | All parsed to UTC |
| `00:00–00:00` opening hours with `is_24_hours=false` means "not filled in", not "open all day" | Stored as unknown |
| ALL-CAPS names, postcodes without spaces, duplicate rows, null `permanent_closure` | Title-cased (acronyms kept), reformatted, de-duplicated, null treated as open |
| Prices and stations are synced separately (a price can arrive first) | No foreign key; joined on `node_id` |

### Freshness & edge cases
| Seam | Handling |
|---|---|
| Retailers have 30 min to report, and we sync every 15 min, so a price can be ~45 min behind the pump | Each price shows its age; >48h is flagged; >14 days is excluded (and counted) |
| An overlapping or late incremental window delivers an **older** price | Never overwrites a newer stored price |
| A failed sync | Recorded with its error; previously synced data is kept; the next run resumes from the last *good* run |
| Straight-line distance ≠ road distance (a station 200 m away may be across a dual carriageway) | The corridor only *shortlists* stations; real detours come from the ORS road matrix |
| The matrix returns `null` for stations it can't snap to a road | Counted as "unroutable" and left out; the search still succeeds |
| Holyhead is a ferry port | Routes avoid ferries, so "Holyhead → Dublin" is reported as no driving route |
| Same start and end; unknown place; journeys over 450 miles; empty database | Specific 422/503 errors explaining what to do |

---

## Verified vs. unverified

This area changed a lot in 2026; this is what was confirmed against official docs and what wasn't.

**Confirmed (official):** Fuel Finder live since 2 Feb 2026 under the Motor Fuel Price (Open Data) Regulations 2025; OAuth2 client credentials with GOV.UK One Login; prices published within 30 min of a change; live limit 100 req/min, 1 concurrent request; caching guidance (stations 1h, prices 15m, searches 5m); the CMA interim retailer JSON feeds **closed on 1 May 2026**. ORS free plan: 2,000 directions/day, 40/min; `api.openrouteservice.org` deprecated in favour of `api.heigit.org`.

**Unverified (community sources, configurable):** Fuel Finder endpoint paths and response shapes, the 1h token TTL, the 403 for non-UK IPs, and the 30/min figure. The exact path layout on `api.heigit.org` (set `ORS_BASE_URL` if it differs). The free-plan ORS **matrix** limits (we send at most 27 locations per request).

All tunables and assumptions live in one file: [config/fueldetour.php](config/fueldetour.php).

## Known limitations

- Opening hours are stored but not yet used ("is it open when I arrive?").
- Stations that disappear from the feed are not deleted; that would be unsafe after a partial sync.
- A longitude sign error east of ~1.8°W still lands inside the UK and can't be detected.
- Detour fuel is priced at the destination station; MPG is a single number, not speed-dependent.
- Map tiles come from the public OpenStreetMap tile server, which is fine for a demo but not for production traffic.

## Project layout

```
app/Services/FuelFinder/   FuelFinderClient (HTTP), FixtureFuelFinderSource, FuelFinderNormaliser, FuelDataSync
app/Services/Routing/      OpenRouteServiceClient, Place, RoutingException
app/Services/Journey/      JourneyPlanner (orchestration), CorridorSearch, DetourCalculator
app/Console/Commands/      fuel:sync, fuel:sync-stations, fuel:sync-prices [--full]
routes/console.php         schedule
config/fueldetour.php      every tunable and API assumption
tests/                     Unit (normaliser, geometry, cost maths), Feature (client, sync, end-to-end)
```
