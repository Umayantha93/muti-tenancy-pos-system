<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appliance_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('built_in')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('appliance_type', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appliance_types');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('appliance_type', 30)->nullable()->change();
        });
    }
};
