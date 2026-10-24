<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('line_total');
        });

        DB::table('features')->where('key', 'free_service')->update([
            'description' => 'A free checkbox on each bill line. That line is not charged to the customer, and its price still counts for technician commission. Nested under Job cards. Off until super-admin enables it.',
        ]);
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });

        DB::table('features')->where('key', 'free_service')->update([
            'description' => 'Mark a job card free for the customer, including parts, while technician commission still uses the line prices. Nested under Job cards. Off until super-admin enables it.',
        ]);
    }
};
