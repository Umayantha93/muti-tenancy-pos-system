<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')->updateOrInsert(
            ['key' => 'owe_in_register'],
            [
                'name' => 'Owe-in register',
                'description' => 'On Finance, list customers who still owe, filter that total by due date, and download a PDF with name, phone, vehicle, and amount. Garage only. Off until super-admin enables it.',
                'group' => 'Finance',
                'sort_order' => 94,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('key', 'owe_in_register')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
