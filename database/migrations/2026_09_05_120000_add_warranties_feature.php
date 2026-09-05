<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_items') && ! Schema::hasColumn('bill_items', 'warranty_starts_on')) {
            Schema::table('bill_items', function (Blueprint $table) {
                $table->date('warranty_starts_on')->nullable()->after('warranty_months');
            });
        }

        if (Schema::hasColumn('bill_items', 'warranty_starts_on') && Schema::hasColumn('bill_items', 'warranty_until')) {
            DB::statement('UPDATE bill_items INNER JOIN bills ON bills.id = bill_items.bill_id SET bill_items.warranty_starts_on = bills.admission_date WHERE bill_items.warranty_until IS NOT NULL AND bill_items.warranty_starts_on IS NULL');
        }

        $featureId = DB::table('features')->where('key', 'warranties')->value('id');
        if (! $featureId) {
            $featureId = DB::table('features')->insertGetId([
                'key' => 'warranties',
                'name' => 'Warranties',
                'description' => 'Add a warranty on the bill from the purchase date (1 year, 2 years, or a custom end date). Includes warranty lookup.',
                'group' => 'Service Intake',
                'sort_order' => 34,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $storeIds = DB::table('tenants')->where('business_type', 'store')->pluck('id');
        foreach ($storeIds as $tenantId) {
            DB::table('tenant_features')->updateOrInsert(
                ['tenant_id' => $tenantId, 'feature_id' => $featureId],
                ['is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('key', 'warranties')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }

        if (Schema::hasColumn('bill_items', 'warranty_starts_on')) {
            Schema::table('bill_items', function (Blueprint $table) {
                $table->dropColumn('warranty_starts_on');
            });
        }
    }
};
