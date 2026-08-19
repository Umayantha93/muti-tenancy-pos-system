<?php

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $feature = Feature::updateOrCreate(
            ['key' => 'bill_profits'],
            [
                'name' => 'Bill Profits Analysis',
                'description' => 'Bill revenue, inventory cost, and credit-bill profit reporting',
                'group' => 'Service Intake',
                'sort_order' => 32,
            ]
        );

        Tenant::query()->each(function (Tenant $tenant) use ($feature) {
            $enabled = $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key');

            if ($enabled->contains('billing') && ! $enabled->contains('bill_profits')) {
                $tenant->features()->syncWithoutDetaching([$feature->id => ['is_enabled' => true]]);
            }
        });

        DB::table('user_permissions')
            ->join('features', 'features.id', '=', 'user_permissions.feature_id')
            ->where('features.key', 'billing')
            ->where('user_permissions.can_access', true)
            ->orderBy('user_permissions.user_id')
            ->pluck('user_permissions.user_id')
            ->unique()
            ->each(function ($userId) use ($feature) {
                DB::table('user_permissions')->updateOrInsert(
                    ['user_id' => $userId, 'feature_id' => $feature->id],
                    ['can_access' => true, 'updated_at' => now(), 'created_at' => now()]
                );
            });
    }

    public function down(): void
    {
        $feature = Feature::where('key', 'bill_profits')->first();
        if (! $feature) {
            return;
        }

        DB::table('user_permissions')->where('feature_id', $feature->id)->delete();
        DB::table('tenant_features')->where('feature_id', $feature->id)->delete();
        $feature->delete();
    }
};
