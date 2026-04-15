<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_number',
        'store_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * All devices linked to this store (hub + sensors).
     */
    public function devices(): HasMany
    {
        return $this->hasMany(StoreDevice::class);
    }

    /**
     * Only the hub device(s) for this store.
     */
    public function hubs(): HasMany
    {
        return $this->hasMany(StoreDevice::class)->where('is_hub', true);
    }

    /**
     * Only the sensor devices (non-hub) for this store.
     */
    public function sensors(): HasMany
    {
        return $this->hasMany(StoreDevice::class)->where('is_hub', false);
    }
}
