<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('subcategory', 100)->nullable()->after('category');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('half_view')->default(false)->after('bill_number_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('subcategory');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('half_view');
        });
    }
};
