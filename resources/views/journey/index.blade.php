<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fuel Detour</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --bg: #f6f7f9; --surface: #ffffff; --text: #1d2330; --muted: #5d6677; --border: #dde1e7;
            --accent: #0b6e4f; --accent-soft: #e3f3ec; --warn: #8a5a00; --warn-soft: #fff4dc;
            --error: #a4262c; --error-soft: #fde7e9; --info-soft: #e8f0fe;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14171c; --surface: #1d2129; --text: #e6e9ef; --muted: #9aa3b2; --border: #2f3542;
                --accent: #4cc38a; --accent-soft: #173327; --warn: #f0b445; --warn-soft: #3a2e14;
                --error: #ff8a8f; --error-soft: #3d1c1f; --info-soft: #1c2840;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; }
        .lede { margin: 0 0 20px; color: var(--muted); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        form .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        label { display: block; font-size: .8rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); font: inherit; }
        button { margin-top: 12px; padding: 9px 18px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font-weight: 600; cursor: pointer; }
        .banner { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
        .banner.warn { background: var(--warn-soft); color: var(--warn); }
        .banner.error { background: var(--error-soft); color: var(--error); }
        .banner.good { background: var(--accent-soft); color: var(--accent); font-weight: 600; font-size: 1.05rem; }
        .banner.info { background: var(--info-soft); }
        .banner code { font-size: .85em; }
        #map { height: 380px; border-radius: 8px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: 8px 6px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; white-space: nowrap; }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.best td { background: var(--accent-soft); }
        .tag { display: inline-block; font-size: .7rem; padding: 1px 6px; border-radius: 4px; margin: 1px 2px 0 0; background: var(--info-soft); color: var(--text); }
        .tag.warn { background: var(--warn-soft); color: var(--warn); }
        .muted { color: var(--muted); font-size: .85rem; }
        .pos { color: var(--accent); } .neg { color: var(--error); }
        ul.compact { margin: 4px 0 0; padding-left: 18px; }
    </style>
</head>
<body>
<main>
    <h1>Fuel Detour</h1>
    <p class="lede">Cheapest fuel along your route, and whether a detour is actually worth it once you count the extra miles.</p>

    <form class="card" method="get" action="{{ route('journey') }}">
        <div class="grid">
            <div><label for="from">From</label><input id="from" name="from" value="{{ $input['from'] ?? '' }}" placeholder="Holyhead" required></div>
            <div><label for="to">To</label><input id="to" name="to" value="{{ $input['to'] ?? '' }}" placeholder="Manchester" required></div>
            <div><label for="fuel">Fuel</label>
                <select id="fuel" name="fuel">
                    @foreach ($fuels as $fuel)
                        <option value="{{ $fuel->value }}" @selected(($input['fuel'] ?? 'E10') === $fuel->value)>{{ $fuel->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="mpg">Your MPG (UK)</label><input id="mpg" name="mpg" type="number" step="1" value="{{ $input['mpg'] ?? $defaults['default_mpg'] }}"></div>
            <div><label for="litres">Litres to buy</label><input id="litres" name="litres" type="number" step="1" value="{{ $input['litres'] ?? $defaults['default_litres'] }}"></div>
            <div><label for="corridor_miles">Search within (miles of route)</label><input id="corridor_miles" name="corridor_miles" type="number" step="0.5" value="{{ $input['corridor_miles'] ?? $defaults['default_corridor_miles'] }}"></div>
        </div>
        <button type="submit">Find fuel</button>
    </form>

    @if ($errors)
        <div class="banner error">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    @if ($error)
        <div class="banner error">
            <strong>{{ $error['source'] === 'app' ? 'Problem' : 'Problem with '.$error['source'] }}</strong>
            ({{ $error['code'] }}): {{ $error['message'] }}
        </div>
    @endif

    @if ($result)
        @foreach ($result['meta']['warnings'] as $warning)
            <div class="banner warn">{{ $warning }}</div>
        @endforeach

        <div class="banner {{ $result['recommendation']['verdict'] === 'no_stations' ? 'warn' : 'good' }}">
            {{ $result['recommendation']['message'] }}
        </div>

        <div class="card">
            <div class="muted" style="margin-bottom:8px">
                <strong>{{ $result['from']['label'] }}</strong> &rarr; <strong>{{ $result['to']['label'] }}</strong>
                &middot; {{ $result['route']['distance_miles'] }} miles, about {{ $result['route']['duration_minutes'] }} min
                &middot; {{ $result['fuel']['label'] }}
            </div>
            <div id="map" role="img" aria-label="Map of the route and stations"></div>
        </div>

        @if (count($result['stations']))
            <div class="card table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Station</th>
                        <th class="num">Price</th>
                        <th class="num">Miles in</th>
                        <th class="num">Detour</th>
                        <th class="num">Fuel</th>
                        <th class="num">Detour fuel</th>
                        <th class="num">Total</th>
                        <th class="num">vs nearest</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($result['stations'] as $s)
                        <tr class="{{ $s['node_id'] === $result['recommendation']['node_id'] ? 'best' : '' }}">
                            <td>
                                <strong>{{ $s['name'] }}</strong><br>
                                <span class="muted">{{ $s['postcode'] }}</span>
                                @if ($s['is_convenient'])<span class="tag">least detour</span>@endif
                                @if ($s['is_motorway_services'])<span class="tag">motorway services</span>@endif
                                @if ($s['is_supermarket'])<span class="tag">supermarket</span>@endif
                                @foreach ($s['data_corrections'] as $fix)
                                    <span class="tag warn" title="The feed data for this station was repaired">{{ str_replace('_', ' ', $fix) }}</span>
                                @endforeach
                            </td>
                            <td class="num">
                                {{ number_format($s['price_ppl'], 1) }}p<br>
                                <span class="muted {{ $s['price_is_old'] ? 'neg' : '' }}">
                                    @if ($s['price_age_hours'] === null) age unknown
                                    @elseif ($s['price_age_hours'] < 1) &lt;1h old
                                    @elseif ($s['price_age_hours'] < 48) {{ (int) $s['price_age_hours'] }}h old
                                    @else {{ (int) ($s['price_age_hours'] / 24) }}d old
                                    @endif
                                </span>
                            </td>
                            <td class="num">{{ $s['miles_into_journey'] }}</td>
                            <td class="num">
                                @if ($s['detour_miles'] < 0.1) none
                                @else +{{ number_format($s['detour_miles'], 1) }} mi<br><span class="muted">+{{ $s['detour_minutes'] }} min</span>
                                @endif
                            </td>
                            <td class="num">£{{ number_format($s['fill_cost_gbp'], 2) }}</td>
                            <td class="num">£{{ number_format($s['detour_fuel_cost_gbp'], 2) }}</td>
                            <td class="num"><strong>£{{ number_format($s['total_cost_gbp'], 2) }}</strong></td>
                            <td class="num {{ $s['saving_vs_convenient_gbp'] > 0 ? 'pos' : ($s['saving_vs_convenient_gbp'] < 0 ? 'neg' : '') }}">
                                {{ $s['saving_vs_convenient_gbp'] >= 0 ? '' : '-' }}£{{ number_format(abs($s['saving_vs_convenient_gbp']), 2) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="card muted">
            @php($ex = array_filter($result['excluded']))
            @if ($ex)
                <strong>Left out:</strong>
                @foreach ($ex as $reason => $count)
                    {{ $count }} {{ str_replace('_', ' ', $reason) }}@if (! $loop->last), @endif
                @endforeach
                <br>
            @endif
            <strong>Assumptions:</strong> {{ $result['assumptions']['mpg'] }} mpg, buying {{ $result['assumptions']['litres'] }} litres,
            stations within {{ $result['assumptions']['corridor_miles'] }} miles of the route,
            detour recommended if it saves at least £{{ number_format($result['assumptions']['min_worthwhile_saving_gbp'], 2) }}.
            <ul class="compact">
                @foreach ($result['assumptions']['notes'] as $note)<li>{{ $note }}</li>@endforeach
            </ul>
            <strong>Data:</strong> source {{ $result['meta']['data_source'] }},
            prices synced {{ $result['meta']['prices_synced_at'] ? \Carbon\Carbon::parse($result['meta']['prices_synced_at'])->diffForHumans() : 'never' }}
            @if ($result['meta']['from_cache']) &middot; cached result @endif
            @if ($result['meta']['ors_quota_remaining'] !== null) &middot; routing quota left: {{ $result['meta']['ors_quota_remaining'] }} @endif
            &middot; <a href="{{ url('/api/journey').'?'.http_build_query($input) }}">JSON</a>
            &middot; <a href="{{ url('/api/status') }}">sync status</a>
        </div>
    @else
        <div class="card muted">
            Try <a href="{{ route('journey', ['from' => 'Holyhead', 'to' => 'Manchester', 'fuel' => 'E10']) }}">Holyhead &rarr; Manchester</a>.
            Prices come from the UK Fuel Finder scheme (synced to a local copy); routes and detours from openrouteservice.
            &middot; <a href="{{ url('/api/status') }}">sync status</a>
        </div>
    @endif
</main>

@if ($result)
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            const result = @json($result);
            const map = L.map('map');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; OpenStreetMap contributors · Routing &copy; openrouteservice.org',
            }).addTo(map);

            const route = L.polyline(result.route.geometry, { weight: 5, opacity: .75 }).addTo(map);
            map.fitBounds(route.getBounds(), { padding: [20, 20] });

            const recommended = result.recommendation.node_id;
            result.stations.forEach(function (s) {
                const best = s.node_id === recommended;
                L.circleMarker([s.lat, s.lon], {
                    radius: best ? 10 : 7,
                    color: best ? '#0b6e4f' : (s.is_convenient ? '#1f5fbf' : '#555'),
                    fillOpacity: .85,
                }).addTo(map).bindPopup(
                    '<strong>' + s.name.replace(/</g, '&lt;') + '</strong><br>' + s.price_ppl.toFixed(1) + 'p/litre' +
                    '<br>Detour: ' + s.detour_miles.toFixed(1) + ' mi · Total £' + s.total_cost_gbp.toFixed(2)
                );
            });

            [['from', 'Start'], ['to', 'Destination']].forEach(function ([key, label]) {
                L.marker([result[key].lat, result[key].lon], { title: label }).addTo(map).bindPopup(label + ': ' + result[key].label.replace(/</g, '&lt;'));
            });
        })();
    </script>
@endif
</body>
</html>
