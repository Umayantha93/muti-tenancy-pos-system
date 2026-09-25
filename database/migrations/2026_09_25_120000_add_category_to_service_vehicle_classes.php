<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_vehicle_classes', function (Blueprint $table) {
            $table->string('category', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_vehicle_classes', 'category')) {
            Schema::table('service_vehicle_classes', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
