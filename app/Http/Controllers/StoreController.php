<?php

namespace App\Http\Controllers;

use App\Http\Controllers\YoSmartController;
use App\Models\Store;
use App\Models\StoreDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * List all stores.
     */
    public function index(): JsonResponse
    {
        $stores = Store::withCount(['devices', 'hubs', 'sensors'])->get();

        return response()->json([
            'success' => true,
            'stores' => $stores,
        ]);
    }

    /**
     * Create a new store.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_number'   => 'required|string|max:50|unique:stores,store_number',
            'store_name'     => 'required|string|max:255',
            'yosmart_uaid'   => 'nullable|string|max:100',
            'yosmart_secret' => 'nullable|string|max:500',
        ]);

        $store = Store::create($validated);

        return response()->json([
            'success' => true,
            'store' => $store,
        ], 201);
    }

    /**
     * Get a single store with its devices.
     */
    public function show(Store $store): JsonResponse
    {
        $store->load('devices');

        return response()->json([
            'success' => true,
            'store' => $store,
        ]);
    }

    /**
     * Update a store.
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'store_number'   => 'sometimes|required|string|max:50|unique:stores,store_number,' . $store->id,
            'store_name'     => 'sometimes|required|string|max:255',
            'is_active'      => 'sometimes|boolean',
            'yosmart_uaid'   => 'sometimes|nullable|string|max:100',
            'yosmart_secret' => 'sometimes|nullable|string|max:500',
        ]);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'store' => $store,
        ]);
    }

    /**
     * Delete a store and its device links.
     */
    public function destroy(Store $store): JsonResponse
    {
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted.',
        ]);
    }

    /**
     * Link devices to a store.
     *
     * Accepts an array of device objects from the YoSmart device list
     * and saves them as linked devices for this store.
     */
    public function linkDevices(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'devices'               => 'required|array|min:1',
            'devices.*.device_id'   => 'required|string|max:100',
            'devices.*.device_token'=> 'required|string|max:200',
            'devices.*.device_type' => 'required|string|max:50',
            'devices.*.device_name' => 'required|string|max:255',
            'devices.*.model_name'  => 'nullable|string|max:100',
            'devices.*.is_hub'      => 'sometimes|boolean',
        ]);

        $linked = [];
        foreach ($validated['devices'] as $deviceData) {
            $linked[] = StoreDevice::updateOrCreate(
                [
                    'store_id'  => $store->id,
                    'device_id' => $deviceData['device_id'],
                ],
                [
                    'device_token' => $deviceData['device_token'],
                    'device_type'  => $deviceData['device_type'],
                    'device_name'  => $deviceData['device_name'],
                    'model_name'   => $deviceData['model_name'] ?? null,
                    'is_hub'       => $deviceData['is_hub'] ?? ($deviceData['device_type'] === 'Hub'),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'linked' => count($linked),
            'devices' => $linked,
        ]);
    }

    /**
     * Unlink a device from a store.
     */
    public function unlinkDevice(Store $store, StoreDevice $device): JsonResponse
    {
        if ($device->store_id !== $store->id) {
            return response()->json([
                'success' => false,
                'error' => 'Device does not belong to this store.',
            ], 404);
        }

        $device->delete();

        return response()->json([
            'success' => true,
            'message' => 'Device unlinked.',
        ]);
    }

    /**
     * List all available YoSmart devices for a store so the user
     * can pick which to link to it.
     */
    public function availableDevices(Store $store): JsonResponse
    {
        $yosmart = app(YoSmartController::class);

        return $yosmart->listDevices($store);
    }
}
