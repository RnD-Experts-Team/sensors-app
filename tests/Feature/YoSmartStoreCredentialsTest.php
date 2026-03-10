<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoSmartStoreCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // Store CRUD – credential fields
    // -------------------------------------------------------------------------

    public function test_store_can_be_created_with_yosmart_credentials(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('stores.store'), [
                'store_number'   => 'S001',
                'store_name'     => 'Test Store',
                'yosmart_uaid'   => 'ua_TESTID',
                'yosmart_secret' => 'sec_v1_TESTSECRET',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('store.store_number', 'S001');

        $this->assertDatabaseHas('stores', ['store_number' => 'S001', 'yosmart_uaid' => 'ua_TESTID']);

        // Verify the secret is stored encrypted (raw DB value should not be the plain text)
        $raw = \DB::table('stores')->where('store_number', 'S001')->value('yosmart_secret');
        $this->assertNotEquals('sec_v1_TESTSECRET', $raw, 'Secret must be encrypted at rest');
    }

    public function test_store_can_be_created_without_yosmart_credentials(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('stores.store'), [
                'store_number' => 'S002',
                'store_name'   => 'No-Creds Store',
            ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('stores', ['store_number' => 'S002', 'yosmart_uaid' => null]);
    }

    public function test_store_credentials_can_be_updated(): void
    {
        $store = Store::create([
            'store_number' => 'S003',
            'store_name'   => 'Update Store',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(route('stores.update', $store), [
                'yosmart_uaid'   => 'ua_NEW',
                'yosmart_secret' => 'sec_v1_NEW',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $store->refresh();
        $this->assertSame('ua_NEW', $store->yosmart_uaid);
        $this->assertSame('sec_v1_NEW', $store->yosmart_secret); // decrypted via model cast
    }

    public function test_yosmart_secret_is_decrypted_via_model(): void
    {
        $store = Store::create([
            'store_number'   => 'S004',
            'store_name'     => 'Encrypted Store',
            'yosmart_secret' => 'sec_v1_MYSECRET',
        ]);

        $freshStore = Store::find($store->id);
        $this->assertSame('sec_v1_MYSECRET', $freshStore->yosmart_secret);
    }

    // -------------------------------------------------------------------------
    // YoSmart routes – missing credentials
    // -------------------------------------------------------------------------

    public function test_yosmart_routes_return_422_when_store_has_no_credentials(): void
    {
        $store = Store::create(['store_number' => 'S010', 'store_name' => 'No Creds']);

        $endpoints = [
            ['GET',  route('yosmart.devices.list', $store)],
            ['GET',  route('yosmart.home.info', $store)],
            ['GET',  route('yosmart.device.states.all', $store)],
            ['GET',  route('yosmart.health', $store)],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->actingAs($this->user)->json($method, $url);
            // health returns 200 with status=unconfigured; all others return 422
            if (str_ends_with($url, '/health')) {
                $response->assertOk()->assertJsonPath('status', 'unconfigured');
            } else {
                $response->assertStatus(422)->assertJsonPath('success', false);
            }
        }
    }

    // -------------------------------------------------------------------------
    // YoSmart routes – with valid credentials (HTTP faked)
    // -------------------------------------------------------------------------

    private function storeWithCreds(): Store
    {
        return Store::create([
            'store_number'   => 'S020',
            'store_name'     => 'Credentialed Store',
            'yosmart_uaid'   => 'ua_FAKE',
            'yosmart_secret' => 'sec_v1_FAKE',
        ]);
    }

    private function fakeYoSmartToken(): void
    {
        Http::fake([
            'api.yosmart.com/open/yolink/token' => Http::response([
                'access_token' => 'fake_token_xyz',
                'expires_in'   => 3600,
                'token_type'   => 'Bearer',
            ], 200),
        ]);
    }

    public function test_list_devices_returns_devices_from_yosmart(): void
    {
        $store = $this->storeWithCreds();

        Http::fake([
            'api.yosmart.com/open/yolink/token' => Http::response([
                'access_token' => 'fake_token',
                'expires_in'   => 3600,
            ], 200),
            'api.yosmart.com/open/yolink/v2/api' => Http::response([
                'code' => '000000',
                'data' => [
                    'devices' => [
                        ['deviceId' => 'dev1', 'name' => 'Hub', 'type' => 'Hub', 'token' => 'tok1'],
                        ['deviceId' => 'dev2', 'name' => 'Sensor', 'type' => 'THSensor', 'token' => 'tok2'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('yosmart.devices.list', $store));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2);
    }

    public function test_health_returns_ok_when_api_responds(): void
    {
        $store = $this->storeWithCreds();

        Http::fake([
            'api.yosmart.com/open/yolink/token' => Http::response([
                'access_token' => 'fake_token',
                'expires_in'   => 3600,
            ], 200),
            'api.yosmart.com/open/yolink/v2/api' => Http::response([
                'code' => '000000',
                'data' => ['id' => 'home1'],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('yosmart.health', $store));

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('credentials_configured', true);
    }

    public function test_two_stores_use_separate_credentials_and_cache_keys(): void
    {
        $store1 = Store::create([
            'store_number'   => 'S030',
            'store_name'     => 'Store One',
            'yosmart_uaid'   => 'ua_STORE1',
            'yosmart_secret' => 'sec_v1_STORE1',
        ]);
        $store2 = Store::create([
            'store_number'   => 'S031',
            'store_name'     => 'Store Two',
            'yosmart_uaid'   => 'ua_STORE2',
            'yosmart_secret' => 'sec_v1_STORE2',
        ]);

        // Track which client_id was used in token requests
        $tokenRequests = [];

        Http::fake([
            'api.yosmart.com/open/yolink/token' => function ($request) use (&$tokenRequests) {
                $tokenRequests[] = $request->data()['client_id'] ?? null;
                return Http::response([
                    'access_token' => 'token_for_' . ($request->data()['client_id'] ?? 'unknown'),
                    'expires_in'   => 3600,
                ], 200);
            },
            'api.yosmart.com/open/yolink/v2/api' => Http::response([
                'code' => '000000',
                'data' => ['devices' => []],
            ], 200),
        ]);

        $this->actingAs($this->user)->getJson(route('yosmart.devices.list', $store1));
        $this->actingAs($this->user)->getJson(route('yosmart.devices.list', $store2));

        $this->assertContains('ua_STORE1', $tokenRequests, 'Store 1 UAID should have been used for token request');
        $this->assertContains('ua_STORE2', $tokenRequests, 'Store 2 UAID should have been used for token request');
    }

    public function test_available_devices_for_store_uses_store_credentials(): void
    {
        $store = $this->storeWithCreds();

        Http::fake([
            'api.yosmart.com/open/yolink/token' => Http::response([
                'access_token' => 'fake_token',
                'expires_in'   => 3600,
            ], 200),
            'api.yosmart.com/open/yolink/v2/api' => Http::response([
                'code' => '000000',
                'data' => [
                    'devices' => [
                        ['deviceId' => 'devA', 'name' => 'Freezer Sensor', 'type' => 'THSensor', 'token' => 'tokA'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('stores.devices.available', $store));

        $response->assertOk()->assertJsonPath('success', true);
    }
}
