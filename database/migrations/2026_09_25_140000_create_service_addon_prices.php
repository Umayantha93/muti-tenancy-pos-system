<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addon_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_addon_id')->constrained('service_addons')->cascadeOnDelete();
            $table->foreignId('service_vehicle_class_id')->constrained('service_vehicle_classes')->cascadeOnDelete();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('offered')->default(true);
            $table->timestamps();

            $table->unique(['service_addon_id', 'service_vehicle_class_id'], 'service_addon_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addon_prices');
    }
};
