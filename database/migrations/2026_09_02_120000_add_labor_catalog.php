<?php

use App\Models\LaborCategory;
use App\Models\Tenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('labor_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('labor_category_id')->constrained('labor_categories')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('hourly_rate', 14, 2)->default(0);
            $table->decimal('standard_hours', 8, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'labor_category_id', 'sort_order']);
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('labor_item_id')->nullable()->after('service_addon_id')
                ->constrained('labor_items')->nullOnDelete();
        });

        Tenant::query()
            ->where('business_type', BusinessTypes::GARAGE)
            ->pluck('id')
            ->each(fn ($tenantId) => LaborCategory::seedDefaultsFor((int) $tenantId));
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labor_item_id');
        });
        Schema::dropIfExists('labor_items');
        Schema::dropIfExists('labor_categories');
    }
};
