<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YoSmartCredential extends Model
{
    protected $table = 'yosmart_credentials';

    protected $fillable = [
        'uaid',
        'secret',
        'is_active',
        'last_synced_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret'        => 'encrypted',
            'is_active'     => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────

    public function devices(): HasMany
    {
        return $this->hasMany(StoreDevice::class, 'credential_id');
    }

    public function hubs(): HasMany
    {
        return $this->hasMany(StoreDevice::class, 'credential_id')->where('is_hub', true);
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(StoreDevice::class, 'credential_id')->where('is_hub', false);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function hasCredentials(): bool
    {
        return ! empty($this->uaid) && ! empty($this->secret);
    }

    public function label(): string
    {
        return $this->uaid;
    }
}
