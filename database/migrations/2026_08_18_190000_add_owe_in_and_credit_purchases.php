<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->date('owe_in_due_date')->nullable()->after('status');
            $table->dateTime('closed_at')->nullable()->after('owe_in_due_date');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('paid')->after('expense_date');
            $table->date('due_date')->nullable()->after('payment_status');
            $table->dateTime('settled_at')->nullable()->after('due_date');
            $table->index('payment_status');
        });

        DB::table('expenses')->whereNull('settled_at')->update([
            'payment_status' => 'paid',
            'settled_at' => DB::raw('expense_date'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['owe_in_due_date', 'closed_at']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['payment_status', 'due_date', 'settled_at']);
        });
    }
};
