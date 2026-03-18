<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * YoSmart API always returns temperature values in Celsius.
     *
     * Historical records were stored with temperature_unit = 'f' or NULL
     * because the device's display `mode` field ('f' = sensor LED shows °F)
     * was incorrectly used as the API response unit. All stored temperature
     * values are therefore raw Celsius — fix the unit column to reflect that.
     */
    public function up(): void
    {
        DB::table('sensor_reports')
            ->whereNull('temperature_unit')
            ->orWhereRaw("LOWER(temperature_unit) = 'f'")
            ->update(['temperature_unit' => 'c']);
    }

    public function down(): void
    {
        // The original values were incorrect, so this is intentionally a no-op.
    }
};
