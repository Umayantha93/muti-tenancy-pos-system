<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('returned_qty', 12, 3)->default(0);
            $table->json('service_names');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'closed_at']);
            $table->index(['tenant_id', 'part_id', 'branch_id']);
        });

        Feature::query()->updateOrCreate(['key' => 'station_consumables'], [
            'name' => 'Station consumables',
            'description' => 'Issue bulk tins (e.g. Hypower, shampoo) to the wash bay. Stock drops when opened; cost per wash and profit are worked out from billed service addons. Off until super-admin enables it.',
            'group' => 'Inventory',
            'sort_order' => 47,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_issues');
        DB::table('features')->where('key', 'station_consumables')->delete();
    }
};
