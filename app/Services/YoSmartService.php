<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoSmartService
{
    private const TOKEN_URL = 'https://api.yosmart.com/open/yolink/token';

    private const API_URL = 'https://api.yosmart.com/open/yolink/v2/api';

    private const CACHE_TTL = 3600;

    /** TTL (seconds) for cached live device state — Layer 1 rate-limit guard. */
    private const STATE_CACHE_TTL = 60;

    /** How long (seconds) to stop calling YoSmart after a rate-limit (010301). */
    private const COOLDOWN_TTL = 60;

    /** YoSmart error code: "Access denied due to limits reached, please retry later". */
    public const RATE_LIMIT_CODE = '010301';

    /**
     * Default number of distinct hubs fetched in parallel per pool round.
     * YoLink limits a UAC to ~5 concurrent connections, so we cap at 5.
     */
    private const STATE_CHUNK_SIZE = 5;

    /**
     * Default max concurrent getState requests aimed at a SINGLE hub per round.
     * Sensors relay through their hub's radio, so keep this low (1) to avoid
     * 000201/020104 "device busy" errors; parallelism comes from many hubs.
     */
    private const PER_HUB_CONCURRENCY = 1;

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
        'Hub' => 'Hub',
        'CellularHub' => 'CellularHub',
        'SpeakerHub' => 'SpeakerHub',
        'THSensor' => 'THSensor',
        'DoorSensor' => 'DoorSensor',
        'MotionSensor' => 'MotionSensor',
        'LeakSensor' => 'LeakSensor',
        'VibrationSensor' => 'VibrationSensor',
        'COSmokeSensor' => 'COSmokeSensor',
        'SmartRemoter' => 'SmartRemoter',
        'InfraredRemoter' => 'InfraredRemoter',
        'Outlet' => 'Outlet',
        'MultiOutlet' => 'MultiOutlet',
        'Switch' => 'Switch',
        'Dimmer' => 'Dimmer',
        'Lock' => 'Lock',
        'LockV2' => 'LockV2',
        'GarageDoor' => 'GarageDoor',
        'Finger' => 'Finger',
        'Siren' => 'Siren',
        'Manipulator' => 'Manipulator',
        'Sprinkler' => 'Sprinkler',
        'SprinklerV2' => 'SprinklerV2',
        'Thermostat' => 'Thermostat',
        'IPCamera' => 'IPCamera',
        'PowerFailureAlarm' => 'PowerFailureAlarm',
        'SoilThcSensor' => 'SoilThcSensor',
        'WaterDepthSensor' => 'WaterDepthSensor',
        'WaterMeterController' => 'WaterMeterController',
        'CSDevice' => 'CSDevice',
    ];

    private string $tokenCacheKey;

    private string $devicesCacheKey;

    public function __construct(
        private readonly string $uaid,
        private readonly string $secret,
        private readonly int $credentialId,
    ) {
        $this->tokenCacheKey = "yosmart_access_token_{$credentialId}";
        $this->devicesCacheKey = "yosmart_devices_{$credentialId}";
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    public function getAccessToken(): ?string
    {
        $token = Cache::get($this->tokenCacheKey);

        if ($token) {
            Log::debug('YoSmart: using cached token', ['credential_id' => $this->credentialId]);

            return $token;
        }

        return $this->fetchAccessToken();
    }

    public function refreshAccessToken(): ?string
    {
        Log::info('YoSmart: force-refreshing token', ['credential_id' => $this->credentialId]);
        Cache::forget($this->tokenCacheKey);

        return $this->fetchAccessToken();
    }

    private function fetchAccessToken(): ?string
    {
        try {
            Log::info('YoSmart: fetching new access token', ['credential_id' => $this->credentialId]);

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->uaid,
                'client_secret' => $this->secret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'] ?? null;
                $expiresIn = $data['expires_in'] ?? 3600;

                if ($token) {
                    $ttl = max(300, $expiresIn - 300);
                    Cache::put($this->tokenCacheKey, $token, now()->addSeconds($ttl));

                    Log::info('YoSmart: token obtained', [
                        'credential_id' => $this->credentialId,
                        'expires_in' => $expiresIn,
                    ]);

                    return $token;
                }
            }

            Log::error('YoSmart: token response error', [
                'credential_id' => $this->credentialId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('YoSmart: token fetch exception', [
                'credential_id' => $this->credentialId,
                'error' => $e->getMessage(),
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

        if (! $token) {
            Log::error('YoSmart: no access token available', ['credential_id' => $this->credentialId]);

            return null;
        }

        $url = $url ?? self::API_URL;
        $payload = array_merge(
            ['method' => $method, 'time' => intval(microtime(true) * 1000)],
            $params,
        );

        try {
            Log::debug('YoSmart: calling API', [
                'credential_id' => $this->credentialId,
                'method' => $method,
                'isRetry' => $isRetry,
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$token}",
            ])->timeout(10)->post($url, $payload);

            if ($response->successful()) {
                $result = $response->json();
                $code = $result['code'] ?? null;

                if ($code === '000000') {
                    Log::debug('YoSmart: API call successful', [
                        'credential_id' => $this->credentialId,
                        'method' => $method,
                    ]);

                    return $result;
                }

                if (! $isRetry && in_array($code, self::TOKEN_ERROR_CODES, true)) {
                    Log::warning('YoSmart: token error, refreshing', [
                        'credential_id' => $this->credentialId,
                        'method' => $method,
                        'code' => $code,
                    ]);
                    $newToken = $this->refreshAccessToken();
                    if ($newToken) {
                        return $this->callApi($method, $params, $url, true);
                    }
                }

                Log::warning('YoSmart: API error response', [
                    'credential_id' => $this->credentialId,
                    'method' => $method,
                    'code' => $code,
                    'desc' => $result['desc'] ?? 'No description',
                ]);

                return $result;
            }

            Log::error('YoSmart: HTTP error', [
                'credential_id' => $this->credentialId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('YoSmart: callApi exception', [
                'credential_id' => $this->credentialId,
                'method' => $method,
                'error' => $e->getMessage(),
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

    /**
     * Read live state for many devices belonging to THIS credential.
     *
     * Live-first with the cache as a fallback:
     *  - Chunked concurrency: every device is fetched fresh from YoLink in
     *    chunks of `state_chunk_size` concurrent requests (YoLink caps a UAC at
     *    ~5 connections), instead of one slow sequential loop or one unbounded
     *    burst that would itself trip the limit.
     *  - Cache fallback: when a device's live read fails (rate-limited, errored,
     *    or skipped during a cooldown), the most recent cached live reading
     *    (≤ STATE_CACHE_TTL) is served instead, flagged stale.
     *  - Cooldown: the moment YoLink replies 010301 a short cooldown is opened;
     *    the rest of this batch — and any request during the cooldown — skips
     *    YoLink and uses the fallback.
     *
     * @param  array<int, array{device_type: string, device_id: string, device_token: string}>  $devices
     * @return array<string, array{ok: bool, source: string, stale: bool, code: ?string, data: ?array, desc: ?string}>
     *                                                                                                                 keyed by device_id; source ∈ live|cache|cooldown|rate_limited|error
     */
    public function getDeviceStates(array $devices, ?int $chunkSize = null): array
    {
        $maxHubsPerRound = max(1, $chunkSize ?? (int) config('services.yosmart.state_chunk_size', self::STATE_CHUNK_SIZE));
        $perHubConcurrency = max(1, (int) config('services.yosmart.per_hub_concurrency', self::PER_HUB_CONCURRENCY));
        $delayMs = max(0, (int) config('services.yosmart.chunk_delay_ms', 0));
        $cooldownKey = "yosmart_cooldown_{$this->credentialId}";

        // hub_key groups a sensor with its hub; default to the device's own id
        // so hubs and orphan/un-synced sensors each form their own group.
        foreach ($devices as &$device) {
            $device['hub_key'] = ($device['hub_key'] ?? null) ?: $device['device_id'];
        }
        unset($device);

        $results = [];

        // While cooling down after a recent rate limit, don't call YoLink at
        // all — go straight to the fallback (cache, then last report).
        if (Cache::get($cooldownKey)) {
            foreach ($devices as $device) {
                $results[$device['device_id']] = $this->fallbackState($device['device_id'], 'cooldown');
            }

            return $results;
        }

        $token = $this->getAccessToken();
        if (! $token) {
            foreach ($devices as $device) {
                $results[$device['device_id']] = $this->fallbackState($device['device_id'], 'error');
            }

            return $results;
        }

        // Live-first: fetch devices in hub-interleaved rounds (≤ perHubConcurrency
        // per hub per round, ≤ maxHubsPerRound distinct hubs in parallel). The
        // cache is only consulted as a fallback (see resolveChunkResponse).
        foreach ($this->buildHubRounds($devices, $maxHubsPerRound, $perHubConcurrency) as $index => $chunk) {
            // A previous chunk tripped the limit — stop calling; use fallbacks.
            if (Cache::get($cooldownKey)) {
                foreach ($chunk as $device) {
                    $results[$device['device_id']] = $this->fallbackState($device['device_id'], 'cooldown');
                }

                continue;
            }

            if ($index > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $keyed = [];
            foreach ($chunk as $device) {
                $keyed[$device['device_id']] = $device;
            }

            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $device, string $deviceId) => $pool
                    ->as($deviceId)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ])
                    ->timeout(10)
                    ->post(self::API_URL, [
                        'method' => $this->resolveGetStateMethod($device['device_type']),
                        'time' => intval(microtime(true) * 1000),
                        'targetDevice' => $deviceId,
                        'token' => $device['device_token'],
                    ]),
                array_values($keyed),
                array_keys($keyed),
            ));

            foreach ($keyed as $deviceId => $device) {
                $results[$deviceId] = $this->resolveChunkResponse($responses[$deviceId] ?? null, $deviceId, $cooldownKey);
            }
        }

        return $results;
    }

    /**
     * Split a device list into hub-interleaved pool rounds. Each inner array is
     * one Http::pool batch with these invariants:
     *   - at most $perHubConcurrency devices from any single hub_key;
     *   - at most $maxPerRound requests total (the UAC connection cap);
     *   - every input device appears exactly once across all batches.
     *
     * Parallelism comes from spreading a batch across many hubs rather than
     * piling requests onto one hub's radio relay.
     *
     * @param  array<int, array<string, mixed>>  $devices  each carrying a 'hub_key'
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function buildHubRounds(array $devices, int $maxPerRound, int $perHubConcurrency): array
    {
        // Group by hub, preserving input order within each hub.
        $groups = [];
        foreach ($devices as $device) {
            $groups[$device['hub_key']][] = $device;
        }

        $rounds = [];

        while ($groups !== []) {
            $batch = [];
            $perHub = [];

            // Round-robin across hubs, taking up to $perHubConcurrency from each,
            // until the batch reaches the per-round request cap.
            do {
                $progressed = false;

                foreach ($groups as $hubKey => $queue) {
                    if (count($batch) >= $maxPerRound) {
                        break;
                    }
                    if ($queue === [] || ($perHub[$hubKey] ?? 0) >= $perHubConcurrency) {
                        continue;
                    }

                    $batch[] = array_shift($groups[$hubKey]);
                    $perHub[$hubKey] = ($perHub[$hubKey] ?? 0) + 1;
                    $progressed = true;
                }
            } while ($progressed && count($batch) < $maxPerRound);

            $rounds[] = $batch;
            $groups = array_filter($groups, fn ($queue) => $queue !== []);
        }

        return $rounds;
    }

    /**
     * Map a single pooled response into a structured state result. A successful
     * read is cached and returned as fresh `live` data; any failure falls back
     * to the cached reading (and opens the cooldown on a rate-limit reply).
     */
    private function resolveChunkResponse(mixed $response, string $deviceId, string $cooldownKey): array
    {
        $result = ($response instanceof Response && $response->successful())
            ? $response->json()
            : null;

        $code = is_array($result) ? ($result['code'] ?? null) : null;

        if ($code === '000000') {
            Cache::put("yosmart_state_{$this->credentialId}_{$deviceId}", $result, self::STATE_CACHE_TTL);

            return $this->stateResult(true, 'live', $result);
        }

        if ($code === self::RATE_LIMIT_CODE) {
            Cache::put($cooldownKey, true, self::COOLDOWN_TTL);
            Log::warning('YoSmart: rate limit reached, cooldown engaged', [
                'credential_id' => $this->credentialId,
                'device_id' => $deviceId,
                'cooldown_secs' => self::COOLDOWN_TTL,
            ]);

            return $this->fallbackState($deviceId, 'rate_limited', $result);
        }

        return $this->fallbackState($deviceId, 'error', is_array($result) ? $result : null);
    }

    /**
     * Fallback for a device whose live read failed (or was skipped during a
     * cooldown): serve the most recent cached live reading if we have one,
     * marked stale. Otherwise signal the caller (ok=false) so it can fall back
     * to its own source (the last persisted report).
     */
    private function fallbackState(string $deviceId, string $source, ?array $result = null): array
    {
        $cached = Cache::get("yosmart_state_{$this->credentialId}_{$deviceId}");

        if (is_array($cached)) {
            return $this->stateResult(true, 'cache', $cached, true);
        }

        return $this->stateResult(false, $source, $result, true);
    }

    /**
     * @return array{ok: bool, source: string, stale: bool, code: ?string, data: ?array, desc: ?string}
     */
    private function stateResult(bool $ok, string $source, ?array $result, bool $stale = false): array
    {
        return [
            'ok' => $ok,
            'source' => $source,
            'stale' => $stale,
            'code' => $result['code'] ?? null,
            'data' => $result['data'] ?? null,
            'desc' => $result['desc'] ?? null,
        ];
    }

    public function resolveGetStateMethod(string $deviceType): string
    {
        if (isset(self::DEVICE_TYPE_MAP[$deviceType])) {
            return self::DEVICE_TYPE_MAP[$deviceType].'.getState';
        }

        foreach (self::DEVICE_TYPE_MAP as $key => $prefix) {
            if (strcasecmp($key, $deviceType) === 0) {
                return $prefix.'.getState';
            }
        }

        Log::warning("YoSmart: unknown device type '{$deviceType}', using as-is");

        return $deviceType.'.getState';
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
     * @return array Array of state results keyed by deviceId
     */
    public function batchGetStates(array $devices): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return [];
        }

        // Build requests grouped by device
        $deviceMap = [];
        foreach ($devices as $device) {
            $deviceId = $device['deviceId'];
            $deviceType = $device['type'] ?? 'unknown';
            $devToken = $device['token'] ?? null;

            if (! $devToken) {
                $deviceMap[$deviceId] = [
                    'deviceId' => $deviceId,
                    'deviceType' => $deviceType,
                    'name' => $device['name'] ?? '',
                    'modelName' => $device['modelName'] ?? '',
                    'success' => false,
                    'error' => 'No token available',
                ];

                continue;
            }

            $deviceMap[$deviceId] = [
                'device' => $device,
                'method' => $this->resolveGetStateMethod($deviceType),
                'devToken' => $devToken,
            ];
        }

        // Separate devices that need API calls from those that already have results
        $toFetch = array_filter($deviceMap, fn ($d) => isset($d['method']));
        $results = array_filter($deviceMap, fn ($d) => ! isset($d['method']));

        if (empty($toFetch)) {
            return array_values($results);
        }

        // Use HTTP pool for concurrent requests
        $responses = Http::pool(function ($pool) use ($toFetch, $token) {
            foreach ($toFetch as $deviceId => $info) {
                $pool->as($deviceId)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ])
                    ->timeout(10)
                    ->post(self::API_URL, [
                        'method' => $info['method'],
                        'time' => intval(microtime(true) * 1000),
                        'targetDevice' => $deviceId,
                        'token' => $info['devToken'],
                    ]);
            }
        });

        // Process responses
        foreach ($toFetch as $deviceId => $info) {
            $device = $info['device'];
            $deviceType = $device['type'] ?? 'unknown';
            $response = $responses[$deviceId] ?? null;

            if ($response && $response->successful()) {
                $result = $response->json();
                if (($result['code'] ?? null) === '000000') {
                    $results[$deviceId] = [
                        'deviceId' => $deviceId,
                        'deviceType' => $deviceType,
                        'name' => $device['name'] ?? '',
                        'modelName' => $device['modelName'] ?? '',
                        'success' => true,
                        'state' => $result['data'] ?? null,
                        'method' => $info['method'],
                    ];
                } else {
                    $results[$deviceId] = [
                        'deviceId' => $deviceId,
                        'deviceType' => $deviceType,
                        'name' => $device['name'] ?? '',
                        'modelName' => $device['modelName'] ?? '',
                        'success' => false,
                        'error' => $result['desc'] ?? 'Unknown error',
                        'code' => $result['code'] ?? 'unknown',
                        'method' => $info['method'],
                    ];
                }
            } else {
                $results[$deviceId] = [
                    'deviceId' => $deviceId,
                    'deviceType' => $deviceType,
                    'name' => $device['name'] ?? '',
                    'modelName' => $device['modelName'] ?? '',
                    'success' => false,
                    'error' => 'HTTP request failed',
                ];
            }
        }

        return array_values($results);
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->uaid) && ! empty($this->secret);
    }

    public function getUaid(): string
    {
        return $this->uaid;
    }
}
