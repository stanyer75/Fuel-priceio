# Fuel Detour

**Enter a start and destination (e.g. Holyhead → Manchester). See the cheapest petrol or diesel near the route, and whether a detour is actually worth it once the extra miles are paid for.**

It pairs two public APIs that were never designed to work together:

| | Provides | Can't do on its own |
|---|---|---|
| **[CheckFuelPrices](https://checkfuelprices.co.uk/developers)** (free, no key; built on the government Fuel Finder feed) | Live UK forecourt prices **within a radius of a point** | Knows nothing about routes; max 20 results per query; no published rate limit |
| **[openrouteservice](https://openrouteservice.org)** (HeiGIT, free plan) | Geocoding, driving routes, a road distance matrix | Knows nothing about fuel prices |

The interesting part is the **seams** between them; they're listed [below](#the-seams), each linked to the code that handles it.

> **Why not the official Fuel Finder API directly?** The integration is built (OAuth, batched sync, normaliser; see `PRICE_SOURCE=synced`), but the government's developer signup form was broken during this project, so no credentials could be obtained. The app switched to CheckFuelPrices, a free intermediary over the same data. Switching back is a one-line `.env` change.

---

## Run it (about 5 minutes)

**Requirements:** PHP 8.3+ with the `pdo_sqlite` extension, and Composer. No Node, no database server, no Docker.

```bash
git clone <this repo> fuel-detour && cd fuel-detour
composer setup          # install, .env, SQLite database, migrations, load sample data
```

Then add a **free openrouteservice key** (sign up at <https://openrouteservice.org/dev/#/signup>, about 2 minutes) to `.env`. It's the only credential needed; live UK prices need no key:

```dotenv
ORS_API_KEY=your-key-here
```

```bash
php artisan serve       # http://localhost:8000
```

Open <http://localhost:8000/?from=Holyhead&to=Manchester&fuel=E10>. A first (uncached) search makes about 20–25 price requests and takes a few seconds; repeats are cached.

### Price sources (`PRICE_SOURCE` in `.env`)

| Value | What it uses | Needs |
|---|---|---|
| `checkfuelprices` (default) | Live UK prices, queried along each route | Nothing |
| `synced` + `FUEL_FINDER_DRIVER=fixture` | **Fictional "Demo Fuels" sample data** in [tests/Fixtures/fuel-finder](tests/Fixtures/fuel-finder), shaped like real Fuel Finder responses and containing every known defect. Fully offline; shows a "SAMPLE DATA" banner | `php artisan fuel:sync` (already run by `composer setup`) |
| `synced` + `FUEL_FINDER_DRIVER=live` | The official Fuel Finder API, bulk-synced to SQLite | Fuel Finder credentials (see below) |

For the official API, register at the [Fuel Finder developer portal](https://www.developer.fuel-finder.service.gov.uk/access-latest-fuelprices) (needs a GOV.UK One Login), then:

```dotenv
PRICE_SOURCE=synced
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
php artisan test             # 99 tests, ~2 seconds, fully offline
```

Every HTTP call is faked (`Http::preventStrayRequests()`), so tests never touch real APIs or quotas. The fakes behave like the real services as probed (distance-sorted and capped at 20; 302 for bad parameters; 406 without the geo+json `Accept` header). `phpunit.xml` pins the sources so a reviewer's `.env` can't leak in.

> **Windows notes** (both in the `php.ini` that `php --ini` reports as loaded, not the `-development`/`-production` templates):
> - `could not find driver` → uncomment `extension=pdo_sqlite`.
> - `cURL error 60: unable to get local issuer certificate` → PHP has no CA bundle. Set `curl.cainfo` and `openssl.cafile` to a CA bundle, e.g. Git for Windows' `C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt` or [curl's cacert.pem](https://curl.se/docs/caextract.html). Don't disable TLS verification.

---

## How it works

```
 User: "Holyhead → Manchester"
   │
   ├─▶ ORS geocode ×2 (cached 7d) ─▶ ORS route (cached 24h) ─▶ StationSource
   │                                                             │
   │        PRICE_SOURCE=checkfuelprices (default)               │
   │        cover the route with overlapping circles ◀───────────┤
   │        ─▶ CheckFuelPrices radius search per circle          │
   │           (cached 30 min; split circles that hit the        │
   │            20-result cap; de-duplicate)                     │
   │                                                             │
   │        PRICE_SOURCE=synced                                  │
   │        bbox query on local SQLite copy ◀────────────────────┘
   │        (filled by fuel:sync from Fuel Finder or sample data)
   │                         │
   │                         ▼  CorridorFilter: within N miles, open, price not stale
   └─◀ verdict + table + map ◀── DetourCalculator ◀── ORS matrix ×1
                     (whole result cached 5 min)
```

An uncached search costs at most 4 openrouteservice requests plus one CheckFuelPrices request per uncached circle (about 22 for Holyhead → Manchester). A repeat costs nothing.

**Covering a route with circles.** With a corridor of half-width *c*, a circle on the midpoint of a route window of length *L* covers the whole corridor for that window if its radius is *r = √((L/2)² + c²)*. We start with *r = 2c* (*L* ≈ 6.9 mi for *c* = 2 mi). When a circle returns the provider's maximum of 20 stations, it was truncated: results are distance-sorted, so cheaper stations further out are invisible. The window is then halved and searched again, down to 1 mile. See [CheckFuelPricesStationSource](app/Services/CheckFuelPrices/CheckFuelPricesStationSource.php).

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

### CheckFuelPrices (live prices) ([client](app/Services/CheckFuelPrices/CheckFuelPricesClient.php), [source](app/Services/CheckFuelPrices/CheckFuelPricesStationSource.php))
| Seam | Handling |
|---|---|
| A route is a **line**; the price API only answers "within R miles of a **point**" | The route is covered with overlapping circles sized to the corridor (maths above) |
| Results are **distance-sorted and capped at 20**: in a city, everything beyond ~2 miles is invisible (probed: 20 results all within 2.1 mi of central Manchester) | A full page counts as truncated, so the circle is split and searched again; unresolved truncation produces a user-visible "busy area" warning |
| **No published rate limit**, no rate-limit headers, Cloudflare in front | Our own limits: 250 ms between requests, max 60/min app-wide, honour 429 + `Retry-After`, stop the search on rate limiting (rather than hammer). Measured with `php artisan prices:probe` (results below) |
| Out-of-range parameters return a **302 redirect**, not a 400 | Redirects are disabled; any 3xx becomes a `request_rejected` error naming the parameters. Following it would have returned an HTML page with a 200 |
| **Undocumented response** (the provider's REST API is "coming soon"; this endpoint powers its widget) | Strict shape validation: `success`, `stations` list, required fields. A drift or a Cloudflare HTML page fails loudly as `unexpected_response` |
| **Two timestamp formats in one response**: `2026-11-18T23:05:15.000Z` and `18/11/2026 10:43:27` | Day-first parsed explicitly (a generic, US-order parser reads `05/11/2026` as 11 May, not 5 November) in **UK local time** (BST in summer), then converted to UTC |
| **What does the price timestamp mean?** Not documented. In Fuel Finder (the upstream feed) it's when the price last **changed**, not when it was last confirmed, and retailers must report changes within 30 min. So "5 days old" usually means "stable for 5 days", not "stale". An early version labelled these "5d old" in red, which suggested they were unreliable | Labelled **"unchanged 5 days"**, with a tooltip giving the exact time and this explanation. Only flagged ⚠ after 14 days unchanged; hidden after 60. The JSON says the same: `price_last_changed_at`, `price_unchanged_hours`, `price_is_doubtful` |
| Leftover prices from the closed CMA scheme (e.g. dated 29/04/2026) | Anything last changed before 1 May 2026 is always hidden as `price_from_closed_scheme`. Live: 10 of the 13 prices hidden on Holyhead → Manchester were these leftovers |
| Inconsistent names (`TESCO`, `Jet`, `ASDA LLANDUDNO SUPERSTORE`), no motorway/closure flags | Display-cased; missing flags default to "unknown/false" |
| Some circles fail | Partial results plus a warning saying how much of the route is missing; only a total failure is an error |
| Attribution required by the provider's terms | "Prices from checkfuelprices.co.uk" shown with every result and returned in the JSON |
| Overlapping circles return the same station | De-duplicated by provider id |

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
| ORS moved host: on `api.heigit.org` routing lives under `/openrouteservice/v2/…` and geocoding under `/pelias/v1/search` (the old host used `/v2/…` and `/geocode/search`) | Prefix and path are config (`ORS_ROUTING_PREFIX`, `ORS_GEOCODE_PATH`), found by probing the live API |
| ORS `/directions/…/geojson` returns **406** if you send `Accept: application/json` | That call sends `Accept: application/geo+json`; the test fake enforces this too |
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
| Retailers have 30 min to report, and prices are cached (30 min live; 15 min sync for the synced source), so a price can be up to about an hour behind the pump | Each price shows how long it has been unchanged; >14 days is flagged, >60 days or pre-May-2026 is hidden (and counted). All thresholds are in config |
| An overlapping or late incremental window delivers an **older** price | Never overwrites a newer stored price |
| A failed sync | Recorded with its error; previously synced data is kept; the next run resumes from the last *good* run |
| Straight-line distance ≠ road distance (a station 200 m away may be across a dual carriageway) | The corridor only *shortlists* stations; real detours come from the ORS road matrix. In the first live run, a station 0.14 mi from the A55 was an **8.2 mi** detour |
| The matrix returns `null` for stations it can't snap to a road | Counted as "unroutable" and left out; the search still succeeds |
| **Geocoding picks a confident wrong answer.** Restricted to the UK, "Dublin" matched a *venue* "Dublin - Liverpool" in Bootle with confidence 1.0 and `match_type: exact`. Meanwhile the *correct* Belfast and Brighton come back as `fallback`, so confidence and match type are both unreliable | Trust the place **name** instead: it must equal what was typed ("Dublin Road" ≠ "Dublin"); alternative names count ("Londonderry / Derry"). Real places are preferred over venues ("Manchester Airport" still works). Same-name places far apart ("Newport") return `ambiguous_place` with suggestions; "Newport, Wales" narrows it by label. All found in live testing ([geocode](app/Services/Routing/OpenRouteServiceClient.php)) |
| Holyhead is a ferry port | Routes avoid ferries; non-UK destinations are refused at geocoding |
| Same start and end; unknown place; journeys over 450 miles; empty database | Specific 422/503 errors explaining what to do |

---

## Verified vs. unverified

This area changed a lot in 2026; this is what was confirmed against official docs and what wasn't.

**Confirmed (official):** Fuel Finder live since 2 Feb 2026 under the Motor Fuel Price (Open Data) Regulations 2025; OAuth2 client credentials with GOV.UK One Login; prices published within 30 min of a change; live limit 100 req/min, 1 concurrent request; caching guidance (stations 1h, prices 15m, searches 5m); the CMA interim retailer JSON feeds **closed on 1 May 2026**. ORS free plan: 2,000 directions/day, 40/min; `api.openrouteservice.org` deprecated in favour of `api.heigit.org`.

**Verified by live calls (Nov 2026):** the `api.heigit.org` path layout above; geocode/directions/matrix response shapes; matrix quota **500/day** (`X-Ratelimit-Limit` header); the geo+json `Accept` requirement.

**CheckFuelPrices, verified by probe (19 Nov 2026):** no key; `radius` 1–50 mi (decimals OK); `limit` max 20; results distance-sorted; `fuel` codes `E10`, `E5`, `B7`, `SDV`; invalid parameters return 302. **Rate limit:** 60 uncached requests at 4/s (straight after a 22-request search) all returned 200 (median 133 ms, p95 167 ms), with no rate-limit headers. The real ceiling was deliberately not pushed further against a free service. Our own cap (60/min) stays well inside what was observed.

**Unverified (community sources, configurable):** Fuel Finder endpoint paths and response shapes, the 1h token TTL, the 403 for non-UK IPs, and the 30/min figure. The ORS matrix per-request location limit (we send at most 27 locations). CheckFuelPrices' actual rate limit and update frequency (it claims about 30 minutes).

All tunables and assumptions live in one file: [config/fueldetour.php](config/fueldetour.php).

## Known limitations & risks

- **CheckFuelPrices is a small third-party service**, and the endpoint used is undocumented (it powers their widget). It could change or disappear without notice; there's no uptime guarantee. Mitigations: strict validation, partial-failure handling, and the `synced` sources as a fallback.
- In very dense areas (central Manchester), even 1-mile circles can hit the 20-result cap; the UI warns when this happens.
- **Capacity:** measured live, an uncached search costs 7–30 CheckFuelPrices requests (Newport → Bristol 7, Holyhead → Manchester 22, London → Brighton 30). With our self-imposed 60/minute cap, that's roughly 2–6 *new* searches per minute across the whole app; cached repeats are free. When the cap is reached, a search in progress returns partial results with a warning, and a new search gets a 503 saying how long to wait. Raising `CFP_MAX_PER_MINUTE` is a one-line change, but it should be agreed with the provider.
- Only the 25 most useful corridor stations get exact road detours (to protect the ORS matrix quota of 500/day); the rest are counted as "not checked".
- Opening hours are stored (synced source) but not yet used ("is it open when I arrive?").
- Stations that disappear from the feed are not deleted; that would be unsafe after a partial sync.
- A longitude sign error east of ~1.8°W still lands inside the UK and can't be detected.
- Detour fuel is priced at the destination station; MPG is a single number, not speed-dependent.
- Map tiles come from the public OpenStreetMap tile server, which is fine for a demo but not for production traffic.

## Project layout

```
app/Services/CheckFuelPrices/  Client (HTTP + politeness), StationSource (route circles), Normaliser
app/Services/Prices/       StationSource interface, CorridorFilter (shared rules), SyncedStationSource
app/Services/FuelFinder/   FuelFinderClient (HTTP), FixtureFuelFinderSource, FuelFinderNormaliser, FuelDataSync
app/Services/Routing/      OpenRouteServiceClient, Place, RoutingException
app/Services/Journey/      JourneyPlanner (orchestration), DetourCalculator
app/Console/Commands/      fuel:sync, fuel:sync-stations, fuel:sync-prices [--full], prices:probe
routes/console.php         schedule
config/fueldetour.php      every tunable and API assumption
tests/                     Unit (normaliser, geometry, cost maths), Feature (client, sync, end-to-end)
```
