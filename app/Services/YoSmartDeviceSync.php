<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreDevice;
use App\Models\YoSmartCredential;

/**
 * Syncs a credential's devices from YoLink (`Home.getDeviceList`) into
 * `store_devices`: upserts each device, links sensors to their store by parsed
 * store number, records each sensor's parent hub, and prunes devices that no
 * longer exist remotely.
 *
 * Shared by the admin sync endpoint and the `yosmart:sync` console command.
 */
class YoSmartDeviceSync
{
    /**
     * @return array{ok: bool, reason?: string, message?: string, synced?: int, linked?: int, unmatched?: int, removed?: int}
     */
    public function sync(YoSmartCredential $credential): array
    {
        if (! $credential->hasCredentials()) {
            return ['ok' => false, 'reason' => 'missing_credentials', 'message' => 'Credential is missing UAID or secret.'];
        }

        $service = new YoSmartService(
            uaid: $credential->uaid,
            secret: $credential->secret,
            credentialId: $credential->id,
        );

        $devices = $service->listDevices();

        if ($devices === null) {
            return ['ok' => false, 'reason' => 'fetch_failed', 'message' => 'Failed to fetch device list from YoSmart.'];
        }

        // Lookup of store_number → store_id for linking sensors to stores.
        $storeMap = Store::where('is_active', true)
            ->pluck('id', 'store_number')
            ->toArray();

        $synced = 0;
        $linked = 0;
        $unmatched = 0;

        foreach ($devices as $device) {
            $deviceId = $device['deviceId'] ?? null;
            $deviceName = $device['name'] ?? '';
            $deviceType = $device['type'] ?? 'unknown';
            $isHub = $deviceType === 'Hub';

            if (! $deviceId) {
                continue;
            }

            $parsedNumber = StoreDevice::parseStoreNumber($deviceName);
            $storeId = $parsedNumber && isset($storeMap[$parsedNumber])
                ? $storeMap[$parsedNumber]
                : null;

            // Hubs are global — don't link to a specific store, and have no parent.
            if ($isHub) {
                $storeId = null;
                $parsedNumber = null;
            }

            StoreDevice::updateOrCreate(
                [
                    'credential_id' => $credential->id,
                    'device_id' => $deviceId,
                ],
                [
                    'store_id' => $storeId,
                    'parent_device_id' => $isHub ? null : (($device['parentDeviceId'] ?? null) ?: null),
                    'device_token' => $device['token'] ?? '',
                    'device_type' => $deviceType,
                    'device_name' => $deviceName,
                    'model_name' => $device['modelName'] ?? null,
                    'is_hub' => $isHub,
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

        // Remove devices that no longer exist on YoSmart.
        $remoteIds = collect($devices)->pluck('deviceId')->filter()->toArray();
        $removed = StoreDevice::where('credential_id', $credential->id)
            ->whereNotIn('device_id', $remoteIds)
            ->delete();

        return [
            'ok' => true,
            'synced' => $synced,
            'linked' => $linked,
            'unmatched' => $unmatched,
            'removed' => $removed,
        ];
    }
}
