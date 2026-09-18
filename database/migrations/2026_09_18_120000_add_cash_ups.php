<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('expected_cash', 12, 2)->default(0);
            $table->decimal('expected_card', 12, 2)->default(0);
            $table->decimal('expected_bank', 12, 2)->default(0);
            $table->decimal('expected_cheque', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2)->default(0);
            $table->decimal('counted_card', 12, 2)->default(0);
            $table->decimal('counted_bank', 12, 2)->default(0);
            $table->decimal('counted_cheque', 12, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'user_id', 'business_date'], 'cash_ups_shop_cashier_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_ups');
    }
};
