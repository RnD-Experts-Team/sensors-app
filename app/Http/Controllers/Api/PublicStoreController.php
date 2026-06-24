<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\SensorReport;
use App\Models\Store;
use App\Models\StoreDevice;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStoreController extends Controller
{
    /**
     * Public endpoint: GET /api/stores/{store_id}/sensors
     *
     * Fetches live sensor data for every device linked to the given store.
     * Devices may be spread across multiple credentials.
     */
    public function sensors(Request $request, string $store_id): JsonResponse
    {
        $store = $this->resolveStore($store_id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'error' => 'Store not found or inactive.',
            ], 404);
        }

        if ($store->devices->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => 'This store has no linked devices.',
            ], 422);
        }

        $targetUnit = $this->resolveUnit($request);
        $payload = $this->liveStorePayload($store, $targetUnit);

        return response()->json([
            'success' => true,
            'store' => $payload['store'],
            'temperature_unit' => $targetUnit,
            'hub' => $payload['hub'],
            'sensors' => $payload['sensors'],
            'count' => $payload['count'],
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Public endpoint: GET /api/stores/sensors?store_ids[]=…&unit=C|F
     *
     * Live sensor data for many stores at once. When `store_ids` is provided,
     * only those stores (matched by store_number) are returned; when empty or
     * omitted, every active store is returned. Store numbers that are requested
     * but not found (or inactive) are reported under `missing`.
     */
    public function bulkSensors(Request $request): JsonResponse
    {
        $request->validate([
            'store_ids' => ['sometimes'],
            'store_ids.*' => ['string'],
        ]);

        $targetUnit = $this->resolveUnit($request);
        $requested = $this->normalizeStoreIds($request->input('store_ids', []));

        $query = Store::where('is_active', true)
            ->with(['devices' => fn ($q) => $q->with('credential')])
            ->orderBy('store_number');

        if (! empty($requested)) {
            $query->whereIn('store_number', $requested);
        }

        $stores = $query->get();

        $found = $stores->pluck('store_number')->all();
        $missing = array_values(array_diff($requested, $found));

        $payload = $stores
            ->map(fn (Store $store) => $this->liveStorePayload($store, $targetUnit))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'temperature_unit' => $targetUnit,
            'count' => count($payload),
            'stores' => $payload,
            'requested' => $requested,
            'missing' => $missing,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Resolve an active store (by public store_number) with its devices and
     * each device's credential eager-loaded.
     */
    private function resolveStore(string $storeNumber): ?Store
    {
        return Store::where('store_number', $storeNumber)
            ->where('is_active', true)
            ->with(['devices' => fn ($q) => $q->with('credential')])
            ->first();
    }

    /**
     * Build the live snapshot payload for a single store: the hub entry (if any),
     * the list of sensor entries, and the sensor count.
     *
     * @return array{store: array, hub: ?array, sensors: array, count: int}
     */
    private function liveStorePayload(Store $store, string $targetUnit): array
    {
        $byCredential = $store->devices->groupBy('credential_id');

        $hub = null;
        $sensors = [];

        foreach ($byCredential as $credentialDevices) {
            $credential = $credentialDevices->first()->credential;

            if (! $credential || ! $credential->hasCredentials()) {
                continue;
            }

            $service = new YoSmartService(
                uaid: $credential->uaid,
                secret: $credential->secret,
                credentialId: $credential->id,
            );

            // Fetch every device for this credential in bounded concurrent
            // chunks (cache-first, cooldown-aware), then shape each entry.
            $states = $service->getDeviceStates(
                $credentialDevices->map(fn (StoreDevice $device) => [
                    'device_type' => $device->device_type,
                    'device_id' => $device->device_id,
                    'device_token' => $device->device_token,
                ])->all(),
            );

            foreach ($credentialDevices as $device) {
                $entry = $this->entryFromState(
                    $device,
                    $states[$device->device_id] ?? $this->unknownState(),
                    $targetUnit,
                );

                if ($device->is_hub) {
                    $hub = $entry;
                } else {
                    $sensors[] = $entry;
                }
            }
        }

        return [
            'store' => [
                'store_number' => $store->store_number,
                'store_name' => $store->store_name,
            ],
            'hub' => $hub,
            'sensors' => $sensors,
            'count' => count($sensors),
        ];
    }

    /**
     * Shape one device entry from its already-fetched state result.
     *
     * On a usable live/cache state, returns the fresh reading. Otherwise
     * (rate-limited, cooling down, or errored) falls back to the device's last
     * persisted reading so the caller still gets usable data instead of an
     * error — clearly flagged via `source` / `stale` / `notice`.
     */
    private function entryFromState(StoreDevice $device, array $result, string $targetUnit): array
    {
        if (! ($result['ok'] ?? false)) {
            return $this->lastReportEntry($device, $targetUnit, $result);
        }

        $data = $result['data'] ?? [];
        $rawState = $data['state'] ?? null;
        $rawTemp = is_array($rawState) ? ($rawState['temperature'] ?? null) : null;
        $stale = (bool) ($result['stale'] ?? false);

        return $this->deviceMeta($device, $targetUnit) + [
            'online' => $data['online'] ?? null,
            'temperature' => AppSetting::convertTemp($rawTemp, 'c', $targetUnit),
            'state' => $rawState,
            'reported_at' => $data['reportAt'] ?? null,
            'source' => $result['source'],   // 'live' (fresh) | 'cache' (stale fallback)
            'stale' => $stale,
            'as_of' => $data['reportAt'] ?? null,
            'success' => true,
            'error' => null,
            'notice' => $stale
                ? 'Live data temporarily unavailable; showing the most recent cached reading.'
                : null,
        ];
    }

    /**
     * Fallback state result for a device the batch fetch didn't return (should
     * not happen in practice; keeps entry shaping total).
     */
    private function unknownState(): array
    {
        return ['ok' => false, 'source' => 'error', 'code' => null, 'data' => null, 'desc' => null];
    }

    /**
     * Build a device entry from its most recent persisted SensorReport, used
     * when live state can't be fetched. Returns an `unavailable` entry if the
     * device has never been recorded.
     */
    private function lastReportEntry(StoreDevice $device, string $targetUnit, array $result): array
    {
        $rateLimited = in_array($result['source'], ['rate_limited', 'cooldown'], true);
        $notice = $rateLimited
            ? 'Live data temporarily rate-limited; showing the last recorded reading.'
            : 'Live data unavailable; showing the last recorded reading.';

        $report = SensorReport::where('store_device_id', $device->id)
            ->latest('recorded_at')
            ->first();

        if (! $report) {
            return $this->deviceMeta($device, $targetUnit) + [
                'online' => null,
                'temperature' => null,
                'state' => null,
                'reported_at' => null,
                'source' => 'unavailable',
                'stale' => true,
                'as_of' => null,
                'success' => false,
                'error' => $result['desc'] ?? ($rateLimited ? 'Rate limited, please retry later.' : 'Device state unavailable.'),
                'notice' => $notice,
            ];
        }

        return $this->deviceMeta($device, $targetUnit) + [
            'online' => $report->online,
            'temperature' => AppSetting::convertTemp($report->temperature, $report->temperature_unit, $targetUnit),
            'state' => $report->state,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'source' => 'last_report',
            'stale' => true,
            'as_of' => $report->recorded_at?->toIso8601String(),
            'success' => true,
            'error' => null,
            'notice' => $notice,
        ];
    }

    /**
     * The static identity fields shared by every device entry shape.
     */
    private function deviceMeta(StoreDevice $device, string $targetUnit): array
    {
        return [
            'device_id' => $device->device_id,
            'device_name' => $device->device_name,
            'device_type' => $device->device_type,
            'model_name' => $device->model_name,
            'is_hub' => $device->is_hub,
            'temperature_unit' => $targetUnit,
        ];
    }

    /**
     * Normalize the `store_ids` input into a clean, de-duplicated list of
     * store-number strings. Accepts either an array (store_ids[]=A&store_ids[]=B)
     * or a comma-separated string (store_ids=A,B).
     */
    private function normalizeStoreIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        $ids = array_map(
            fn ($v) => is_string($v) ? trim($v) : $v,
            (array) $raw,
        );

        $ids = array_filter($ids, fn ($v) => is_string($v) && $v !== '');

        return array_values(array_unique($ids));
    }

    private function resolveUnit(Request $request): string
    {
        $unit = strtoupper($request->input('unit', 'C'));

        return in_array($unit, ['C', 'F'], true) ? $unit : 'C';
    }
}
