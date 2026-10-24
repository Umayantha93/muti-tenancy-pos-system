<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Commission for the team on a job card. Super admin chooses the commission type. Labor above salary counts service labor at 50% and repair labor at 100%, then pays 10% of the amount above salary. Off until super-admin enables it.',
        ]);
    }

    public function down(): void
    {
        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Monthly commission for the team on paid job cards. The current type pays 10% of labor above salary. Repair labor counts in full, service labor at half. Off until super-admin enables it.',
        ]);
    }
};
