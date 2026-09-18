<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'setup_fee_amount')) {
                $table->decimal('setup_fee_amount', 14, 2)->nullable()->after('plan_amount');
            }
        });

        Schema::create('tenant_setup_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_setup_fee_payments');
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'setup_fee_amount')) {
                $table->dropColumn('setup_fee_amount');
            }
        });
    }
};
