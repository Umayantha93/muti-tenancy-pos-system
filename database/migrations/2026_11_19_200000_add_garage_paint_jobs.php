<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labor_categories', function (Blueprint $table) {
            $table->string('catalog', 20)->default('garage')->after('tenant_id');
            $table->index(['tenant_id', 'catalog']);
        });

        Schema::table('service_addons', function (Blueprint $table) {
            $table->string('catalog', 20)->default('garage')->after('tenant_id');
            $table->index(['tenant_id', 'catalog']);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->string('paint_mode', 20)->nullable()->after('job_kind');
        });

        $paintTenantIds = DB::table('tenants')->where('business_type', 'paint')->pluck('id');
        if ($paintTenantIds->isNotEmpty()) {
            DB::table('labor_categories')->whereIn('tenant_id', $paintTenantIds)->update(['catalog' => 'paint']);
            DB::table('service_addons')->whereIn('tenant_id', $paintTenantIds)->update(['catalog' => 'paint']);
        }

        Feature::query()->updateOrCreate(['key' => 'admit_paint'], [
            'name' => 'Paint jobs',
            'description' => 'Paint admissions on a garage: panel work, paint packages, paint labor, and colour stock. Nested under Admit vehicle. Off until super-admin enables it.',
            'group' => 'Service Intake',
            'sort_order' => 12,
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('paint_mode');
        });

        Schema::table('service_addons', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'catalog']);
            $table->dropColumn('catalog');
        });

        Schema::table('labor_categories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'catalog']);
            $table->dropColumn('catalog');
        });

        $featureId = DB::table('features')->where('key', 'admit_paint')->value('id');
        if ($featureId) {
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
