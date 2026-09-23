<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('bill_prefix', 12)->nullable()->after('logo');
            $table->unsignedInteger('bill_sequence')->default(0)->after('bill_prefix');
            $table->timestamp('bill_number_locked_at')->nullable()->after('bill_sequence');
        });

        Schema::create('discount_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mode', 16)->default('amount');
            $table->decimal('value', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'active', 'sort_order']);
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
        Schema::dropIfExists('discount_types');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['bill_prefix', 'bill_sequence', 'bill_number_locked_at']);
        });
    }
};
