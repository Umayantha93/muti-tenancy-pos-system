<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 100)->nullable()->after('sku');
            $table->string('badge', 30)->nullable()->after('color');
            $table->unique(['tenant_id', 'barcode']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('address');
        });

        Schema::create('clothing_floor_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['tenant_id', 'branch_id']);
        });

        DB::table('features')->updateOrInsert(
            ['key' => 'customer_display'],
            [
                'name' => 'Customer display',
                'description' => 'Second screen that shows the current clothing sale to the customer. An empty sale shows the shop logo. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 29,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('key', 'customer_display')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }

        Schema::dropIfExists('clothing_floor_states');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_vip');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'barcode']);
            $table->dropColumn(['barcode', 'badge']);
        });
    }
};
