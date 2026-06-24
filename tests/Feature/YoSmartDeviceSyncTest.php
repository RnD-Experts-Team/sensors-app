<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\YoSmartCredential;
use App\Services\YoSmartDeviceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoSmartDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_captures_parent_device_id_linking_sensors_to_hubs(): void
    {
        Http::fake([
            'https://api.yosmart.com/open/yolink/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'https://api.yosmart.com/open/yolink/v2/api' => Http::response([
                'code' => '000000',
                'data' => ['devices' => [
                    ['deviceId' => 'hub-xyz', 'name' => 'YoLink Hub', 'type' => 'Hub', 'token' => 'ht', 'modelName' => 'YS1603-UC'],
                    ['deviceId' => 'sensor-1', 'name' => 'freezer 03795-00038', 'type' => 'THSensor', 'token' => 'st1', 'modelName' => 'YS8003-UC', 'parentDeviceId' => 'hub-xyz'],
                    ['deviceId' => 'sensor-2', 'name' => 'cooler 03795-00038', 'type' => 'THSensor', 'token' => 'st2', 'modelName' => 'YS8003-UC', 'parentDeviceId' => ''],
                ]],
            ]),
        ]);

        $credential = YoSmartCredential::create(['uaid' => 'ua_X', 'secret' => 'sec_X', 'is_active' => true]);
        Store::create(['store_number' => '03795-00038', 'store_name' => 'Store 38', 'is_active' => true]);

        $result = app(YoSmartDeviceSync::class)->sync($credential);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['synced']);

        // Hub has no parent; sensor links to its hub; empty parentDeviceId -> null.
        $this->assertDatabaseHas('store_devices', ['device_id' => 'hub-xyz', 'parent_device_id' => null, 'is_hub' => true]);
        $this->assertDatabaseHas('store_devices', ['device_id' => 'sensor-1', 'parent_device_id' => 'hub-xyz']);
        $this->assertDatabaseHas('store_devices', ['device_id' => 'sensor-2', 'parent_device_id' => null]);
    }
}
