<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_devices', function (Blueprint $table) {
            // YoLink parentDeviceId: the hub a sensor relays through. Null for
            // hubs and for devices synced before this column existed. Stores
            // YoLink's device_id string (not a local FK).
            $table->string('parent_device_id')->nullable()->after('device_id');
            $table->index(['credential_id', 'parent_device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('store_devices', function (Blueprint $table) {
            $table->dropIndex(['credential_id', 'parent_device_id']);
            $table->dropColumn('parent_device_id');
        });
    }
};
