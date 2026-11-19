<?php

namespace App\Http\Controllers;

use App\Enums\FuelType;
use App\Exceptions\UpstreamException;
use App\Models\Station;
use App\Models\StationPrice;
use App\Models\SyncRun;
use App\Services\Journey\JourneyPlanner;
use App\Services\Journey\JourneyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JourneyController extends Controller
{
    public function __construct(private readonly JourneyPlanner $planner) {}

    /** HTML page. Uses GET so every search is a shareable URL. */
    public function show(Request $request): View
    {
        $view = ['input' => $request->query(), 'result' => null, 'error' => null, 'errors' => null];

        if ($request->filled('from') || $request->filled('to')) {
            $validator = Validator::make($request->query(), self::rules());

            if ($validator->fails()) {
                $view['errors'] = $validator->errors();
            } else {
                try {
                    $view['result'] = $this->planner->plan(JourneyQuery::fromValidated($validator->validated()));
                } catch (UpstreamException $e) {
                    $view['error'] = $e->toArray();
                }
            }
        }

        return view('journey.index', $view + ['fuels' => FuelType::searchable(), 'defaults' => config('fueldetour.journey')]);
    }

    /** JSON API: GET /api/journey?from=Holyhead&to=Manchester&fuel=E10 */
    public function api(Request $request): JsonResponse
    {
        // Invalid input -> 422 JSON (bootstrap/app.php renders api/* as JSON).
        $validated = Validator::make($request->query(), self::rules())->validate();

        try {
            return response()->json($this->planner->plan(JourneyQuery::fromValidated($validated)));
        } catch (UpstreamException $e) {
            return response()->json(['error' => $e->toArray()], $e->status);
        }
    }

    /** GET /api/status - is our copy of the Fuel Finder data healthy? */
    public function status(): JsonResponse
    {
        $runs = SyncRun::latest('id')->limit(10)->get()->map(fn (SyncRun $r) => [
            'id' => $r->id,
            'type' => $r->type,
            'source' => $r->source,
            'status' => $r->status,
            'changed_since' => $r->changed_since?->toIso8601String(),
            'requests_made' => $r->requests_made,
            'batches' => $r->batches,
            'records_received' => $r->records_received,
            'rows_saved' => $r->rows_saved,
            'rows_rejected' => $r->rows_rejected,
            'rejections' => $r->rejections,
            'corrections' => $r->corrections,
            'error' => $r->error,
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ]);

        return response()->json([
            'data_source' => config('fueldetour.fuel_finder.driver'),
            'stations' => Station::count(),
            'prices' => StationPrice::count(),
            'last_successful_price_sync' => SyncRun::lastSuccessfulPrices()?->finished_at?->toIso8601String(),
            'last_successful_station_sync' => SyncRun::lastSuccessfulStations()?->finished_at?->toIso8601String(),
            'recent_runs' => $runs,
        ]);
    }

    public static function rules(): array
    {
        return [
            'from' => ['required', 'string', 'min:2', 'max:100'],
            'to' => ['required', 'string', 'min:2', 'max:100'],
            'fuel' => ['nullable', Rule::in(array_map(fn ($f) => $f->value, FuelType::searchable()))],
            'mpg' => ['nullable', 'numeric', 'between:10,150'],
            'litres' => ['nullable', 'numeric', 'between:5,150'],
            'corridor_miles' => ['nullable', 'numeric', 'between:0.5,10'],
        ];
    }
}
