<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('transfer_number', 40)->nullable()->after('tenant_id');
            $table->string('status', 20)->default('received')->after('quantity');
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('received_at')->nullable()->after('notes');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        $rows = DB::table('stock_transfers')->orderBy('id')->get();
        foreach ($rows as $row) {
            $number = 'TRF-'.now()->format('Ymd').'-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT);
            DB::table('stock_transfers')->where('id', $row->id)->update([
                'transfer_number' => $row->transfer_number ?: $number,
                'status' => $row->status ?: 'received',
                'received_at' => $row->received_at ?: $row->created_at,
                'received_by' => $row->received_by ?: $row->created_by,
            ]);
            if ($row->part_id || $row->product_id) {
                DB::table('stock_transfer_items')->insert([
                    'tenant_id' => $row->tenant_id,
                    'stock_transfer_id' => $row->id,
                    'part_id' => $row->part_id,
                    'product_id' => $row->product_id,
                    'quantity' => $row->quantity,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['transfer_number', 'status', 'notes', 'received_at']);
        });
    }
};
