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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('appliance_type', 30)->nullable()->after('asset_kind');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->string('service_place', 20)->nullable()->after('job_kind');
        });

        Feature::query()->updateOrCreate(['key' => 'admit_appliance'], [
            'name' => 'Admit appliance',
            'description' => 'Admit a fridge, split unit, or other appliance onto a job card. Nested under Admit vehicle. A/C repair shops.',
            'group' => 'Service Intake',
            'sort_order' => 13,
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('service_place');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('appliance_type');
        });

        $featureId = DB::table('features')->where('key', 'admit_appliance')->value('id');
        if ($featureId) {
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
