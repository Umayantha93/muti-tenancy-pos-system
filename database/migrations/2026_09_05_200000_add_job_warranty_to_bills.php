<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bills') && ! Schema::hasColumn('bills', 'warranty_months')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->unsignedSmallInteger('warranty_months')->nullable()->after('additional_note_color');
                $table->date('warranty_starts_on')->nullable()->after('warranty_months');
                $table->date('warranty_until')->nullable()->after('warranty_starts_on');
            });
        }

        $featureId = DB::table('features')->where('key', 'warranties')->value('id');
        if (! $featureId) {
            $featureId = DB::table('features')->insertGetId([
                'key' => 'warranties',
                'name' => 'Warranties',
                'description' => 'Add a warranty on the job or sale in months or years.',
                'group' => 'Service Intake',
                'sort_order' => 34,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('features')->where('id', $featureId)->update([
                'description' => 'Add a warranty on the job or sale in months or years.',
                'updated_at' => now(),
            ]);
        }

        $tenantIds = DB::table('tenants')
            ->whereIn('business_type', ['garage', 'tyre', 'device_repair', 'paint'])
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            DB::table('tenant_features')->updateOrInsert(
                ['tenant_id' => $tenantId, 'feature_id' => $featureId],
                ['is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $staffIds = DB::table('users')
            ->whereIn('tenant_id', $tenantIds)
            ->where('role', 'staff')
            ->pluck('id');

        foreach ($staffIds as $userId) {
            DB::table('user_permissions')->updateOrInsert(
                ['user_id' => $userId, 'feature_id' => $featureId],
                ['can_access' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bills', 'warranty_months')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn(['warranty_months', 'warranty_starts_on', 'warranty_until']);
            });
        }
    }
};
