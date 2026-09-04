<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('features')->where('key', 'repair_bills')->exists()) {
            return;
        }

        DB::table('features')->insert([
            'key' => 'repair_bills',
            'name' => 'Repair',
            'description' => 'Repair bills and repair profit for stores. Off until super-admin enables it.',
            'group' => 'Service Intake',
            'sort_order' => 33,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('features')->where('key', 'repair_bills')->delete();
    }
};
