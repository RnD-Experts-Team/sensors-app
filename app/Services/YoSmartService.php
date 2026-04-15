<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoSmartService
{
    private const TOKEN_URL = 'https://api.yosmart.com/open/yolink/token';
    private const API_URL   = 'https://api.yosmart.com/open/yolink/v2/api';
    private const CACHE_TTL = 3600;

    /** Error codes that mean the access token must be refreshed. */
    private const TOKEN_ERROR_CODES = [
        '010103', // Authorization is invalid
        '010104', // Token is expired
    ];

    /**
     * Map of YoSmart device types to their API method prefix.
     * Each device type has its own `{Type}.getState` endpoint.
     */
    private const DEVICE_TYPE_MAP = [
        'Hub'                  => 'Hub',
        'CellularHub'          => 'CellularHub',
        'SpeakerHub'           => 'SpeakerHub',
        'THSensor'             => 'THSensor',
        'DoorSensor'           => 'DoorSensor',
        'MotionSensor'         => 'MotionSensor',
        'LeakSensor'           => 'LeakSensor',
        'VibrationSensor'      => 'VibrationSensor',
        'COSmokeSensor'        => 'COSmokeSensor',
        'SmartRemoter'         => 'SmartRemoter',
        'InfraredRemoter'      => 'InfraredRemoter',
        'Outlet'               => 'Outlet',
        'MultiOutlet'          => 'MultiOutlet',
        'Switch'               => 'Switch',
        'Dimmer'               => 'Dimmer',
        'Lock'                 => 'Lock',
        'LockV2'               => 'LockV2',
        'GarageDoor'           => 'GarageDoor',
        'Finger'               => 'Finger',
        'Siren'                => 'Siren',
        'Manipulator'          => 'Manipulator',
        'Sprinkler'            => 'Sprinkler',
        'SprinklerV2'          => 'SprinklerV2',
        'Thermostat'           => 'Thermostat',
        'IPCamera'             => 'IPCamera',
        'PowerFailureAlarm'    => 'PowerFailureAlarm',
        'SoilThcSensor'        => 'SoilThcSensor',
        'WaterDepthSensor'     => 'WaterDepthSensor',
        'WaterMeterController' => 'WaterMeterController',
        'CSDevice'             => 'CSDevice',
    ];

    private string $tokenCacheKey;
    private string $devicesCacheKey;

    public function __construct(
        private readonly string $uaid,
        private readonly string $secret,
        private readonly int $credentialId,
    ) {
        $this->tokenCacheKey   = "yosmart_access_token_{$credentialId}";
        $this->devicesCacheKey = "yosmart_devices_{$credentialId}";
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    public function getAccessToken(): ?string
    {
        $token = Cache::get($this->tokenCacheKey);

        if ($token) {
            Log::debug('YoSmart: using cached token', ['store_id' => $this->storeId]);
            return $token;
        }

        return $this->fetchAccessToken();
    }

    public function refreshAccessToken(): ?string
    {
        Log::info('YoSmart: force-refreshing token', ['store_id' => $this->storeId]);
        Cache::forget($this->tokenCacheKey);

        return $this->fetchAccessToken();
    }

    private function fetchAccessToken(): ?string
    {
        try {
            Log::info('YoSmart: fetching new access token', ['store_id' => $this->storeId]);

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->uaid,
                'client_secret' => $this->secret,
            ]);

            if ($response->successful()) {
                $data      = $response->json();
                $token     = $data['access_token'] ?? null;
                $expiresIn = $data['expires_in'] ?? 3600;

                if ($token) {
                    $ttl = max(300, $expiresIn - 300);
                    Cache::put($this->tokenCacheKey, $token, now()->addSeconds($ttl));

                    Log::info('YoSmart: token obtained', [
                        'credential_id' => $this->credentialId,
                        'expires_in'    => $expiresIn,
                    ]);

                    return $token;
                }
            }

            Log::error('YoSmart: token response error', [
                'store_id' => $this->storeId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('YoSmart: token fetch exception', [
                'store_id' => $this->storeId,
                'error'    => $e->getMessage(),
            ]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Core API call
    // -------------------------------------------------------------------------

    /**
     * Execute a YoSmart API call. Retries once with a fresh token on auth errors.
     */
    public function callApi(
        string $method,
        array $params = [],
        ?string $url = null,
        bool $isRetry = false,
    ): ?array {
        $token = $this->getAccessToken();

        if (!$token) {
            Log::error('YoSmart: no access token available', ['store_id' => $this->storeId]);
            return null;
        }

        $url     = $url ?? self::API_URL;
        $payload = array_merge(
            ['method' => $method, 'time' => intval(microtime(true) * 1000)],
            $params,
        );

        try {
            Log::debug('YoSmart: calling API', [
                'store_id' => $this->storeId,
                'method'   => $method,
                'isRetry'  => $isRetry,
            ]);

            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => "Bearer {$token}",
            ])->timeout(10)->post($url, $payload);

            if ($response->successful()) {
                $result = $response->json();
                $code   = $result['code'] ?? null;

                if ($code === '000000') {
                    Log::debug('YoSmart: API call successful', [
                        'store_id' => $this->storeId,
                        'method'   => $method,
                    ]);
                    return $result;
                }

                if (!$isRetry && in_array($code, self::TOKEN_ERROR_CODES, true)) {
                    Log::warning('YoSmart: token error, refreshing', [
                        'store_id' => $this->storeId,
                        'method'   => $method,
                        'code'     => $code,
                    ]);
                    $newToken = $this->refreshAccessToken();
                    if ($newToken) {
                        return $this->callApi($method, $params, $url, true);
                    }
                }

                Log::warning('YoSmart: API error response', [
                    'store_id' => $this->storeId,
                    'method'   => $method,
                    'code'     => $code,
                    'desc'     => $result['desc'] ?? 'No description',
                ]);

                return $result;
            }

            Log::error('YoSmart: HTTP error', [
                'store_id' => $this->storeId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('YoSmart: callApi exception', [
                'store_id' => $this->storeId,
                'method'   => $method,
                'error'    => $e->getMessage(),
            ]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // High-level helpers
    // -------------------------------------------------------------------------

    public function listDevices(): ?array
    {
        $result = $this->callApi('Home.getDeviceList');

        if ($result && ($result['code'] ?? null) === '000000') {
            $devices = $result['data']['devices'] ?? [];
            Cache::put($this->devicesCacheKey, $devices, self::CACHE_TTL);
            return $devices;
        }

        return null;
    }

    public function homeInfo(): ?array
    {
        $result = $this->callApi('Home.getGeneralInfo');

        return ($result && ($result['code'] ?? null) === '000000')
            ? ($result['data'] ?? [])
            : null;
    }

    public function resolveGetStateMethod(string $deviceType): string
    {
        if (isset(self::DEVICE_TYPE_MAP[$deviceType])) {
            return self::DEVICE_TYPE_MAP[$deviceType] . '.getState';
        }

        foreach (self::DEVICE_TYPE_MAP as $key => $prefix) {
            if (strcasecmp($key, $deviceType) === 0) {
                return $prefix . '.getState';
            }
        }

        Log::warning("YoSmart: unknown device type '{$deviceType}', using as-is");
        return $deviceType . '.getState';
    }

    public function getErrorHint(string $errorCode): string
    {
        $hints = [
            '000101' => 'Cannot connect to Hub. Ensure the Hub is powered on and connected to the network.',
            '000102' => 'Hub cannot respond to this command. The Hub may be busy or the command is unsupported.',
            '000103' => 'Token is invalid. The device list may be stale or the wrong API method is being used for this device type.',
            '000104' => 'Hub token is invalid. Try refreshing the device list to get updated tokens.',
            '000201' => 'Cannot connect to the device. Check that the device is powered on and in range of the Hub.',
            '000202' => 'Device cannot respond to this command. The device may not support this operation.',
            '010104' => 'Access token expired. The app will automatically refresh it on the next request.',
            '010203' => 'Method is not supported for this device type. The device type mapping may need updating.',
            '020101' => 'Device does not exist. Refresh the device list to verify.',
        ];

        return $hints[$errorCode] ?? "Error code {$errorCode}. See YoSmart API error codes documentation.";
    }

    // -------------------------------------------------------------------------
    // Accessors (for health checks etc.)
    // -------------------------------------------------------------------------

    /**
     * Fetch states for multiple devices concurrently using HTTP pool.
     *
     * @param  array  $devices  Array of devices from Home.getDeviceList
     * @return array  Array of state results keyed by deviceId
     */
    public function batchGetStates(array $devices): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        // Build requests grouped by device
        $deviceMap = [];
        foreach ($devices as $device) {
            $deviceId   = $device['deviceId'];
            $deviceType = $device['type'] ?? 'unknown';
            $devToken   = $device['token'] ?? null;

            if (!$devToken) {
                $deviceMap[$deviceId] = [
                    'deviceId'   => $deviceId,
                    'deviceType' => $deviceType,
                    'name'       => $device['name'] ?? '',
                    'modelName'  => $device['modelName'] ?? '',
                    'success'    => false,
                    'error'      => 'No token available',
                ];
                continue;
            }

            $deviceMap[$deviceId] = [
                'device'   => $device,
                'method'   => $this->resolveGetStateMethod($deviceType),
                'devToken' => $devToken,
            ];
        }

        // Separate devices that need API calls from those that already have results
        $toFetch = array_filter($deviceMap, fn($d) => isset($d['method']));
        $results = array_filter($deviceMap, fn($d) => !isset($d['method']));

        if (empty($toFetch)) {
            return array_values($results);
        }

        // Use HTTP pool for concurrent requests
        $responses = Http::pool(function ($pool) use ($toFetch, $token) {
            foreach ($toFetch as $deviceId => $info) {
                $pool->as($deviceId)
                    ->withHeaders([
                        'Content-Type'  => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ])
                    ->timeout(10)
                    ->post(self::API_URL, [
                        'method'       => $info['method'],
                        'time'         => intval(microtime(true) * 1000),
                        'targetDevice' => $deviceId,
                        'token'        => $info['devToken'],
                    ]);
            }
        });

        // Process responses
        foreach ($toFetch as $deviceId => $info) {
            $device     = $info['device'];
            $deviceType = $device['type'] ?? 'unknown';
            $response   = $responses[$deviceId] ?? null;

            if ($response && $response->successful()) {
                $result = $response->json();
                if (($result['code'] ?? null) === '000000') {
                    $results[$deviceId] = [
                        'deviceId'   => $deviceId,
                        'deviceType' => $deviceType,
                        'name'       => $device['name'] ?? '',
                        'modelName'  => $device['modelName'] ?? '',
                        'success'    => true,
                        'state'      => $result['data'] ?? null,
                        'method'     => $info['method'],
                    ];
                } else {
                    $results[$deviceId] = [
                        'deviceId'   => $deviceId,
                        'deviceType' => $deviceType,
                        'name'       => $device['name'] ?? '',
                        'modelName'  => $device['modelName'] ?? '',
                        'success'    => false,
                        'error'      => $result['desc'] ?? 'Unknown error',
                        'code'       => $result['code'] ?? 'unknown',
                        'method'     => $info['method'],
                    ];
                }
            } else {
                $results[$deviceId] = [
                    'deviceId'   => $deviceId,
                    'deviceType' => $deviceType,
                    'name'       => $device['name'] ?? '',
                    'modelName'  => $device['modelName'] ?? '',
                    'success'    => false,
                    'error'      => 'HTTP request failed',
                ];
            }
        }

        return array_values($results);
    }

    public function hasCredentials(): bool
    {
        return !empty($this->uaid) && !empty($this->secret);
    }

    public function getUaid(): string
    {
        return $this->uaid;
    }
}
