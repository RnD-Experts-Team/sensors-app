<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'credential_id',
        'store_id',
        'device_id',
        'device_token',
        'device_type',
        'device_name',
        'model_name',
        'is_hub',
        'parsed_store_number',
    ];

    protected function casts(): array
    {
        return [
            'is_hub' => 'boolean',
        ];
    }

    /**
     * The credential this device was discovered from.
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(YoSmartCredential::class, 'credential_id');
    }

    /**
     * The store this device belongs to (nullable — unmatched devices have no store).
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Parse the store number from the device name.
     * Looks for a 5-5 digit pattern (e.g. 03795-00038) anywhere in the name.
     * Examples: "freezer 03795-00038" → "03795-00038", "hub" → null
     */
    public static function parseStoreNumber(string $deviceName): ?string
    {
        if (preg_match('/(\d{5}-\d{5})/', $deviceName, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
