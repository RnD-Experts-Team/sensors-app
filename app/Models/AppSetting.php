<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key, with optional default.
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        return Cache::remember("app_setting:{$key}", 60, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting?->value ?? $default;
        });
    }

    /**
     * Set a setting value by key (creates or updates).
     */
    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        Cache::forget("app_setting:{$key}");
    }

    /**
     * Get the global temperature display unit ('F' or 'C').
     */
    public static function temperatureUnit(): string
    {
        return strtoupper(static::getValue('temperature_unit', 'C'));
    }

    /**
     * Convert a temperature value from one unit to the target unit.
     */
    public static function convertTemp(?float $value, ?string $fromUnit, string $toUnit): ?float
    {
        if ($value === null) {
            return null;
        }

        $from = strtoupper($fromUnit ?? 'C');
        $to   = strtoupper($toUnit);

        if ($from === $to) {
            return round($value, 2);
        }

        if ($to === 'F') {
            return round($value * 9 / 5 + 32, 2);
        }

        if ($to === 'C') {
            return round(($value - 32) * 5 / 9, 2);
        }

        return round($value, 2);
    }

    /**
     * Return a raw SQL expression that converts the temperature column
     * to the target unit based on each row's temperature_unit value.
     *
     * Use this inside DB::raw() for aggregate queries (AVG, MIN, MAX).
     */
    public static function tempSqlExpr(string $targetUnit, string $column = 'temperature'): string
    {
        $target = strtoupper($targetUnit);

        if ($target === 'F') {
            // YoSmart API always returns Celsius; only skip conversion when unit is explicitly 'f'
            return "CASE WHEN LOWER(temperature_unit) = 'f' THEN {$column} ELSE ({$column} * 9.0 / 5.0 + 32) END";
        }

        if ($target === 'C') {
            // Default (null or 'c') is already Celsius; only convert when explicitly 'f'
            return "CASE WHEN LOWER(temperature_unit) = 'c' THEN {$column} ELSE (({$column} - 32) * 5.0 / 9.0) END";
        }

        return $column;
    }
}
