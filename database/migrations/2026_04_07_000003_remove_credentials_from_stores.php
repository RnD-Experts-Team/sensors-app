<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['yosmart_uaid', 'yosmart_secret']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('yosmart_uaid')->nullable()->after('is_active');
            $table->text('yosmart_secret')->nullable()->after('yosmart_uaid');
        });
    }
};
