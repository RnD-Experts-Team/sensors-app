<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_devices', function (Blueprint $table) {
            // Add credential_id FK
            $table->foreignId('credential_id')
                ->nullable()
                ->after('id')
                ->constrained('yosmart_credentials')
                ->cascadeOnDelete();

            // Make store_id nullable (unmatched devices won't have a store)
            $table->unsignedBigInteger('store_id')->nullable()->change();

            // Parsed store number extracted from device name
            $table->string('parsed_store_number')->nullable()->after('device_name');
        });

        // Drop the FK on store_id first (MySQL requires this before dropping the unique index it uses)
        Schema::table('store_devices', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });

        // Drop old unique constraint and add new one
        Schema::table('store_devices', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'device_id']);
            $table->unique(['credential_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('store_devices', function (Blueprint $table) {
            $table->dropUnique(['credential_id', 'device_id']);
            $table->dropForeign(['credential_id']);
            $table->dropColumn(['credential_id', 'parsed_store_number']);

            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unique(['store_id', 'device_id']);
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }
};
