<?php

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $billSms = Feature::updateOrCreate(
            ['key' => 'bill_sms'],
            [
                'name' => 'Bill SMS',
                'description' => 'Send quotation / paid bill links to customers by SMS',
                'group' => 'Service Intake',
                'sort_order' => 31,
            ]
        );

        Tenant::query()->each(function (Tenant $tenant) use ($billSms) {
            $enabled = $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key');

            if ($enabled->contains('billing') && ! $enabled->contains('bill_sms')) {
                $tenant->features()->syncWithoutDetaching([$billSms->id => ['is_enabled' => true]]);
            }
        });

        DB::table('user_permissions')
            ->join('features', 'features.id', '=', 'user_permissions.feature_id')
            ->where('features.key', 'billing')
            ->where('user_permissions.can_access', true)
            ->orderBy('user_permissions.user_id')
            ->pluck('user_permissions.user_id')
            ->unique()
            ->each(function ($userId) use ($billSms) {
                DB::table('user_permissions')->updateOrInsert(
                    ['user_id' => $userId, 'feature_id' => $billSms->id],
                    ['can_access' => true, 'updated_at' => now(), 'created_at' => now()]
                );
            });
    }

    public function down(): void
    {
        $billSms = Feature::where('key', 'bill_sms')->first();
        if (! $billSms) {
            return;
        }

        DB::table('user_permissions')->where('feature_id', $billSms->id)->delete();
        DB::table('tenant_features')->where('feature_id', $billSms->id)->delete();
        $billSms->delete();
    }
};
