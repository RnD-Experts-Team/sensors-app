<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\YoSmartService;
use App\Models\SensorReport;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public Reports API – advanced, stateless endpoints.
 *
 * Features:
 *  • Optional API-key middleware (X-Api-Key header)
 *  • Daily / Weekly / Monthly aggregated sensor data
 *  • Per-device breakdown with min / max / avg
 *  • Live snapshot capture + historical queries
 *  • Pagination, date-range filtering, field selection
 *  • CORS-friendly JSON responses
 *
 * Base URL: /api/stores/{storeNumber}/reports
 */
class PublicReportController extends Controller
{
    // ── DB helpers ─────────────────────────────────────────────────────

    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private function dateFormat(string $column, string $pgFormat): string
    {
        if ($this->isPostgres()) {
            return "TO_CHAR({$column}, '{$pgFormat}')";
        }

        $mysqlFormat = str_replace(
            ['HH24', 'YYYY', 'MM', 'DD'],
            ['%H',   '%Y',   '%m', '%d'],
            $pgFormat
        );

        return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
    }

    private function boolLiteral(bool $value): string
    {
        if ($this->isPostgres()) {
            return $value ? 'true' : 'false';
        }

        return $value ? '1' : '0';
    }

    private static function parseAlarm(mixed $alarm): bool
    {
        if (is_bool($alarm)) {
            return $alarm;
        }

        if (is_array($alarm)) {
            foreach ($alarm as $key => $val) {
                if ($key === 'code') {
                    continue;
                }
                if ($val === true) {
                    return true;
                }
            }

            return false;
        }

        return (bool) $alarm;
    }

    // ── Live Snapshot ───────────────────────────────────────────────

    /**
     * POST /api/stores/{storeNumber}/reports/snapshot
     *
     * Collect live sensor data from YoSmart for every device in the store,
     * persist a SensorReport row per device, and return the snapshot.
     */
    public function snapshot(string $storeNumber): JsonResponse
    {
        $store = $this->resolveStore($storeNumber);
        if (!$store) {
            return $this->storeNotFound($storeNumber);
        }

        if (empty($store->yosmart_uaid) || empty($store->yosmart_secret)) {
            return response()->json([
                'success' => false,
                'error'   => 'This store has no YoSmart credentials configured.',
            ], 422);
        }

        $service = new YoSmartService(
            uaid:    $store->yosmart_uaid,
            secret:  $store->yosmart_secret,
            storeId: $store->id,
        );

        $snapshot = [];

        foreach ($store->devices as $device) {
            $method = $device->device_type . '.getState';

            $result = $service->callApi($method, [
                'targetDevice' => $device->device_id,
                'token'        => $device->device_token,
            ]);

            $success = $result && ($result['code'] ?? null) === '000000';
            $data    = $result['data'] ?? [];
            $state   = $data['state'] ?? [];

            $report = SensorReport::create([
                'store_id'         => $store->id,
                'store_device_id'  => $device->id,
                'device_id'        => $device->device_id,
                'device_type'      => $device->device_type,
                'device_name'      => $device->device_name,
                'online'           => $data['online'] ?? false,
                'temperature'      => $state['temperature'] ?? $state['temp'] ?? null,
                'temperature_unit' => $state['mode'] ?? null,
                'humidity'         => $state['humidity'] ?? null,
                'battery_level'    => $state['battery'] ?? null,
                'alarm'            => self::parseAlarm($state['alarm'] ?? false),
                'state'            => is_array($state) ? ($state['state'] ?? null) : $state,
                'raw_state'        => $success ? $data : ['error' => $result['desc'] ?? 'unknown'],
                'reported_at'      => isset($data['reportAt']) ? Carbon::parse($data['reportAt']) : null,
                'recorded_at'      => now(),
            ]);

            $snapshot[] = [
                'device_id'   => $device->device_id,
                'device_name' => $device->device_name,
                'device_type' => $device->device_type,
                'online'      => $report->online,
                'temperature' => AppSetting::convertTemp($report->temperature, $report->temperature_unit, AppSetting::temperatureUnit()),
                'temperature_unit' => AppSetting::temperatureUnit(),
                'humidity'    => $report->humidity,
                'battery'     => $report->battery_level,
                'alarm'       => $report->alarm,
                'state'       => $report->state,
                'reported_at' => $report->reported_at?->toIso8601String(),
                'success'     => $success,
            ];
        }

        return response()->json([
            'success'          => true,
            'store'            => $this->storePayload($store),
            'temperature_unit' => AppSetting::temperatureUnit(),
            'snapshot'         => $snapshot,
            'count'            => count($snapshot),
            'captured_at'      => now()->toIso8601String(),
        ]);
    }

    // ── Aggregated Report ──────────────────────────────────────────

    /**
     * GET /api/stores/{storeNumber}/reports?period=daily|weekly|monthly&date=YYYY-MM-DD&device_id=…&fields=…
     *
     * Returns time-series aggregations (avg/min/max temp & humidity),
     * per-device summaries, and overall statistics.
     */
    public function index(Request $request, string $storeNumber): JsonResponse
    {
        $store = $this->resolveStore($storeNumber);
        if (!$store) {
            return $this->storeNotFound($storeNumber);
        }

        $period   = $request->input('period', 'daily');
        $baseDate = Carbon::parse($request->input('date', now()->toDateString()));
        $deviceId = $request->input('device_id');
        $fields   = $request->input('fields'); // comma-separated

        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid period. Use daily, weekly, or monthly.',
            ], 422);
        }

        [$from, $to, $groupFormat] = $this->resolveDateRange($period, $baseDate);

        $query = SensorReport::forStore($store->id)
            ->between($from, $to)
            ->thSensors();

        if ($deviceId) {
            $query->forDevice($deviceId);
        }

        // Temperature unit conversion SQL expression
        $targetUnit = AppSetting::temperatureUnit();
        $t = AppSetting::tempSqlExpr($targetUnit);

        // Time-series
        $timeSeries = (clone $query)
            ->select([
                DB::raw($this->dateFormat('recorded_at', $groupFormat) . ' as time_bucket'),
                DB::raw("AVG({$t}) as avg_temp"),
                DB::raw("MIN({$t}) as min_temp"),
                DB::raw("MAX({$t}) as max_temp"),
                DB::raw('AVG(humidity) as avg_humidity'),
                DB::raw('MIN(humidity) as min_humidity'),
                DB::raw('MAX(humidity) as max_humidity'),
                DB::raw('COUNT(*) as reading_count'),
            ])
            ->groupBy('time_bucket')
            ->orderBy('time_bucket')
            ->get();

        // Per-device summary
        $deviceSummary = (clone $query)
            ->select([
                'device_id',
                'device_name',
                DB::raw("AVG({$t}) as avg_temp"),
                DB::raw("MIN({$t}) as min_temp"),
                DB::raw("MAX({$t}) as max_temp"),
                DB::raw('AVG(humidity) as avg_humidity'),
                DB::raw('MIN(humidity) as min_humidity'),
                DB::raw('MAX(humidity) as max_humidity'),
                DB::raw('COUNT(*) as reading_count'),
                DB::raw('SUM(CASE WHEN alarm = ' . $this->boolLiteral(true) . ' THEN 1 ELSE 0 END) as alarm_count'),
                DB::raw('SUM(CASE WHEN online = ' . $this->boolLiteral(false) . ' THEN 1 ELSE 0 END) as offline_count'),
            ])
            ->groupBy('device_id', 'device_name')
            ->get();

        // Overall
        $overall = (clone $query)
            ->select([
                DB::raw("AVG({$t}) as avg_temp"),
                DB::raw("MIN({$t}) as min_temp"),
                DB::raw("MAX({$t}) as max_temp"),
                DB::raw('AVG(humidity) as avg_humidity'),
                DB::raw('COUNT(*) as total_readings'),
                DB::raw('SUM(CASE WHEN alarm = ' . $this->boolLiteral(true) . ' THEN 1 ELSE 0 END) as total_alarms'),
                DB::raw('SUM(CASE WHEN online = ' . $this->boolLiteral(false) . ' THEN 1 ELSE 0 END) as total_offline'),
            ])
            ->first();

        $response = [
            'success'          => true,
            'store'            => $this->storePayload($store),
            'period'           => $period,
            'temperature_unit' => $targetUnit,
            'range'            => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
        ];

        // Selective field inclusion
        $sections = $fields ? explode(',', $fields) : ['overall', 'time_series', 'device_summary'];

        if (in_array('overall', $sections, true)) {
            $response['overall'] = $overall;
        }
        if (in_array('time_series', $sections, true)) {
            $response['time_series'] = $timeSeries;
        }
        if (in_array('device_summary', $sections, true)) {
            $response['device_summary'] = $deviceSummary;
        }

        $response['generated_at'] = now()->toIso8601String();

        return response()->json($response);
    }

    // ── Report History (paginated) ─────────────────────────────────

    /**
     * GET /api/stores/{storeNumber}/reports/history?from=&to=&per_page=&device_type=
     *
     * Raw report rows with cursor-based pagination.
     */
    public function history(Request $request, string $storeNumber): JsonResponse
    {
        $store = $this->resolveStore($storeNumber);
        if (!$store) {
            return $this->storeNotFound($storeNumber);
        }

        $query = SensorReport::forStore($store->id)
            ->orderByDesc('recorded_at');

        if ($request->filled('from')) {
            $query->where('recorded_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('recorded_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }
        if ($request->filled('device_type')) {
            $query->where('device_type', $request->input('device_type'));
        }
        if ($request->filled('device_id')) {
            $query->forDevice($request->input('device_id'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->paginate($perPage);

        $targetUnit = AppSetting::temperatureUnit();

        $paginated->getCollection()->transform(function ($report) use ($targetUnit) {
            $report->temperature = AppSetting::convertTemp($report->temperature, $report->temperature_unit, $targetUnit);
            $report->temperature_unit = $targetUnit;
            return $report;
        });

        return response()->json([
            'success'          => true,
            'store'            => $this->storePayload($store),
            'temperature_unit' => $targetUnit,
            'reports'          => $paginated,
        ]);
    }

    // ── Alerts Summary ─────────────────────────────────────────────

    /**
     * GET /api/stores/{storeNumber}/reports/alerts?from=&to=
     *
     * Returns a summary of alarm events and offline periods within a
     * date range (defaults to the last 24 hours).
     */
    public function alerts(Request $request, string $storeNumber): JsonResponse
    {
        $store = $this->resolveStore($storeNumber);
        if (!$store) {
            return $this->storeNotFound($storeNumber);
        }

        $from = Carbon::parse($request->input('from', now()->subDay()));
        $to   = Carbon::parse($request->input('to', now()));

        $alarms = SensorReport::forStore($store->id)
            ->between($from, $to)
            ->where('alarm', true)
            ->select([
                'device_id',
                'device_name',
                'device_type',
                'temperature',
                'temperature_unit',
                'humidity',
                'state',
                'recorded_at',
            ])
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        $targetUnit = AppSetting::temperatureUnit();

        $alarms->transform(function ($report) use ($targetUnit) {
            $report->temperature = AppSetting::convertTemp($report->temperature, $report->temperature_unit, $targetUnit);
            $report->temperature_unit = $targetUnit;
            return $report;
        });

        $offlineEvents = SensorReport::forStore($store->id)
            ->between($from, $to)
            ->where('online', false)
            ->select([
                'device_id',
                'device_name',
                'device_type',
                'recorded_at',
            ])
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success'          => true,
            'store'            => $this->storePayload($store),
            'temperature_unit' => $targetUnit,
            'range'   => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'alarms'         => $alarms,
            'alarm_count'    => $alarms->count(),
            'offline_events' => $offlineEvents,
            'offline_count'  => $offlineEvents->count(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function resolveStore(string $storeNumber): ?Store
    {
        return Store::where('store_number', $storeNumber)
            ->where('is_active', true)
            ->with('devices')
            ->first();
    }

    private function storeNotFound(string $storeNumber): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => "Store '{$storeNumber}' not found or inactive.",
        ], 404);
    }

    private function storePayload(Store $store): array
    {
        return [
            'store_number' => $store->store_number,
            'store_name'   => $store->store_name,
        ];
    }

    private function resolveDateRange(string $period, Carbon $baseDate): array
    {
        return match ($period) {
            'daily' => [
                $baseDate->copy()->startOfDay(),
                $baseDate->copy()->endOfDay(),
                'HH24:00',
            ],
            'weekly' => [
                $baseDate->copy()->startOfWeek(),
                $baseDate->copy()->endOfWeek(),
                'YYYY-MM-DD',
            ],
            'monthly' => [
                $baseDate->copy()->startOfMonth(),
                $baseDate->copy()->endOfMonth(),
                'YYYY-MM-DD',
            ],
        };
    }
}
