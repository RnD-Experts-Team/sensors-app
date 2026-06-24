<?php

namespace Tests\Unit;

use App\Services\YoSmartService;
use PHPUnit\Framework\TestCase;

class YoSmartHubChunkingTest extends TestCase
{
    /**
     * Exposes the protected buildHubRounds() for direct assertion.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function rounds(array $devices, int $maxPerRound, int $perHubConcurrency): array
    {
        $service = new class('ua', 'sec', 1) extends YoSmartService
        {
            public function exposeRounds(array $devices, int $maxPerRound, int $perHubConcurrency): array
            {
                return $this->buildHubRounds($devices, $maxPerRound, $perHubConcurrency);
            }
        };

        return $service->exposeRounds($devices, $maxPerRound, $perHubConcurrency);
    }

    private function dev(string $id, string $hubKey): array
    {
        return ['device_id' => $id, 'hub_key' => $hubKey, 'device_type' => 'THSensor', 'device_token' => 't'];
    }

    /** @param array<int, array<string, mixed>> $devices */
    private function assertInvariants(array $rounds, array $devices, int $maxPerRound, int $perHubConcurrency): void
    {
        foreach ($rounds as $batch) {
            $hubKeys = array_column($batch, 'hub_key');
            $counts = array_count_values($hubKeys);

            $this->assertLessThanOrEqual($maxPerRound, count($batch), 'batch exceeded the per-round request cap');
            $this->assertLessThanOrEqual($perHubConcurrency, max($counts), 'batch exceeded per-hub concurrency');
        }

        // Every input device appears exactly once across all batches.
        $flatIds = array_column(array_merge(...$rounds ?: [[]]), 'device_id');
        sort($flatIds);
        $inputIds = array_column($devices, 'device_id');
        sort($inputIds);
        $this->assertSame($inputIds, $flatIds, 'rounds must cover every device exactly once');
    }

    public function test_two_hubs_never_mix_same_hub_in_a_batch_and_interleave(): void
    {
        $devices = [
            $this->dev('a1', 'H1'), $this->dev('a2', 'H1'), $this->dev('a3', 'H1'),
            $this->dev('b1', 'H2'), $this->dev('b2', 'H2'), $this->dev('b3', 'H2'),
        ];

        $rounds = $this->rounds($devices, maxPerRound: 5, perHubConcurrency: 1);

        $this->assertInvariants($rounds, $devices, 5, 1);
        // 3 rounds, each pairing one device from each hub.
        $this->assertCount(3, $rounds);
        foreach ($rounds as $batch) {
            $this->assertCount(2, $batch);
            $this->assertSame(['H1', 'H2'], array_column($batch, 'hub_key'));
        }
    }

    public function test_distinct_hubs_capped_at_max_per_round(): void
    {
        $devices = array_map(fn ($n) => $this->dev("d$n", "H$n"), range(1, 6));

        $rounds = $this->rounds($devices, maxPerRound: 5, perHubConcurrency: 1);

        $this->assertInvariants($rounds, $devices, 5, 1);
        $this->assertCount(2, $rounds);
        $this->assertCount(5, $rounds[0]);
        $this->assertCount(1, $rounds[1]);
    }

    public function test_single_hub_is_serialized_one_device_per_round(): void
    {
        $devices = array_map(fn ($n) => $this->dev("d$n", 'H1'), range(1, 6));

        $rounds = $this->rounds($devices, maxPerRound: 5, perHubConcurrency: 1);

        $this->assertInvariants($rounds, $devices, 5, 1);
        $this->assertCount(6, $rounds);
        foreach ($rounds as $batch) {
            $this->assertCount(1, $batch);
        }
    }

    public function test_per_hub_concurrency_two_allows_two_same_hub_per_batch(): void
    {
        $devices = array_map(fn ($n) => $this->dev("d$n", 'H1'), range(1, 6));

        $rounds = $this->rounds($devices, maxPerRound: 5, perHubConcurrency: 2);

        $this->assertInvariants($rounds, $devices, 5, 2);
        $this->assertCount(3, $rounds);
        foreach ($rounds as $batch) {
            $this->assertCount(2, $batch);
        }
    }

    public function test_empty_device_list_yields_no_rounds(): void
    {
        $this->assertSame([], $this->rounds([], 5, 1));
    }
}
