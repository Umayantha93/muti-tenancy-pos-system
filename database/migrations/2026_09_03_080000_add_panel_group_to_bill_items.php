<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->uuid('panel_group_id')->nullable()->after('labor_item_id');
            $table->string('panel_name')->nullable()->after('panel_group_id');
            $table->index(['bill_id', 'panel_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropIndex(['bill_id', 'panel_group_id']);
            $table->dropColumn(['panel_group_id', 'panel_name']);
        });
    }
};
