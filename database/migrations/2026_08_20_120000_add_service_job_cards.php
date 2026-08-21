<?php

use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_full_service')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('service_addon_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('full_service_addon_id')->constrained('service_addons')->cascadeOnDelete();
            $table->foreignId('included_addon_id')->constrained('service_addons')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['full_service_addon_id', 'included_addon_id'], 'addon_inclusion_unique');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->string('job_kind', 20)->default('repair')->after('status');
            $table->index(['tenant_id', 'job_kind']);
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('service_addon_id')->nullable()->after('part_id')
                ->constrained('service_addons')->nullOnDelete();
        });

        Tenant::query()
            ->where('business_type', BusinessTypes::GARAGE)
            ->pluck('id')
            ->each(fn ($tenantId) => ServiceAddon::seedDefaultsFor((int) $tenantId));
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_addon_id');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'job_kind']);
            $table->dropColumn('job_kind');
        });

        Schema::dropIfExists('service_addon_inclusions');
        Schema::dropIfExists('service_addons');
    }
};
