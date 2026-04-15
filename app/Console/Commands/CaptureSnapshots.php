<?php

namespace App\Console\Commands;

use App\Models\SensorReport;
use App\Models\YoSmartCredential;
use App\Services\YoSmartService;
use App\Models\SnapshotSchedule;
use App\Models\Store;
use App\Models\StoreDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CaptureSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --force : Ignore schedule timing and run immediately
     */
    protected $signature = 'snapshots:capture
                            {--force : Ignore schedule timing and run immediately}';

    protected $description = 'Capture sensor snapshots for ALL active credentials (global schedule)';

    public function handle(): int
    {
        $schedule = SnapshotSchedule::global();

        if (! $schedule->is_active && ! $this->option('force')) {
            $this->line('Global snapshot schedule is inactive — skipping.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $schedule->isDue()) {
            $this->line("Next run scheduled for {$schedule->next_run_at}");
            return self::SUCCESS;
        }

        $this->info('📡 Running global snapshot for all active credentials…');

        $credentials = YoSmartCredential::where('is_active', true)->get();

        if ($credentials->isEmpty()) {
            $this->warn('No active credentials found.');
            $schedule->markRanSuccessfully();
            return self::SUCCESS;
        }

        $totalCaptured = 0;
        $failed        = 0;
        $errors        = [];

        foreach ($credentials as $credential) {
            $this->line("  → Credential: {$credential->uaid}");

            try {
                $count = $this->captureForCredential($credential);
                $totalCaptured += $count;
                $this->info("    ✅ {$count} device(s)");
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$credential->uaid}: {$e->getMessage()}";
                $this->error("    ❌ {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done — {$totalCaptured} total device(s) captured across {$credentials->count()} credential(s).");

        if ($failed > 0) {
            $schedule->markFailed(implode('; ', $errors));
            return self::FAILURE;
        }

        $schedule->markRanSuccessfully();
        return self::SUCCESS;
    }

    private function captureForCredential(YoSmartCredential $credential): int
    {
        if (! $credential->hasCredentials()) {
            throw new \RuntimeException("Credential has incomplete configuration.");
        }

        $service = new YoSmartService(
            uaid:         $credential->uaid,
            secret:       $credential->secret,
            credentialId: $credential->id,
        );

        // Only capture for devices that have a store assigned (non-hub, linked)
        $devices = StoreDevice::where('credential_id', $credential->id)
            ->whereNotNull('store_id')
            ->get();

        $count = 0;

        foreach ($devices as $device) {
            /** @var StoreDevice $device */
            $method = $device->device_type . '.getState';

            $result = $service->callApi($method, [
                'targetDevice' => $device->device_id,
                'token'        => $device->device_token,
            ]);

            $success = $result && ($result['code'] ?? null) === '000000';
            $data    = $result['data'] ?? [];
            $state   = $data['state'] ?? [];

            SensorReport::create([
                'store_id'         => $device->store_id,
                'store_device_id'  => $device->id,
                'device_id'        => $device->device_id,
                'device_type'      => $device->device_type,
                'device_name'      => $device->device_name,
                'online'           => $data['online'] ?? false,
                'temperature'      => $state['temperature'] ?? $state['temp'] ?? null,
                'temperature_unit' => 'c',
                'humidity'         => $state['humidity'] ?? null,
                'battery_level'    => $state['battery'] ?? null,
                'alarm'            => self::parseAlarm($state['alarm'] ?? false),
                'state'            => is_array($state) ? ($state['state'] ?? null) : $state,
                'raw_state'        => $success ? $data : ['error' => $result['desc'] ?? 'unknown'],
                'reported_at'      => isset($data['reportAt']) ? Carbon::parse($data['reportAt']) : null,
                'recorded_at'      => now(),
            ]);

            $count++;
        }

        return $count;
    }

    private static function parseAlarm(mixed $alarm): bool
    {
        if (is_bool($alarm)) {
            return $alarm;
        }

        if (is_array($alarm)) {
            foreach ($alarm as $key => $val) {
                if ($key === 'code') {
                    continue;
                }
                if ($val === true) {
                    return true;
                }
            }

            return false;
        }

        return (bool) $alarm;
    }
}
