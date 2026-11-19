# Fuel Detour

*Repository: [github.com/stanyer75/Fuel-priceio](https://github.com/stanyer75/Fuel-priceio)*

**You fill up, drive two minutes down the road, and see fuel 8p a litre cheaper.**
Fuel Detour takes a start and a destination anywhere in the UK (e.g. *Holyhead → Manchester*), finds the cheapest petrol or diesel near the route, and tells you whether a detour is actually worth it once the extra miles are paid for.

It combines two public APIs that were never designed to work together:

| API | Gives us | Key needed? |
|---|---|---|
| **[openrouteservice](https://openrouteservice.org)** (HeiGIT) | Place search, driving routes, road distances | **Yes**, free |
| **[CheckFuelPrices](https://checkfuelprices.co.uk/developers)** | Live UK forecourt prices (built on the government's Fuel Finder data) | **No** |

The interesting part is the **seams** between them (auth, rate limits, pagination, data mismatches, edge cases). They're summarised [below](#how-it-works) and documented in full in [docs/INTEGRATION-NOTES.md](docs/INTEGRATION-NOTES.md).

---

## Quick start (about 5 minutes)

### 1. Prerequisites

- **PHP 8.3 or newer** with the `pdo_sqlite` extension
- **Composer** 2
- **Git**

No Node, no database server, no Docker.

> **Don't have PHP and Composer?** Laravel's official installer, [php.new](https://php.new), installs both with one command:
>
> | OS | Command |
> |---|---|
> | macOS | `/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"` |
> | Windows (PowerShell) | `Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))` |
> | Linux | `/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"` |
>
> Open a new terminal afterwards.

### 2. Get the code and install

```bash
git clone https://github.com/stanyer75/Fuel-priceio.git
cd Fuel-priceio
composer setup
```

`composer setup` does everything:
1. checks your PHP
2. installs dependencies
3. creates `.env` and an app key
4. creates the SQLite database and runs the migrations
5. loads sample data

If anything is missing from your PHP, it **stops before installing anything and tells you exactly what to fix**. On Windows it names the `php.ini` line to change. (You can run the check on its own: `php scripts/check-requirements.php`.)

### 3. Get your API keys

#### Maps and routing: openrouteservice (required, free, about 2 minutes)

openrouteservice handles finding places, planning the route, and measuring detours. It's run by [HeiGIT](https://heigit.org) at Heidelberg University and is free for this kind of use.

1. Go to **[openrouteservice.org](https://openrouteservice.org)**, find the **Our API Services** section, and click **Public API**.
2. **Sign up** for a free account and **confirm your email address** (check your spam folder).
3. Once you're signed in, your free **API key** is shown on your dashboard; copy it. (You can find it again later at [account.heigit.org](https://account.heigit.org).)
4. Open `.env` in the project folder and paste it in:
   ```dotenv
   ORS_API_KEY=paste-your-key-here
   ```

The free plan allows **2,000 routes and 500 distance matrices a day** (40 requests a minute), plenty for reviewing. The app caches results, so repeating a search costs nothing.

#### Petrol station prices: CheckFuelPrices (no key needed)

Live UK prices come from **[CheckFuelPrices](https://checkfuelprices.co.uk/developers)**, a free service built on the government's statutory Fuel Finder data. **There's nothing to sign up for.** Their terms ask for attribution, which the app shows with every result ("Prices from checkfuelprices.co.uk"), and for sensible request volumes, which the app enforces itself (see [Configuration](#configuration)).

#### Optional: the official UK government Fuel Finder API

You don't need this to run the app. It's an alternative price source, already fully integrated.

1. Go to the **[Fuel Finder developer portal](https://www.developer.fuel-finder.service.gov.uk/access-latest-fuelprices)** and sign in with a **GOV.UK One Login** (create one if needed).
2. Create an **Information Recipient** application to receive a **client ID** and **client secret**.
3. In `.env`:
   ```dotenv
   PRICE_SOURCE=synced
   FUEL_FINDER_DRIVER=live
   FUEL_FINDER_CLIENT_ID=your-client-id
   FUEL_FINDER_CLIENT_SECRET=your-client-secret
   ```
4. Download the data: `php artisan fuel:sync` (about 35 requests, 1–2 minutes). To keep it fresh, run `php artisan schedule:work`.

> **Note:** during development the government's signup form was broken, so this route couldn't be tested against the live API. That's why CheckFuelPrices is the default. The Fuel Finder integration (OAuth, batched sync, data cleaning) is complete and fully tested against faked API responses.

### 4. Run it

```bash
php artisan serve
```

Open **<http://localhost:8000/?from=Holyhead&to=Manchester&fuel=E10>**.

A first search takes a few seconds (it queries prices all along the route). Repeating it is instant.

Some routes to try:

| Route | Link |
|---|---|
| Holyhead → Manchester, petrol | <http://localhost:8000/?from=Holyhead&to=Manchester&fuel=E10> |
| London → Brighton, diesel | <http://localhost:8000/?from=London&to=Brighton&fuel=B7_STANDARD> |
| Edinburgh → Glasgow, petrol | <http://localhost:8000/?from=Edinburgh&to=Glasgow&fuel=E10> |
| Belfast → Derry, petrol | <http://localhost:8000/?from=Belfast&to=Derry&fuel=E10> |
| "Newport" (ambiguous on purpose) | <http://localhost:8000/?from=Newport&to=Bristol> |

---

## Using it

### Web page

Enter a start, a destination, your fuel, your car's **MPG (UK gallons)**, how many **litres** you'll buy, and how far from the route to look. You get:

- **a verdict**: "Worth the detour…" or "Not worth a detour…", with the saving in pounds
- **a table** of stations ranked by *total* cost (fuel bought + fuel burned on the detour), each with its price, how long the price has been unchanged, the extra miles and minutes, and the saving compared with the most convenient station
- **a map** of the route and stations

Every search is a plain URL, so it can be bookmarked and shared.

### JSON API

```bash
curl "http://localhost:8000/api/journey?from=Holyhead&to=Manchester&fuel=E10&mpg=45&litres=40"
```

| Parameter | Required | Values |
|---|---|---|
| `from`, `to` | yes | UK town, city, postcode or address. Add a county or country for ambiguous names (`Newport, Wales`) |
| `fuel` | no | `E10` (default), `E5`, `B7_STANDARD`, `B7_PREMIUM` |
| `mpg` | no | 10–150 (default 45) |
| `litres` | no | 5–150 (default 40) |
| `corridor_miles` | no | 0.5–10 (default 2) |

Abridged response:

```json
{
  "route": { "distance_miles": 118.9, "duration_minutes": 134 },
  "recommendation": {
    "verdict": "not_worth_it",
    "message": "Not worth a detour: the cheapest option (Texaco) only saves £0.26 once extra fuel is counted. Use Esso at 149.9p/litre."
  },
  "stations": [
    { "name": "Texaco", "price_ppl": 148.9, "price_unchanged_hours": 48.4,
      "detour_miles": 0.95, "detour_minutes": 4, "total_cost_gbp": 59.70, "saving_vs_convenient_gbp": 0.26 }
  ],
  "excluded": { "price_from_closed_scheme": 10, "not_checked_matrix_limit": 61 },
  "meta": { "data_source": "checkfuelprices", "attribution": { "text": "Prices from checkfuelprices.co.uk" },
            "warnings": [], "coverage": { "circles_searched": 22 }, "from_cache": false }
}
```

Errors from either API come back in one consistent shape, with a meaningful status (422 for bad input, 503 when a service is unavailable):

```json
{ "error": { "source": "openrouteservice", "code": "quota_exhausted", "message": "The daily openrouteservice quota for this API key is used up…" } }
```

### Other endpoints and commands

| | |
|---|---|
| `GET /api/status` | Which price source is active, plus a history of data syncs |
| `php artisan prices:probe` | Measures CheckFuelPrices' (unpublished) rate limit politely; stops at the first 429 |
| `php artisan fuel:sync` | Loads stations and prices for the `synced` source (sample data or official API) |

---

## Running the tests

```bash
php artisan test
```

**99 tests, about 2 seconds, no API keys and no internet needed.** Every HTTP call is faked, and the fakes behave like the real services as observed in live testing (results capped at 20, redirects for bad parameters, rejection without the right `Accept` header, and so on).

---

## Configuration

Everything lives in `.env` (documented in [`.env.example`](.env.example)). All tunables and API assumptions are in one file: [`config/fueldetour.php`](config/fueldetour.php).

| Setting | Default | Purpose |
|---|---|---|
| `ORS_API_KEY` | none | **Required.** Your openrouteservice key |
| `PRICE_SOURCE` | `checkfuelprices` | `checkfuelprices` = live prices; `synced` = local copy (sample data or official API) |
| `FUEL_FINDER_DRIVER` | `fixture` | *Not in `.env.example`; only needed for the [official API](#optional-the-official-uk-government-fuel-finder-api).* With `PRICE_SOURCE=synced`: `fixture` = **fictional sample data** (offline, shows a "SAMPLE DATA" banner); `live` = official API |
| `CFP_MAX_PER_MINUTE` | `60` | Our own cap on CheckFuelPrices requests (it publishes no limit) |
| `CFP_MIN_INTERVAL_MS` | `250` | Gap between CheckFuelPrices requests |

---

## Troubleshooting

| You see | Fix |
|---|---|
| `could not find driver` or the setup check says `pdo_sqlite` is missing | Enable `extension=pdo_sqlite` in the `php.ini` that `php --ini` reports as **loaded**. On Windows, don't edit the `-development`/`-production` templates; save with admin rights (VS Code offers **Retry as Admin**). |
| `cURL error 60: unable to get local issuer certificate` | PHP has no list of trusted certificates. In the same `php.ini`, set `curl.cainfo` and `openssl.cafile` to a CA bundle, e.g. `"C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt"` or [curl's cacert.pem](https://curl.se/docs/caextract.html). **Don't disable TLS verification.** |
| `missing_api_key` | Add `ORS_API_KEY` to `.env` ([how](#maps-and-routing-openrouteservice-required-free-about-2-minutes)). |
| `invalid_api_key` | Check the key was copied completely, with no quotes or spaces. |
| `quota_exhausted` | The free daily openrouteservice quota is used up; it resets 24 hours after your first request. |
| `local_rate_cap` | The app's own politeness limit on price requests was reached. Wait the number of seconds shown. A first search uses 7–30 requests, so this allows a few new searches a minute (repeats are free). |
| `ambiguous_place` | Several UK places share that name. Add a county or country (`Newport, Wales`) or use a postcode. |
| `place_not_found` | Only UK places are supported (e.g. `Dublin` won't work). Check the spelling or use a postcode. |
| Port 8000 in use | `php artisan serve --port=8080` |

---

## How it works

```
 "Holyhead → Manchester"
     │
     ├─▶ openrouteservice: find both places ─▶ plan the route
     │
     ├─▶ CheckFuelPrices: cover the route with overlapping search circles
     │     (the API only answers "stations within R miles of a point", max 20 results)
     │
     ├─▶ keep stations within N miles of the route, open, with a believable price
     │
     ├─▶ openrouteservice: real road detour to each shortlisted station (one matrix call)
     │
     └─▶ total cost = fuel bought + fuel burned on the detour  ─▶  verdict
```

**Is the detour worth it?** Each station's *total cost* is compared with the most convenient station's (the one needing the least detour). A detour is recommended only if it saves at least £0.50 (configurable). Extra minutes are shown, but time isn't given a cash value.

### Highlights: seams between the two APIs

A few of the problems found and handled (the full list, with the code for each, is in **[docs/INTEGRATION-NOTES.md](docs/INTEGRATION-NOTES.md)**):

- **A line versus a point.** Routes are lines; the price API only searches around a point and returns at most 20 stations, sorted by distance. The route is covered with overlapping circles sized so none of the corridor is missed. Any circle that hits the cap is split and searched again, because in a city the 20 nearest stations can all be within 2 miles.
- **Straight-line distance can mislead.** A station 0.14 miles from the A55 turned out to be an 8.2-mile detour (other side of a dual carriageway). Distances only shortlist stations; real road detours come from the routing matrix.
- **No published rate limit.** The app sets its own limits, measured them with a probe, backs off on 429s, and returns partial results instead of hammering the service.
- **Confident wrong answers.** Restricted to the UK, the geocoder matched "Dublin" to a ferry-terminal *venue* in Bootle with full confidence. Places are now matched by name, and ambiguous names ("Newport") prompt the user to choose.
- **Data mismatches.** Two timestamp formats in one response (`2026-11-18T23:05:15.000Z` and UK day-first `18/11/2026 10:43:27`); prices in pence *or* pounds; swapped coordinates; the same fuel under different codes in different feeds.
- **What "old" means.** A price timestamp is when it last *changed*, so "5 days old" usually means "stable", not "stale". Prices are labelled "unchanged 5 days", and abandoned prices from a closed government scheme are filtered out.
- **Silent traps.** Invalid parameters are answered with a redirect rather than an error; following it would have returned a web page with a 200 "success". One routing endpoint refuses requests without a specific `Accept` header.

## Project layout

```
app/Services/CheckFuelPrices/  live prices: HTTP client (rate limiting), route coverage, data cleaning
app/Services/Routing/          openrouteservice: geocoding, routes, distance matrix
app/Services/Journey/          the search itself and the detour-cost maths
app/Services/Prices/           shared station rules; the synced (database) price source
app/Services/FuelFinder/       official Fuel Finder API: OAuth client, batched sync, data cleaning
config/fueldetour.php          every setting and API assumption, in one place
scripts/check-requirements.php the setup pre-flight check
tests/                         99 tests, all offline
docs/INTEGRATION-NOTES.md      the detailed engineering notes
```

## Tested on

Set up from scratch by following this README on clean systems:
- **Debian 13 with PHP 8.3** (the minimum)
- **Alpine 3.24 with PHP 8.5**
- **Windows 11 with PHP 8.5**, including a fresh-install `php.ini` and a folder path containing spaces

Setup takes under 10 seconds after downloads, and the test suite passes with networking switched off.

## Data and credits

- Fuel prices from **[checkfuelprices.co.uk](https://checkfuelprices.co.uk)**, built on UK Government [Fuel Finder](https://www.gov.uk/government/collections/fuel-finder) data.
- Routing and geocoding by **[openrouteservice](https://openrouteservice.org)** (HeiGIT). Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors.
- The sample data in `tests/Fixtures/` is **fictional** ("Demo Fuels"); it isn't real prices.
