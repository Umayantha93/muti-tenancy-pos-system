<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->string('driver_name', 255)->nullable()->after('customer_id');
            $table->string('driver_phone', 20)->nullable()->after('driver_name');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['driver_name', 'driver_phone']);
        });
    }
};
