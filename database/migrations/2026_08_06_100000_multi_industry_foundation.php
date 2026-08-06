<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('contact_phones')->nullable()->after('contact_phone');
            $table->json('owner_phones')->nullable()->after('owner_phone');
        });

        DB::table('tenants')->whereIn('business_type', ['shop', 'supermarket'])->update(['business_type' => 'clothing']);

        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
        });

        DB::statement('ALTER TABLE bills MODIFY vehicle_id BIGINT UNSIGNED NULL');

        Schema::table('bills', function (Blueprint $table) {
            $table->nullableMorphs('source');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropMorphs('source');
            $table->dropForeign(['vehicle_id']);
        });

        DB::statement('ALTER TABLE bills MODIFY vehicle_id BIGINT UNSIGNED NOT NULL');

        Schema::table('bills', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['contact_phones', 'owner_phones']);
        });
    }
};
