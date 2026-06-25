<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDevice;
use App\Models\YoSmartCredential;
use App\Services\YoSmartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptureSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep tests fast — no real pacing/backoff sleeps.
        config()->set('services.yosmart.capture_chunk_delay_ms', 0);
        config()->set('services.yosmart.capture_backoff_ms', 0);
        config()->set('services.yosmart.capture_max_retries', 1);
    }

    public function test_snapshot_persists_only_successful_reads(): void
    {
        $credential = YoSmartCredential::create(['uaid' => 'ua', 'secret' => 'sec', 'is_active' => true]);
        $store = Store::create(['store_number' => '03795-00001', 'store_name' => 'S1', 'is_active' => true]);

        foreach (['sensor-A', 'sensor-B'] as $id) {
            StoreDevice::create([
                'credential_id' => $credential->id,
                'store_id' => $store->id,
                'device_id' => $id,
                'device_token' => "{$id}-token",
                'device_type' => 'THSensor',
                'device_name' => "freezer {$id}",
                'is_hub' => false,
                'parsed_store_number' => $store->store_number,
            ]);
        }

        // sensor-A succeeds; sensor-B is permanently rate-limited.
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            $target = $request->data()['targetDevice'] ?? '';

            return $target === 'sensor-A'
                ? Http::response(['code' => '000000', 'data' => ['online' => true, 'state' => ['temperature' => 4.0]]])
                : Http::response(['code' => '010301', 'desc' => 'Access denied due to limits reached']);
        });

        $this->artisan('snapshots:capture', ['--force' => true])->assertExitCode(0);

        // Good reading persisted for A; NO null row written for rate-limited B.
        $this->assertDatabaseHas('sensor_reports', ['device_id' => 'sensor-A', 'temperature' => 4.0]);
        $this->assertDatabaseMissing('sensor_reports', ['device_id' => 'sensor-B']);
    }

    public function test_capture_device_states_retries_rate_limit_then_succeeds(): void
    {
        config()->set('services.yosmart.capture_max_retries', 2);

        $attempts = 0;
        Http::fake(function (ClientRequest $request) use (&$attempts) {
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            $attempts++;

            // First device-state call is rate-limited, the retry succeeds.
            return $attempts === 1
                ? Http::response(['code' => '010301', 'desc' => 'limits'])
                : Http::response(['code' => '000000', 'data' => ['online' => true, 'state' => ['temperature' => 5.1]]]);
        });

        $service = new YoSmartService('ua', 'sec', 1);

        $result = $service->captureDeviceStates([
            ['device_type' => 'THSensor', 'device_id' => 'sensor-1', 'device_token' => 't'],
        ]);

        $this->assertTrue($result['sensor-1']['success']);
        $this->assertEquals(5.1, $result['sensor-1']['data']['state']['temperature']);
        $this->assertSame(2, $attempts); // one rate-limited, one successful retry
    }
}
