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
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('job_kind', 20)->nullable()->after('category');
        });

        Feature::query()->updateOrCreate(['key' => 'expense_job_split'], [
            'name' => 'Repair / service expenses',
            'description' => 'Every new expense must be tagged Repair or Service, and Finance shows the two totals separately. Garage only. Off until super-admin enables it.',
            'group' => 'Finance',
            'sort_order' => 92,
        ]);
        Feature::query()->updateOrCreate(['key' => 'finance_report_export'], [
            'name' => 'Finance report download',
            'description' => 'Download the monthly Finance report as Excel or PDF — daily table only, or full with every day\'s detail lines. Garage only. Off until super-admin enables it.',
            'group' => 'Finance',
            'sort_order' => 93,
        ]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('job_kind');
        });
        DB::table('features')->whereIn('key', ['expense_job_split', 'finance_report_export'])->delete();
    }
};
