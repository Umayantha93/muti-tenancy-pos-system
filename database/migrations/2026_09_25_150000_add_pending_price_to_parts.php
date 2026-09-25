<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->decimal('pending_price', 12, 2)->nullable()->after('price');
            $table->decimal('pending_price_at_qty', 12, 3)->nullable()->after('pending_price');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn(['pending_price', 'pending_price_at_qty']);
        });
    }
};
