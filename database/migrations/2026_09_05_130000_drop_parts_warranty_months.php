<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('parts', 'warranty_months')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropColumn('warranty_months');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('parts', 'warranty_months')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->unsignedSmallInteger('warranty_months')->nullable()->after('description');
            });
        }
    }
};
