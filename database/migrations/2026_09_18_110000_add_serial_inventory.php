<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            if (! Schema::hasColumn('parts', 'serialized')) {
                $table->boolean('serialized')->default(false)->after('stock_qty');
            }
        });

        Schema::create('part_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 40);
            $table->string('status', 20)->default('in_stock');
            $table->foreignId('bill_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'serial']);
            $table->index(['part_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_serials');
        Schema::table('parts', function (Blueprint $table) {
            if (Schema::hasColumn('parts', 'serialized')) {
                $table->dropColumn('serialized');
            }
        });
    }
};
