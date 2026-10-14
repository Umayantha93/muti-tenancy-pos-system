<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_ups', function (Blueprint $table) {
            $table->decimal('opening_float', 12, 2)->default(0)->after('business_date');
            $table->json('cash_count')->nullable()->after('counted_cheque');
            $table->json('bank_lines')->nullable()->after('cash_count');
        });
    }

    public function down(): void
    {
        Schema::table('cash_ups', function (Blueprint $table) {
            $table->dropColumn(['opening_float', 'cash_count', 'bank_lines']);
        });
    }
};
