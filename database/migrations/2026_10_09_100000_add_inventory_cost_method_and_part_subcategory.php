<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('subcategory', 100)->nullable()->after('type');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('inventory_cost_method', 20)->default('average')->after('dual_financial_view_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('subcategory');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('inventory_cost_method');
        });
    }
};
