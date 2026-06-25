<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single global row that controls how often snapshots are captured
 * for ALL active stores at once.
 */
class SnapshotSchedule extends Model
{
    protected $fillable = [
        'is_active',
        'interval_minutes',
        'last_run_at',
        'next_run_at',
        'total_runs',
        'consecutive_failures',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'interval_minutes' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'total_runs' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    // ── Singleton helper ──────────────────────────────────────────

    /**
     * Return the single global schedule record, creating it if absent.
     */
    public static function global(): static
    {
        return static::firstOrCreate([], [
            'is_active' => false,
            'interval_minutes' => 10,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isDue(): bool
    {
        return $this->is_active
            && ($this->next_run_at === null || $this->next_run_at->lte(now()));
    }

    public function markRanSuccessfully(): void
    {
        $this->update([
            'last_run_at' => now(),
            'next_run_at' => now()->addMinutes($this->interval_minutes),
            'total_runs' => $this->total_runs + 1,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'last_run_at' => now(),
            'next_run_at' => now()->addMinutes($this->interval_minutes),
            'total_runs' => $this->total_runs + 1,
            'consecutive_failures' => $this->consecutive_failures + 1,
            'last_error' => $error,
        ]);
    }
}
