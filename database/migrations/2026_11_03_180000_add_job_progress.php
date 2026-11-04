<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bills', 'progress_step')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('progress_step', 32)->nullable()->after('floor_status');
            });
        }

        if (! Schema::hasTable('job_progress_photos')) {
            Schema::create('job_progress_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
                $table->string('step_key', 32);
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->unsignedInteger('size_bytes')->default(0);
                $table->timestamps();
                $table->index(['bill_id', 'step_key']);
            });
        }

        DB::table('features')->updateOrInsert(
            ['key' => 'job_progress'],
            [
                'name' => 'Job progress',
                'description' => 'Step-by-step job progress with a photo on each step, a Complete state, and a WhatsApp link for the customer. Garage and paint shops. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 33,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('job_progress_photos');
        if (Schema::hasColumn('bills', 'progress_step')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('progress_step');
            });
        }
        $featureId = DB::table('features')->where('key', 'job_progress')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
            DB::table('user_permissions')->where('feature_id', $featureId)->delete();
            DB::table('features')->where('id', $featureId)->delete();
        }
    }
};
