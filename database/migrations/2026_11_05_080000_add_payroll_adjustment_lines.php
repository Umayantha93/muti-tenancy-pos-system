<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->json('addition_lines')->nullable()->after('allowances_total');
            $table->decimal('commission', 14, 2)->default(0)->after('bonus');
            $table->json('deduction_lines')->nullable()->after('deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['addition_lines', 'commission', 'deduction_lines']);
        });
    }
};
