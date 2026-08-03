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
            $table->string('contact_email')->nullable()->after('owner_email');
            $table->string('contact_phone', 20)->nullable()->after('contact_email');
        });

        DB::table('tenants')->orderBy('id')->each(function ($tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'contact_email' => $tenant->owner_email,
                'contact_phone' => $tenant->owner_phone,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone']);
        });
    }
};
