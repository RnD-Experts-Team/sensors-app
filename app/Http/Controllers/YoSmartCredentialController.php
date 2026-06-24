<?php

namespace App\Http\Controllers;

use App\Models\StoreDevice;
use App\Models\YoSmartCredential;
use App\Services\YoSmartDeviceSync;
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
            'success' => true,
            'credentials' => $credentials,
        ]);
    }

    /** POST /api/credentials */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uaid' => 'required|string|max:255|unique:yosmart_credentials,uaid',
            'secret' => 'required|string|max:500',
        ]);

        $credential = YoSmartCredential::create([
            'uaid' => $validated['uaid'],
            'secret' => $validated['secret'],
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
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
            'success' => true,
            'credential' => $credential,
        ]);
    }

    /** PUT /api/credentials/{credential} */
    public function update(Request $request, YoSmartCredential $credential): JsonResponse
    {
        $validated = $request->validate([
            'uaid' => 'required|string|max:255|unique:yosmart_credentials,uaid,'.$credential->id,
            'secret' => 'nullable|string|max:500',
        ]);

        $credential->uaid = $validated['uaid'];

        if (! empty($validated['secret'])) {
            $credential->secret = $validated['secret'];
        }

        $credential->save();

        return response()->json([
            'success' => true,
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
    public function sync(YoSmartCredential $credential, YoSmartDeviceSync $sync): JsonResponse
    {
        $result = $sync->sync($credential);

        if (! $result['ok']) {
            $status = $result['reason'] === 'missing_credentials' ? 422 : 400;

            return response()->json([
                'success' => false,
                'error' => $result['message'],
            ], $status);
        }

        return response()->json([
            'success' => true,
            'synced' => $result['synced'],
            'linked' => $result['linked'],
            'unmatched' => $result['unmatched'],
            'removed' => $result['removed'],
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
                'error' => 'Device does not belong to this credential.',
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
            'device' => $device,
        ]);
    }
}
