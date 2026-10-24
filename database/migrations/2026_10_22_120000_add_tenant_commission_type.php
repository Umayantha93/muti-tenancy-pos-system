<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('commission_type', 40)->default('labor_excess')->after('business_type');
        });

        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Monthly commission for the team on paid job cards. The current type pays 10% of labor above salary. Repair labor counts in full, service labor at half. Off until super-admin enables it.',
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('commission_type');
        });

        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Commission for employees assigned to a job card, deducted into finance. Nested under Job cards. Off until super-admin enables it.',
        ]);
    }
};
