<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date');
            $table->string('status', 20)->default('pending');
            $table->foreignId('settlement_id')->nullable()->constrained('expense_settlements')->nullOnDelete();
            $table->date('cleared_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'cheque_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_cheques');
    }
};
