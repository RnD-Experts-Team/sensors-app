<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreDevice;
use App\Models\YoSmartCredential;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoSmartCredentialController extends Controller
{
    /** GET /api/credentials */
    public function index(): JsonResponse
    {
        $credentials = YoSmartCredential::withCount(['devices', 'hubs', 'sensors'])
            ->orderBy('uaid')
            ->get();

        return response()->json([
            'success'     => true,
            'credentials' => $credentials,
        ]);
    }

    /** POST /api/credentials */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uaid'   => 'required|string|max:255|unique:yosmart_credentials,uaid',
            'secret' => 'required|string|max:500',
        ]);

        $credential = YoSmartCredential::create([
            'uaid'      => $validated['uaid'],
            'secret'    => $validated['secret'],
            'is_active' => true,
        ]);

        return response()->json([
            'success'    => true,
            'credential' => $credential,
        ], 201);
    }

    /** GET /api/credentials/{credential} */
    public function show(YoSmartCredential $credential): JsonResponse
    {
        $credential->loadCount(['devices', 'hubs', 'sensors']);
        $credential->load(['devices' => function ($q) {
            $q->with('store:id,store_number,store_name');
        }]);

        return response()->json([
            'success'    => true,
            'credential' => $credential,
        ]);
    }

    /** PUT /api/credentials/{credential} */
    public function update(Request $request, YoSmartCredential $credential): JsonResponse
    {
        $validated = $request->validate([
            'uaid'   => 'required|string|max:255|unique:yosmart_credentials,uaid,' . $credential->id,
            'secret' => 'nullable|string|max:500',
        ]);

        $credential->uaid = $validated['uaid'];

        if (! empty($validated['secret'])) {
            $credential->secret = $validated['secret'];
        }

        $credential->save();

        return response()->json([
            'success'    => true,
            'credential' => $credential,
        ]);
    }

    /** DELETE /api/credentials/{credential} */
    public function destroy(YoSmartCredential $credential): JsonResponse
    {
        $credential->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/credentials/{credential}/sync
     *
     * Fetch devices from YoSmart, upsert into store_devices,
     * auto-link to stores by parsing store number from device name.
     */
    public function sync(YoSmartCredential $credential): JsonResponse
    {
        if (! $credential->hasCredentials()) {
            return response()->json([
                'success' => false,
                'error'   => 'Credential is missing UAID or secret.',
            ], 422);
        }

        $service = new YoSmartService(
            uaid:         $credential->uaid,
            secret:       $credential->secret,
            credentialId: $credential->id,
        );

        $devices = $service->listDevices();

        if ($devices === null) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to fetch device list from YoSmart.',
            ], 400);
        }

        // Build a lookup of store_number → store_id
        $storeMap = Store::where('is_active', true)
            ->pluck('id', 'store_number')
            ->toArray();

        $synced    = 0;
        $linked    = 0;
        $unmatched = 0;

        foreach ($devices as $device) {
            $deviceId   = $device['deviceId'] ?? null;
            $deviceName = $device['name'] ?? '';
            $deviceType = $device['type'] ?? 'unknown';
            $isHub      = $deviceType === 'Hub';

            if (! $deviceId) {
                continue;
            }

            // Parse store number from device name
            $parsedNumber = StoreDevice::parseStoreNumber($deviceName);
            $storeId      = $parsedNumber && isset($storeMap[$parsedNumber])
                ? $storeMap[$parsedNumber]
                : null;

            // Hubs are global — don't link to a specific store
            if ($isHub) {
                $storeId      = null;
                $parsedNumber = null;
            }

            StoreDevice::updateOrCreate(
                [
                    'credential_id' => $credential->id,
                    'device_id'     => $deviceId,
                ],
                [
                    'store_id'            => $storeId,
                    'device_token'        => $device['token'] ?? '',
                    'device_type'         => $deviceType,
                    'device_name'         => $deviceName,
                    'model_name'          => $device['modelName'] ?? null,
                    'is_hub'              => $isHub,
                    'parsed_store_number' => $parsedNumber,
                ],
            );

            $synced++;

            if ($storeId) {
                $linked++;
            } elseif (! $isHub) {
                $unmatched++;
            }
        }

        $credential->update(['last_synced_at' => now()]);

        // Remove devices that no longer exist on YoSmart
        $remoteIds = collect($devices)->pluck('deviceId')->filter()->toArray();
        $removed   = StoreDevice::where('credential_id', $credential->id)
            ->whereNotIn('device_id', $remoteIds)
            ->delete();

        return response()->json([
            'success'   => true,
            'synced'    => $synced,
            'linked'    => $linked,
            'unmatched' => $unmatched,
            'removed'   => $removed,
        ]);
    }

    /**
     * PUT /api/credentials/{credential}/devices/{device}/assign
     *
     * Manually assign a device to a store.
     */
    public function assignDevice(Request $request, YoSmartCredential $credential, StoreDevice $device): JsonResponse
    {
        if ((int) $device->credential_id !== (int) $credential->id) {
            return response()->json([
                'success' => false,
                'error'   => 'Device does not belong to this credential.',
            ], 404);
        }

        $validated = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $device->update([
            'store_id' => $validated['store_id'],
        ]);

        $device->load('store:id,store_number,store_name');

        return response()->json([
            'success' => true,
            'device'  => $device,
        ]);
    }
}
