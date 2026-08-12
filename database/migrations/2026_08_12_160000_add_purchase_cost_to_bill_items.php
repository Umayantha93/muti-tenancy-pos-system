<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('purchase_unit_cost', 14, 2)->nullable()->after('unit_price');
            $table->foreignId('purchase_expense_id')->nullable()->after('purchase_unit_cost')
                ->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_expense_id');
            $table->dropColumn('purchase_unit_cost');
        });
    }
};
