<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * CheckFuelPrices publishes no rate limit, so measure it - politely.
 *
 * Sends sequential, uncached requests at a fixed pace and reports status
 * codes, latency and any rate-limit headers. Stops at the FIRST 429 (or 3
 * other errors) and refuses to send more than 120 requests per run.
 */
class ProbePrices extends Command
{
    protected $signature = 'prices:probe
        {--requests=30 : Number of requests to send (max 120)}
        {--interval=250 : Milliseconds between requests}
        {--lat=53.4794} {--lng=-2.2453} {--radius=3}';

    protected $description = 'Measure CheckFuelPrices\' unpublished rate limit (stops at first 429)';

    public function handle(): int
    {
        $config = config('fueldetour.checkfuelprices');
        $total = min(120, max(1, (int) $this->option('requests')));
        $interval = max(100, (int) $this->option('interval'));

        $this->info(sprintf('Probing %s%s: %d requests, one every %dms (~%d/min). Stops at first 429.',
            $config['base_url'], $config['stations_path'], $total, $interval, (int) (60000 / $interval)));

        $statuses = [];
        $latencies = [];
        $errors = 0;
        $headersSeen = [];

        for ($i = 1; $i <= $total; $i++) {
            $began = microtime(true);
            try {
                $response = Http::baseUrl($config['base_url'])
                    ->withoutRedirecting()
                    ->withUserAgent($config['user_agent'])
                    ->acceptJson()
                    ->timeout((int) $config['timeout_seconds'])
                    ->get($config['stations_path'], [
                        // Nudge the point each time so no cache (theirs or ours) can answer.
                        'lat' => round((float) $this->option('lat') + $i * 0.0001, 5),
                        'lng' => (float) $this->option('lng'),
                        'fuel' => 'E10',
                        'radius' => (float) $this->option('radius'),
                        'limit' => 20,
                    ]);
            } catch (ConnectionException $e) {
                $this->warn("#{$i} connection error: {$e->getMessage()}");
                if (++$errors >= 3) {
                    break;
                }

                continue;
            }

            $ms = (int) round((microtime(true) - $began) * 1000);
            $status = $response->status();
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $latencies[] = $ms;

            foreach ($response->headers() as $name => $values) {
                if (preg_match('/rate|retry|limit|throttl|cf-cache|x-cache|age/i', $name)) {
                    $headersSeen[strtolower($name)] = $values[0];
                }
            }

            $this->line(sprintf('#%-3d HTTP %d  %4dms', $i, $status, $ms));

            if ($status === 429) {
                $this->error("Rate limited after {$i} requests. Retry-After: ".($response->header('Retry-After') ?: 'not sent'));
                break;
            }
            if (! $response->successful() && ++$errors >= 3) {
                $this->error('Stopping after 3 non-success responses.');
                break;
            }

            if ($i < $total) {
                Sleep::for($interval)->milliseconds();
            }
        }

        sort($latencies);
        $this->newLine();
        $this->table(['Sent', 'Statuses', 'Median ms', 'p95 ms', 'Rate/limit headers seen'], [[
            array_sum($statuses),
            collect($statuses)->map(fn ($n, $s) => "{$s}x{$n}")->implode(', '),
            $latencies ? $latencies[intdiv(count($latencies), 2)] : '-',
            $latencies ? $latencies[(int) floor(0.95 * (count($latencies) - 1))] : '-',
            $headersSeen ? collect($headersSeen)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ') : 'none',
        ]]);

        return isset($statuses[429]) ? self::FAILURE : self::SUCCESS;
    }
}
