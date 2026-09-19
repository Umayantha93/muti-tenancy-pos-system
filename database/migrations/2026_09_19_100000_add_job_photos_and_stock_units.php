<?php

use App\Models\Feature;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('label', 40)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamps();
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('stock_unit', 8)->default('qty')->after('stock_qty');
        });

        $paintIds = DB::table('tenants')->where('business_type', BusinessTypes::PAINT)->pluck('id');
        if ($paintIds->isNotEmpty()) {
            DB::table('parts')->whereIn('tenant_id', $paintIds)->update(['stock_unit' => 'ml']);
        }

        Feature::query()->updateOrCreate(
            ['key' => 'job_photos'],
            [
                'name' => 'Job photos',
                'description' => 'Up to 15 photos on a garage job. Visible on the customer bill link. Deleted after 6 months. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 15,
            ],
        );

        Feature::query()->where('key', 'job_videos')->update([
            'description' => 'Up to 5 compressed clips on a garage job. Visible on the customer bill link. Deleted after 6 months. Off until super-admin enables it.',
            'sort_order' => 16,
        ]);
        Feature::query()->where('key', 'job_bookings')->update(['sort_order' => 17]);
    }

    public function down(): void
    {
        Feature::query()->where('key', 'job_photos')->delete();
        Feature::query()->where('key', 'job_videos')->update([
            'description' => 'Up to 5 compressed clips on a garage job. Staff-only. Deleted after 6 months. Off until super-admin enables it.',
            'sort_order' => 15,
        ]);
        Feature::query()->where('key', 'job_bookings')->update(['sort_order' => 16]);

        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('stock_unit');
        });
        Schema::dropIfExists('bill_photos');
    }
};
