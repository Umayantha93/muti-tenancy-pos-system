<?php

use App\Models\ServiceAddon;
use App\Models\ServiceVehicleClass;
use App\Models\Tenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_vehicle_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'active', 'sort_order']);
        });

        Schema::table('service_addons', function (Blueprint $table) {
            $table->foreignId('service_vehicle_class_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('service_vehicle_classes')
                ->nullOnDelete();
            $table->index(['tenant_id', 'service_vehicle_class_id', 'sort_order'], 'service_addons_class_sort_idx');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('service_vehicle_class_id')
                ->nullable()
                ->after('job_kind')
                ->constrained('service_vehicle_classes')
                ->nullOnDelete();
        });

        Tenant::query()
            ->where('business_type', BusinessTypes::GARAGE)
            ->orderBy('id')
            ->each(function (Tenant $tenant): void {
                ServiceVehicleClass::ensureDefaultsFor((int) $tenant->id);
                $car = ServiceVehicleClass::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('name', 'Car')
                    ->first();
                if (! $car) {
                    return;
                }
                ServiceAddon::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('service_vehicle_class_id')
                    ->update(['service_vehicle_class_id' => $car->id]);
            });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_vehicle_class_id');
        });
        Schema::table('service_addons', function (Blueprint $table) {
            $table->dropIndex('service_addons_class_sort_idx');
            $table->dropConstrainedForeignId('service_vehicle_class_id');
        });
        Schema::dropIfExists('service_vehicle_classes');
    }
};
