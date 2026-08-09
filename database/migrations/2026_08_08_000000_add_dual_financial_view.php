<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('dual_financial_view_enabled')->default(false)->after('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_secondary_view')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('dual_financial_view_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_secondary_view');
        });
    }
};
