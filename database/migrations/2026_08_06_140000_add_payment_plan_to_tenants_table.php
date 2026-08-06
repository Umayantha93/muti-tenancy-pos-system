<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('payment_plan')->default('monthly')->after('plan');
            $table->decimal('plan_amount', 14, 2)->nullable()->after('payment_plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['payment_plan', 'plan_amount']);
        });
    }
};
