<?php

use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garages move to one shared service-name list with prices typed on each job card.
 * Old per-vehicle-type priced services and the seeded vehicle types are cleared so owners enter their own.
 * Bill lines keep their description and amounts; only the catalog link is nulled by the foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $garageIds = DB::table('tenants')->where('business_type', BusinessTypes::GARAGE)->pluck('id');

        if ($garageIds->isNotEmpty()) {
            DB::table('service_addons')->whereIn('tenant_id', $garageIds)->delete();
            DB::table('service_vehicle_classes')->whereIn('tenant_id', $garageIds)->delete();
        }

        if (Schema::hasColumn('service_vehicle_classes', 'category')) {
            Schema::table('service_vehicle_classes', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        // Cleared catalog rows cannot be restored.
    }
};
