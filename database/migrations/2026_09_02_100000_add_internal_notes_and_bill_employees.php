<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });

        Schema::create('bill_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['bill_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_employees');

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
