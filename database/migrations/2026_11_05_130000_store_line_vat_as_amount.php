<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->renameColumn('vat_percent', 'line_vat');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('line_vat', 14, 2)->default(0)->change();
        });

        Feature::query()->where('key', 'line_vat')->update([
            'description' => 'A VAT amount on each bill line, between rate and discount. The amount is taken out of the rate and added to the bill VAT total. Owe-in bills can still set it. Off until super-admin enables it.',
        ]);
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('line_vat', 5, 2)->default(0)->change();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->renameColumn('line_vat', 'vat_percent');
        });
    }
};
