<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\SensorReport;
use App\Models\Store;
use App\Models\StoreDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\YoSmartService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ReportController extends Controller
{
    // ── DB helpers ─────────────────────────────────────────────────────

    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Return a DB::raw expression for date formatting that works on both
     * PostgreSQL and MySQL.
     */
    private function dateFormat(string $column, string $pgFormat): string
    {
        if ($this->isPostgres()) {
            return "TO_CHAR({$column}, '{$pgFormat}')";
        }

        // Convert PostgreSQL format tokens to MySQL equivalents
        $mysqlFormat = str_replace(
            ['HH24', 'YYYY', 'MM', 'DD'],
            ['%H',   '%Y',   '%m', '%d'],
            $pgFormat
        );

        return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
    }

    /**
     * Return the correct boolean literal for the current driver.
     */
    private function boolLiteral(bool $value): string
    {
        if ($this->isPostgres()) {
            return $value ? 'true' : 'false';
        }

        return $value ? '1' : '0';
    }

    /**
     * Extract a boolean alarm value from the YoSmart state.
     *
     * The API may return alarm as:
     *  - a boolean
     *  - an object {"lowBattery":false,"highTemp":true,"code":4,...}
     */
    private static function parseAlarm(mixed $alarm): bool
    {
        if (is_bool($alarm)) {
            return $alarm;
        }

        if (is_array($alarm)) {
            // If any flag (excluding the numeric 'code' key) is true → alarm active
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

    // ── Dashboard page ────────────────────────────────────────────────

    /**
     * Render the Reports Inertia page.
     * Passes stores list so the frontend can filter by store.
     */
    public function index(): InertiaResponse
    {
        $stores = Store::where('is_active', true)
            ->withCount('sensors')
            ->get(['id', 'store_number', 'store_name']);

        return Inertia::render('reports', [
            'stores' => $stores,
        ]);
    }

    // ── API endpoints (auth'd, consumed by the React page) ───────────

    /**
     * Snapshot: collect live sensor data from YoSmart for a store and
     * persist a SensorReport row for each device.
     *
     * POST /api/reports/snapshot
     */
    public function snapshot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
        ]);

        $store = Store::with('devices')->findOrFail($validated['store_id']);

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

        $reports = [];

        foreach ($store->devices as $device) {
            /** @var StoreDevice $device */
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
                'temperature_unit' => 'c',
                'humidity'         => $state['humidity'] ?? null,
                'battery_level'    => $state['battery'] ?? null,
                'alarm'            => self::parseAlarm($state['alarm'] ?? false),
                'state'            => is_array($state) ? ($state['state'] ?? null) : $state,
                'raw_state'        => $success ? $data : ['error' => $result['desc'] ?? 'unknown'],
                'reported_at'      => isset($data['reportAt']) ? Carbon::parse($data['reportAt']) : null,
                'recorded_at'      => now(),
            ]);

            $reports[] = $report;
        }

        $targetUnit = AppSetting::temperatureUnit();

        $converted = collect($reports)->map(function ($r) use ($targetUnit) {
            $r->temperature = AppSetting::convertTemp($r->temperature, $r->temperature_unit, $targetUnit);
            $r->temperature_unit = $targetUnit;
            return $r;
        });

        return response()->json([
            'success'          => true,
            'count'            => count($reports),
            'temperature_unit' => $targetUnit,
            'snapshot'         => $converted,
        ]);
    }

    /**
     * Fetch aggregated report data for a given period.
     *
     * GET /api/reports/data?store_id=&period=daily|weekly|monthly&date=YYYY-MM-DD
     */
    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id'  => 'required|integer|exists:stores,id',
            'period'    => 'required|in:daily,weekly,monthly',
            'date'      => 'nullable|date',
            'device_id' => 'nullable|string',
        ]);

        $storeId  = (int) $validated['store_id'];
        $period   = $validated['period'];
        $baseDate = Carbon::parse($validated['date'] ?? now()->toDateString());

        // Resolve the date range
        [$from, $to, $groupFormat, $labelFormat] = match ($period) {
            'daily'   => [
                $baseDate->copy()->startOfDay(),
                $baseDate->copy()->endOfDay(),
                'HH24:00',
                'H:i',
            ],
            'weekly'  => [
                $baseDate->copy()->startOfWeek(),
                $baseDate->copy()->endOfWeek(),
                'YYYY-MM-DD',
                'D, M j',
            ],
            'monthly' => [
                $baseDate->copy()->startOfMonth(),
                $baseDate->copy()->endOfMonth(),
                'YYYY-MM-DD',
                'M j',
            ],
        };

        $query = SensorReport::forStore($storeId)
            ->between($from, $to)
            ->thSensors();

        if (!empty($validated['device_id'])) {
            $query->forDevice($validated['device_id']);
        }

        // Temperature unit conversion SQL expression
        $targetUnit = AppSetting::temperatureUnit();
        $t = AppSetting::tempSqlExpr($targetUnit);

        // ─── Aggregated time-series ──────────────────────────────────
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

        // ─── Per-device summary ──────────────────────────────────────
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

        // ─── Overall summary ─────────────────────────────────────────
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

        return response()->json([
            'success'          => true,
            'period'           => $period,
            'temperature_unit' => $targetUnit,
            'range'            => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'overall'        => $overall,
            'time_series'    => $timeSeries,
            'device_summary' => $deviceSummary,
        ]);
    }

    /**
     * List all report snapshots for a store (paginated).
     *
     * GET /api/reports/history?store_id=&per_page=&page=
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'per_page' => 'nullable|integer|min:1|max:100',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
        ]);

        $query = SensorReport::forStore($validated['store_id'])
            ->orderByDesc('recorded_at');

        if (!empty($validated['from'])) {
            $query->where('recorded_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }
        if (!empty($validated['to'])) {
            $query->where('recorded_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        $paginated = $query->paginate($validated['per_page'] ?? 25);

        $targetUnit = AppSetting::temperatureUnit();

        $paginated->getCollection()->transform(function ($report) use ($targetUnit) {
            $report->temperature = AppSetting::convertTemp($report->temperature, $report->temperature_unit, $targetUnit);
            $report->temperature_unit = $targetUnit;
            return $report;
        });

        return response()->json([
            'success'          => true,
            'temperature_unit' => $targetUnit,
            'reports'          => $paginated,
        ]);
    }
}
