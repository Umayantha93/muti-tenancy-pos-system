<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });

        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Commission for the team on a job card. Super admin chooses the commission type. Labor above salary counts service labor at 50% and repair labor at 100%, then pays 10% of the amount above salary. Monthly staff use their amount. Daily staff use attendance days, half days, additions, and deductions. Off until super-admin enables it.',
        ]);
    }

    public function down(): void
    {
        DB::table('features')->where('key', 'job_commissions')->update([
            'description' => 'Commission for the team on a job card. Super admin chooses the commission type. Labor above salary counts service labor at 50% and repair labor at 100%, then pays 10% of the amount above salary. Off until super-admin enables it.',
        ]);
    }
};
