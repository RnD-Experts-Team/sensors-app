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

    public function test_bulk_live_sensors_filters_by_store_ids_and_reports_missing(): void
    {
        $this->fakeExternalServices();

        $this->withToken('valid-user-token')
            ->getJson('/api/stores/sensors?store_ids[]='.$this->store->store_number.'&store_ids[]=NONEXISTENT-00000&unit=F')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('temperature_unit', 'F')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('stores.0.store.store_number', $this->store->store_number)
            ->assertJsonPath('stores.0.hub.device_id', $this->hub->device_id)
            ->assertJsonPath('stores.0.sensors.0.device_id', $this->sensor->device_id)
            ->assertJsonPath('stores.0.sensors.0.temperature', 41.18)
            ->assertJsonPath('stores.0.count', 1)
            ->assertJsonPath('requested', [$this->store->store_number, 'NONEXISTENT-00000'])
            ->assertJsonPath('missing', ['NONEXISTENT-00000']);
    }

    public function test_bulk_live_sensors_returns_all_active_stores_when_empty(): void
    {
        $this->fakeExternalServices();

        $credential = YoSmartCredential::query()->firstOrFail();

        $store2 = Store::create([
            'store_number' => '03795-00099',
            'store_name' => 'PNE Foods Store 99',
            'is_active' => true,
        ]);

        StoreDevice::create([
            'credential_id' => $credential->id,
            'store_id' => $store2->id,
            'device_id' => 'sensor-099',
            'device_token' => 'sensor-099-token',
            'device_type' => 'THSensor',
            'device_name' => 'freezer 03795-00099',
            'model_name' => 'YS8003-UC',
            'is_hub' => false,
            'parsed_store_number' => $store2->store_number,
        ]);

        // Inactive store must be excluded from the "all stores" result.
        Store::create([
            'store_number' => '03795-00100',
            'store_name' => 'Inactive Store',
            'is_active' => false,
        ]);

        $this->withToken('valid-user-token')
            ->getJson('/api/stores/sensors')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('requested', [])
            ->assertJsonPath('missing', [])
            ->assertJsonPath('stores.0.store.store_number', $this->store->store_number)
            ->assertJsonPath('stores.1.store.store_number', $store2->store_number);
    }

    public function test_bulk_live_sensors_requires_a_token(): void
    {
        $this->getJson('/api/stores/sensors')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Missing Bearer token');
    }

    public function test_live_sensors_fall_back_to_last_report_when_rate_limited(): void
    {
        // YoSmart returns 010301 (rate limit) for every device-state call.
        $this->fakeRateLimitedYoSmart();

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors?unit=F")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sensors.0.source', 'last_report')
            ->assertJsonPath('sensors.0.stale', true)
            ->assertJsonPath('sensors.0.success', true)
            // latest persisted report for the sensor is 5.80°C → 42.44°F
            ->assertJsonPath('sensors.0.temperature', 42.44)
            ->assertJsonPath('sensors.0.notice', 'Live data temporarily rate-limited; showing the last recorded reading.');
    }

    public function test_live_is_fetched_every_request_cache_does_not_short_circuit(): void
    {
        // Live-first: each request fetches fresh state from YoSmart; the cache
        // does NOT short-circuit a successful live read.
        $stateCalls = 0;
        Http::fake($this->successfulYoSmartHandler($stateCalls));

        $url = "/api/stores/{$this->store->store_number}/sensors";

        $this->withToken('valid-user-token')->getJson($url)->assertOk()->assertJsonPath('sensors.0.source', 'live');
        $this->withToken('valid-user-token')->getJson($url)->assertOk()->assertJsonPath('sensors.0.source', 'live');

        // 2 devices fetched live on BOTH requests (no cache short-circuit).
        $this->assertSame(4, $stateCalls);
    }

    public function test_cache_is_used_as_fallback_when_live_fails(): void
    {
        // First a healthy response (which populates the cache), then a rate
        // limit — the device should fall back to the cached live reading,
        // flagged stale, in preference to the older persisted report.
        $mode = 'ok';
        Http::fake(function (ClientRequest $request) use (&$mode) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                return Http::response($this->verifyBody(), 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'yosmart-access-token', 'expires_in' => 3600], 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                return $mode === 'ok'
                    ? Http::response($this->yosmartStateResponse($request->data()), 200)
                    : Http::response(['code' => '010301', 'desc' => 'Access denied due to limits reached, Please retry later'], 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        });

        $url = "/api/stores/{$this->store->store_number}/sensors?unit=F";

        // 1) Healthy — fresh live value (5.10°C → 41.18°F), cached.
        $this->withToken('valid-user-token')->getJson($url)
            ->assertOk()
            ->assertJsonPath('sensors.0.source', 'live')
            ->assertJsonPath('sensors.0.temperature', 41.18);

        // 2) Rate limited — falls back to the cached reading, not the DB report.
        $mode = 'fail';
        $this->withToken('valid-user-token')->getJson($url)
            ->assertOk()
            ->assertJsonPath('sensors.0.source', 'cache')
            ->assertJsonPath('sensors.0.stale', true)
            ->assertJsonPath('sensors.0.temperature', 41.18)
            ->assertJsonPath('sensors.0.notice', 'Live data temporarily unavailable; showing the most recent cached reading.');
    }

    public function test_rate_limit_cooldown_short_circuits_remaining_chunks(): void
    {
        // chunk size 1 => devices are fetched one chunk at a time. The first
        // 010301 engages the cooldown, so later chunks skip YoSmart entirely.
        config()->set('services.yosmart.state_chunk_size', 1);

        $stateCalls = 0;
        Http::fake($this->rateLimitedYoSmartHandler($stateCalls));

        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertOk()
            ->assertJsonPath('sensors.0.source', 'last_report');

        // 2 devices, chunk size 1: only the first device actually hit YoSmart.
        $this->assertSame(1, $stateCalls);
    }

    public function test_rate_limit_cooldown_blocks_subsequent_requests(): void
    {
        $stateCalls = 0;
        Http::fake($this->rateLimitedYoSmartHandler($stateCalls));

        $url = "/api/stores/{$this->store->store_number}/sensors";

        // First request: both devices fetched in one chunk (2 calls), cooldown set.
        $this->withToken('valid-user-token')->getJson($url)->assertOk();
        // Second request: cooldown active -> served from last report, no upstream calls.
        $this->withToken('valid-user-token')->getJson($url)
            ->assertOk()
            ->assertJsonPath('sensors.0.stale', true);

        $this->assertSame(2, $stateCalls);
    }

    public function test_device_states_are_fetched_in_bounded_chunks(): void
    {
        // Force several chunks (chunk size 2) and add devices to span them.
        config()->set('services.yosmart.state_chunk_size', 2);

        $credential = YoSmartCredential::query()->firstOrFail();
        foreach (range(2, 5) as $n) {
            StoreDevice::create([
                'credential_id' => $credential->id,
                'store_id' => $this->store->id,
                'device_id' => "sensor-00{$n}",
                'device_token' => "sensor-00{$n}-token",
                'device_type' => 'THSensor',
                'device_name' => "freezer extra {$n}",
                'model_name' => 'YS8003-UC',
                'is_hub' => false,
                'parsed_store_number' => $this->store->store_number,
            ]);
        }

        $stateCalls = 0;
        Http::fake(function (ClientRequest $request) use (&$stateCalls) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                return Http::response($this->verifyBody(), 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'yosmart-access-token', 'expires_in' => 3600], 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                $stateCalls++;

                return Http::response($this->yosmartStateResponse($request->data()), 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        });

        // 1 hub + 5 sensors = 6 devices, fetched across 3 chunks of 2.
        $this->withToken('valid-user-token')
            ->getJson("/api/stores/{$this->store->store_number}/sensors")
            ->assertOk()
            ->assertJsonPath('count', 5)
            ->assertJsonPath('sensors.0.source', 'live')
            ->assertJsonPath('hub.source', 'live');

        // Every device was fetched exactly once across the chunks.
        $this->assertSame(6, $stateCalls);
    }

    /**
     * Builds an Http::fake handler that returns healthy YoSmart device-state
     * responses while counting how many device-state calls were made.
     */
    private function successfulYoSmartHandler(int &$stateCalls): callable
    {
        return function (ClientRequest $request) use (&$stateCalls) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                return Http::response($this->verifyBody(), 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'yosmart-access-token', 'expires_in' => 3600], 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                $stateCalls++;

                return Http::response($this->yosmartStateResponse($request->data()), 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        };
    }

    /**
     * Builds an Http::fake handler that rate-limits every YoSmart device-state
     * call (010301) while counting how many were made.
     */
    private function rateLimitedYoSmartHandler(int &$stateCalls): callable
    {
        return function (ClientRequest $request) use (&$stateCalls) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                return Http::response($this->verifyBody(), 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'yosmart-access-token', 'expires_in' => 3600], 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                $stateCalls++;

                return Http::response([
                    'code' => '010301',
                    'desc' => 'Access denied due to limits reached, Please retry later',
                ], 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        };
    }

    private function fakeRateLimitedYoSmart(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === 'https://auth.example.test/api/v1/auth/token-verify') {
                return Http::response($this->verifyBody(), 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/token') {
                return Http::response(['access_token' => 'yosmart-access-token', 'expires_in' => 3600], 200);
            }
            if ($request->url() === 'https://api.yosmart.com/open/yolink/v2/api') {
                return Http::response([
                    'code' => '010301',
                    'desc' => 'Access denied due to limits reached, Please retry later',
                ], 200);
            }

            return Http::response(['error' => 'Unexpected URL: '.$request->url()], 500);
        });
    }

    private function verifyBody(bool $active = true, bool $authorized = true): array
    {
        return [
            'active' => $active,
            'user' => ['id' => 123],
            'roles' => ['api-consumer'],
            'permissions' => ['stores.read'],
            'ext' => [
                'authorized' => $authorized,
                'required_permissions' => ['stores.read'],
            ],
        ];
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
