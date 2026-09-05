<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('description');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('line_total');
            $table->date('warranty_until')->nullable()->after('warranty_months');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn(['warranty_months', 'warranty_until']);
        });
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
