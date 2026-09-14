<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->string('cheque_number', 80)->nullable()->after('reference');
            $table->date('cheque_date')->nullable()->after('cheque_number');
            $table->string('cheque_status', 20)->nullable()->after('cheque_date');
            $table->date('cleared_on')->nullable()->after('cheque_status');
            $table->index(['cheque_status', 'cheque_date']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropIndex(['cheque_status', 'cheque_date']);
            $table->dropColumn(['cheque_number', 'cheque_date', 'cheque_status', 'cleared_on']);
        });
    }
};
