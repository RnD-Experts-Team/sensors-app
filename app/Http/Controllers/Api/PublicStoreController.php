<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;

class PublicStoreController extends Controller
{
    /**
     * Public endpoint: GET /api/stores/{storeNumber}/sensors
     *
     * Fetches live sensor data for every device linked to the given store.
     * Returns the store info, hub status, and every sensor's current state
     * in a single response.
     *
     * This endpoint is intentionally public (no auth) so it can be consumed
     * by external displays / dashboards. A middleware can be wrapped later
     * to add API-key or token protection.
     */
    public function sensors(string $storeNumber): JsonResponse
    {
        $store = Store::where('store_number', $storeNumber)
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

        foreach ($store->devices as $device) {
            $method = $this->resolveGetStateMethod($device->device_type);

            $state = $this->fetchDeviceState(
                $service,
                $method,
                $device->device_id,
                $device->device_token,
            );

            $entry = [
                'device_id'   => $device->device_id,
                'device_name' => $device->device_name,
                'device_type' => $device->device_type,
                'model_name'  => $device->model_name,
                'is_hub'      => $device->is_hub,
                'online'      => $state['data']['online'] ?? null,
                'state'       => $state['data']['state'] ?? null,
                'reported_at' => $state['data']['reportAt'] ?? null,
                'success'     => ($state['code'] ?? null) === '000000',
                'error'       => ($state['code'] ?? null) !== '000000'
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
            'success' => true,
            'store'   => [
                'store_number' => $store->store_number,
                'store_name'   => $store->store_name,
            ],
            'hub'     => $hub,
            'sensors' => $sensors,
            'count'   => count($sensors),
            'fetched_at' => now()->toIso8601String(),
        ]);
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
