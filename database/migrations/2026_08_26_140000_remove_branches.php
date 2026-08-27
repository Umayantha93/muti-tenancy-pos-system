<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bills') && Schema::hasColumn('bills', 'branch_id')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }

        if (Schema::hasTable('part_sales') && Schema::hasColumn('part_sales', 'branch_id')) {
            Schema::table('part_sales', function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }

        Schema::dropIfExists('branches');
    }

    public function down(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        if (Schema::hasTable('bills') && ! Schema::hasColumn('bills', 'branch_id')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('part_sales') && ! Schema::hasColumn('part_sales', 'branch_id')) {
            Schema::table('part_sales', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }
};
