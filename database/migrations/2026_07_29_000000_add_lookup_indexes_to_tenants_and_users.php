<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('owner_email', 'tenants_owner_email_index');
            $table->index(['business_type', 'status'], 'tenants_type_status_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status'], 'users_role_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_status_index');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_owner_email_index');
            $table->dropIndex('tenants_type_status_index');
        });
    }
};
