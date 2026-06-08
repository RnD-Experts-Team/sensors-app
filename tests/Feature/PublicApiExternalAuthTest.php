<?php

namespace Tests\Feature;

use App\Models\SensorReport;
use App\Models\Store;
use App\Models\StoreDevice;
use App\Models\YoSmartCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicApiExternalAuthTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private StoreDevice $sensor;

    private StoreDevice $hub;

    private array $authServerPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-27 12:00:00'));

        config()->set('services.auth_server', [
            'base_url' => 'https://auth.example.test',
            'verify_path' => '/api/v1/auth/token-verify',
            'service_name' => 'sensors-app',
            'call_token' => 'service-call-token',
            'timeout' => 3,
            'retries' => 0,
            'retry_ms' => 100,
            'cache_ttl' => 30,
        ]);

        $this->seedStoreFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_external_auth_middleware_sends_expected_verification_payload(): void
    {
        $this->fakeExternalServices();

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors?unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('count', 1);

        $this->assertCount(1, $this->authServerPayloads);
        $payload = $this->authServerPayloads[0];

        $this->assertSame('sensors-app', $payload['service']);
        $this->assertSame('valid-user-token', $payload['token']);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame("/api/stores/{$this->store->store_number}/sensors", $payload['path']);
        $this->assertSame('api.stores.sensors', $payload['route_name']);
        $this->assertSame($this->store->store_number, $payload['store_context']['path']['store_id']);
        $this->assertSame('F', $payload['store_context']['query']['unit']);
        $this->assertSame([], $payload['store_context']['body']);

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://auth.example.test/api/v1/auth/token-verify'
                && $request->hasHeader('Authorization', 'Bearer service-call-token');
        });
    }

    public function test_external_auth_middleware_rejects_bad_auth_states(): void
    {
        $this->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Missing Bearer token');

        $this->fakeAuthResponsesByToken();

        $this->withToken('inactive-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized');

        $this->withToken('not-store-scoped-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden');
    }

    public function test_upstream_auth_server_failures_are_surfaced_not_masked(): void
    {
        // Auth server replies 409 to our verify call -> must surface as 502,
        // not a misleading 401, so the integration problem is debuggable.
        Http::fake([
            'https://auth.example.test/*' => Http::response(['error' => 'conflict'], 409),
        ]);

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertStatus(502);

        // Auth server unreachable (transport error) -> 503.
        Http::fake([
            'https://auth.example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertStatus(503);
    }

    public function test_missing_auth_server_config_returns_500(): void
    {
        config()->set('services.auth_server.call_token', '');

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertStatus(500);
    }

    public function test_protected_public_api_endpoints_return_expected_responses(): void
    {
        $this->fakeExternalServices();

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors?unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('store.store_number', $this->store->store_number)
            ->assertJsonPath('hub.device_id', $this->hub->device_id)
            ->assertJsonPath('sensors.0.device_id', $this->sensor->device_id)
            ->assertJsonPath('sensors.0.temperature', 41.18)
            ->assertJsonPath('count', 1);

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/reports?period=daily&date=2026-04-27&unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('period', 'daily')
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('overall.total_readings', 2)
            ->assertJsonPath('overall.total_alarms', 1)
            ->assertJsonPath('time_series.0.time_bucket', '09:00')
            ->assertJsonPath('device_summary.0.device_id', $this->sensor->device_id);

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/reports/history?from=2026-04-27&to=2026-04-27&per_page=1&unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('reports.per_page', 1)
            ->assertJsonPath('reports.total', 2);

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/reports/alerts?from=2026-04-27&to=2026-04-27T23:59:59&unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('alarm_count', 1)
            ->assertJsonPath('offline_count', 1);

        $this->withToken('valid-user-token')
            ->postJson("/api/stores/{$this->store->store_number}/reports/snapshot?unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('snapshot.0.device_id', $this->hub->device_id)
            ->assertJsonPath('snapshot.1.device_id', $this->sensor->device_id)
            ->assertJsonPath('snapshot.1.temperature', 41.18)
            ->assertJsonPath('count', 2);
    }

    private function seedStoreFixture(): void
    {
        $credential = YoSmartCredential::create([
            'uaid' => 'ua_TEST',
            'secret' => 'sec_TEST',
            'is_active' => true,
        ]);

        $this->store = Store::create([
            'store_number' => '03795-00038',
            'store_name' => 'PNE Foods Store 38',
            'is_active' => true,
        ]);

        $this->hub = StoreDevice::create([
            'credential_id' => $credential->id,
            'store_id' => $this->store->id,
            'device_id' => 'hub-001',
            'device_token' => 'hub-token',
            'device_type' => 'Hub',
            'device_name' => 'YoLink Hub',
            'model_name' => 'YS1603-UC',
            'is_hub' => true,
            'parsed_store_number' => $this->store->store_number,
        ]);

        $this->sensor = StoreDevice::create([
            'credential_id' => $credential->id,
            'store_id' => $this->store->id,
            'device_id' => 'sensor-001',
            'device_token' => 'sensor-token',
            'device_type' => 'THSensor',
            'device_name' => 'freezer 03795-00038',
            'model_name' => 'YS8003-UC',
            'is_hub' => false,
            'parsed_store_number' => $this->store->store_number,
        ]);

        SensorReport::create([
            'store_id' => $this->store->id,
            'store_device_id' => $this->sensor->id,
            'device_id' => $this->sensor->device_id,
            'device_type' => $this->sensor->device_type,
            'device_name' => $this->sensor->device_name,
            'online' => true,
            'temperature' => 4.10,
            'temperature_unit' => 'c',
            'humidity' => 65.30,
            'battery_level' => 4,
            'alarm' => false,
            'state' => 'normal',
            'raw_state' => ['state' => 'normal'],
            'reported_at' => Carbon::parse('2026-04-27 09:00:00'),
            'recorded_at' => Carbon::parse('2026-04-27 09:00:00'),
        ]);

        SensorReport::create([
            'store_id' => $this->store->id,
            'store_device_id' => $this->sensor->id,
            'device_id' => $this->sensor->device_id,
            'device_type' => $this->sensor->device_type,
            'device_name' => $this->sensor->device_name,
            'online' => false,
            'temperature' => 5.80,
            'temperature_unit' => 'c',
            'humidity' => 72.10,
            'battery_level' => 3,
            'alarm' => true,
            'state' => 'highTemp',
            'raw_state' => ['state' => 'highTemp'],
            'reported_at' => Carbon::parse('2026-04-27 10:00:00'),
            'recorded_at' => Carbon::parse('2026-04-27 10:00:00'),
        ]);
    }

    private function fakeExternalServices(bool $active = true, bool $authorized = true): void
    {
        $this->authServerPayloads = [];

        Http::fake(function (ClientRequest $request) use ($active, $authorized) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                $this->authServerPayloads[] = $request->data();

                return Http::response([
                    'active' => $active,
                    'user' => ['id' => 123],
                    'roles' => ['api-consumer'],
                    'permissions' => ['stores.read'],
                    'ext' => [
                        'authorized' => $authorized,
                        'required_permissions' => ['stores.read'],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response([
                    'access_token' => 'yosmart-access-token',
                    'expires_in' => 3600,
                ], 200);
            }

            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                return Http::response($this->yosmartStateResponse($request->data()), 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        });
    }

    private function fakeAuthResponsesByToken(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                $token = $request->data()['token'] ?? '';

                return Http::response([
                    'active' => $token !== 'inactive-token',
                    'user' => ['id' => 123],
                    'roles' => ['api-consumer'],
                    'permissions' => ['stores.read'],
                    'ext' => [
                        'authorized' => $token !== 'not-store-scoped-token',
                        'required_permissions' => ['stores.read'],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        });
    }

    private function yosmartStateResponse(array $payload): array
    {
        $deviceId = $payload['targetDevice'] ?? '';

        if ($deviceId === $this->hub->device_id) {
            return [
                'code' => '000000',
                'desc' => 'Success',
                'data' => [
                    'online' => true,
                    'state' => ['state' => 'online'],
                    'reportAt' => '2026-04-27T11:55:00Z',
                ],
            ];
        }

        return [
            'code' => '000000',
            'desc' => 'Success',
            'data' => [
                'online' => true,
                'state' => [
                    'temperature' => 5.10,
                    'humidity' => 66.40,
                    'battery' => 4,
                    'alarm' => false,
                    'state' => 'normal',
                ],
                'reportAt' => '2026-04-27T11:58:00Z',
            ],
        ];
    }
}
