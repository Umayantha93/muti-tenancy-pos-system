<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->decimal('stock_qty', 12, 3)->default(0)->change();
        });
        Schema::table('branch_stocks', function (Blueprint $table) {
            $table->decimal('qty', 12, 3)->default(0)->change();
        });
        if (Schema::hasTable('stock_receipt_items')) {
            Schema::table('stock_receipt_items', function (Blueprint $table) {
                $table->decimal('quantity', 12, 3)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->unsignedInteger('stock_qty')->default(0)->change();
        });
        Schema::table('branch_stocks', function (Blueprint $table) {
            $table->unsignedInteger('qty')->default(0)->change();
        });
        if (Schema::hasTable('stock_receipt_items')) {
            Schema::table('stock_receipt_items', function (Blueprint $table) {
                $table->unsignedInteger('quantity')->change();
            });
        }
    }
};
