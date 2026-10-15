<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('section')->constrained()->nullOnDelete();
            $table->decimal('commission_amount', 14, 2)->nullable()->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('commission_amount');
        });
    }
};
