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
        Schema::table('bills', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('hide_amounts');
        });

        foreach ($this->features() as $feature) {
            Feature::query()->updateOrCreate(['key' => $feature['key']], $feature);
        }
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });

        $ids = DB::table('features')->whereIn('key', ['free_service', 'show_bill_technicians'])->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('user_permissions')->whereIn('feature_id', $ids)->delete();
            DB::table('tenant_features')->whereIn('feature_id', $ids)->delete();
            DB::table('features')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, group: string, sort_order: int}>
     */
    private function features(): array
    {
        return [
            ['key' => 'free_service', 'name' => 'Free service', 'description' => 'Mark a job card free for the customer, including parts, while technician commission still uses the line prices. Nested under Job cards. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 34],
            ['key' => 'show_bill_technicians', 'name' => 'Technicians on the bill', 'description' => 'Print the technician on each labor and service line. Nested under Job cards. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 35],
        ];
    }
};
