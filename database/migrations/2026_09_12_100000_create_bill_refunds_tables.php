<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('amount_refunded', 12, 2)->default(0)->after('amount_paid');
        });

        Schema::create('bill_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->date('refunded_at');
            $table->text('reason');
            $table->string('method', 32);
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'refunded_at']);
            $table->index(['bill_id', 'refunded_at']);
        });

        Schema::create('bill_refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('disposition', 20)->default('none');
            $table->timestamps();

            $table->index(['bill_refund_id', 'bill_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_refund_items');
        Schema::dropIfExists('bill_refunds');

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('amount_refunded');
        });
    }
};
