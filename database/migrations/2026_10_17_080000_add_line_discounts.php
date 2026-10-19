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
        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('line_discount', 14, 2)->default(0)->after('discount_percent');
        });

        Feature::query()->updateOrCreate(['key' => 'line_discounts'], [
            'name' => 'Line discount',
            'description' => 'A discount field on each bill line, between rate and total. Flags a line that sells under cost after the discount. Nested under Job cards. Off until super-admin enables it.',
            'group' => 'Service Intake',
            'sort_order' => 36,
        ]);
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('line_discount');
        });

        $ids = DB::table('features')->where('key', 'line_discounts')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('user_permissions')->whereIn('feature_id', $ids)->delete();
            DB::table('tenant_features')->whereIn('feature_id', $ids)->delete();
            DB::table('features')->whereIn('id', $ids)->delete();
        }
    }
};
