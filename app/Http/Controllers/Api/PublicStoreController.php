<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Store;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStoreController extends Controller
{
    /**
     * Public endpoint: GET /api/stores/{store_id}/sensors
     *
     * Fetches live sensor data for every device linked to the given store.
     * Returns the store info, hub status, and every sensor's current state
     * in a single response.
     *
     * This endpoint is intentionally public (no auth) so it can be consumed
     * by external displays / dashboards. A middleware can be wrapped later
     * to add API-key or token protection.
     */
    public function sensors(Request $request, string $store_id): JsonResponse
    {
        $store = Store::where('store_number', $store_id)
            ->where('is_active', true)
            ->with('devices')
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'error'   => 'Store not found or inactive.',
            ], 404);
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

        $hub = null;
        $sensors = [];
        $targetUnit = $this->resolveUnit($request);

        foreach ($store->devices as $device) {
            $method = $this->resolveGetStateMethod($device->device_type);

            $state = $this->fetchDeviceState(
                $service,
                $method,
                $device->device_id,
                $device->device_token,
            );

            $rawState = $state['data']['state'] ?? null;
            $rawTemp  = is_array($rawState) ? ($rawState['temperature'] ?? null) : null;

            $entry = [
                'device_id'        => $device->device_id,
                'device_name'      => $device->device_name,
                'device_type'      => $device->device_type,
                'model_name'       => $device->model_name,
                'is_hub'           => $device->is_hub,
                'online'           => $state['data']['online'] ?? null,
                'temperature'      => AppSetting::convertTemp($rawTemp, 'c', $targetUnit),
                'temperature_unit' => $targetUnit,
                'state'            => $rawState,
                'reported_at'      => $state['data']['reportAt'] ?? null,
                'success'          => ($state['code'] ?? null) === '000000',
                'error'            => ($state['code'] ?? null) !== '000000'
                    ? ($state['desc'] ?? 'Unknown error')
                    : null,
            ];

            if ($device->is_hub) {
                $hub = $entry;
            } else {
                $sensors[] = $entry;
            }
        }

        return response()->json([
            'success'          => true,
            'store'            => [
                'store_number' => $store->store_number,
                'store_name'   => $store->store_name,
            ],
            'temperature_unit' => $targetUnit,
            'hub'              => $hub,
            'sensors'          => $sensors,
            'count'            => count($sensors),
            'fetched_at'       => now()->toIso8601String(),
        ]);
    }

    /**
     * Resolve the requested temperature unit from the query string.
     * Accepts ?unit=c or ?unit=f (case-insensitive). Defaults to 'C'.
     */
    private function resolveUnit(Request $request): string
    {
        $unit = strtoupper($request->input('unit', 'C'));
        return in_array($unit, ['C', 'F'], true) ? $unit : 'C';
    }

    /**
     * Resolve the correct getState method for a device type.
     */
    private function resolveGetStateMethod(string $deviceType): string
    {
        return $deviceType . '.getState';
    }

    /**
     * Call the YoSmart API to get a device's state.
     */
    private function fetchDeviceState(
        YoSmartService $service,
        string $method,
        string $deviceId,
        string $deviceToken,
    ): array {
        $result = $service->callApi($method, [
            'targetDevice' => $deviceId,
            'token'        => $deviceToken,
        ]);

        return $result ?? ['code' => 'error', 'desc' => 'API call returned null'];
    }
}
