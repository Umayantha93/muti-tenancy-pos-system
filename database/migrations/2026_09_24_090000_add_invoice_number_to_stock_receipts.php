<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->string('invoice_number', 80)->nullable()->after('receipt_number');
            $table->index(['tenant_id', 'invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'invoice_number']);
            $table->dropColumn('invoice_number');
        });
    }
};
