<?php

namespace App\Console\Commands;

use App\Models\YoSmartCredential;
use App\Services\YoSmartDeviceSync;
use Illuminate\Console\Command;

class SyncYoSmartDevices extends Command
{
    protected $signature = 'yosmart:sync
                            {credential? : Optional credential id to sync (default: all active)}';

    protected $description = 'Re-sync YoLink devices for active credentials (backfills hub linkage / parent_device_id)';

    public function handle(YoSmartDeviceSync $sync): int
    {
        $query = YoSmartCredential::where('is_active', true);

        if ($id = $this->argument('credential')) {
            $query->whereKey($id);
        }

        $credentials = $query->get();

        if ($credentials->isEmpty()) {
            $this->warn('No active credentials to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($credentials as $credential) {
            $result = $sync->sync($credential);

            if (! $result['ok']) {
                $failed++;
                $this->error("Credential #{$credential->id} ({$credential->uaid}): {$result['message']}");

                continue;
            }

            $this->info(sprintf(
                'Credential #%d (%s): synced %d, linked %d, unmatched %d, removed %d',
                $credential->id,
                $credential->uaid,
                $result['synced'],
                $result['linked'],
                $result['unmatched'],
                $result['removed'],
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
