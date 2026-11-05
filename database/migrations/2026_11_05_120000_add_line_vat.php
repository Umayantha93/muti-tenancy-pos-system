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
            $table->decimal('vat_percent', 5, 2)->default(0)->after('discount_percent');
        });

        Feature::query()->updateOrCreate(['key' => 'line_vat'], [
            'name' => 'Line VAT',
            'description' => 'A VAT percent on each bill line, between rate and discount. The percent is taken out of the rate and added to the bill VAT total. Owe-in bills can still set it. Off until super-admin enables it.',
            'group' => 'Service Intake',
            'sort_order' => 37,
        ]);
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('vat_percent');
        });

        Feature::query()->where('key', 'line_vat')->delete();
    }
};
