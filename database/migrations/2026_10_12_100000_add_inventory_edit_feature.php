<?php

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => 'inventory_edit'],
            [
                'name' => 'Edit inventory',
                'description' => 'Add, edit, and delete catalog items, including the on-hand quantity. The shop owner always has this. Staff only have it when the owner turns it on.',
                'group' => 'Inventory',
                'sort_order' => 48,
            ],
        );

        Tenant::query()
            ->whereHas('features', function ($query) {
                $query->where('features.key', 'parts_inventory')
                    ->where('tenant_features.is_enabled', true);
            })
            ->each(function (Tenant $tenant) use ($feature) {
                $tenant->features()->syncWithoutDetaching([
                    $feature->id => ['is_enabled' => true],
                ]);
            });
    }

    public function down(): void
    {
        $feature = Feature::query()->where('key', 'inventory_edit')->first();
        if (! $feature) {
            return;
        }

        DB::table('user_permissions')->where('feature_id', $feature->id)->delete();
        $feature->tenants()->detach();
        $feature->delete();
    }
};
