<?php

use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('phone_secondary', 30)->nullable()->after('phone');
            $table->string('email')->nullable()->after('phone_secondary');
            $table->string('address')->nullable()->after('email');
            $table->string('tin', 50)->nullable()->after('address');
            $table->string('contact_person', 120)->nullable()->after('tin');
            $table->boolean('is_system')->default(false)->after('notes');
            $table->boolean('active')->default(true)->after('is_system');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->boolean('hide_amounts')->default(false)->after('job_kind');
        });

        Schema::create('bill_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('label', 40)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamps();
        });

        $now = now();
        $features = [
            [
                'key' => 'owner_bill_sms',
                'name' => 'Owner bill SMS',
                'description' => 'When staff send a bill SMS, also send a copy of the same link to the shop owner. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 35,
            ],
            [
                'key' => 'service_ops_report',
                'name' => 'Service operations report',
                'description' => 'Count billed garage service addons (sold qty vs inside full service) with revenue. Off until super-admin enables it.',
                'group' => 'Finance',
                'sort_order' => 91,
            ],
            [
                'key' => 'job_videos',
                'name' => 'Job videos',
                'description' => 'Up to 5 compressed clips on a garage job. Staff-only. Deleted after 6 months. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 36,
            ],
        ];
        foreach ($features as $feature) {
            if (DB::table('features')->where('key', $feature['key'])->exists()) {
                continue;
            }
            DB::table('features')->insert([
                ...$feature,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $garageIds = DB::table('tenants')->where('business_type', BusinessTypes::GARAGE)->pluck('id');
        foreach ($garageIds as $tenantId) {
            $exists = DB::table('suppliers')
                ->where('tenant_id', $tenantId)
                ->where('is_system', true)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('suppliers')->insert([
                'tenant_id' => $tenantId,
                'name' => 'Walk-in / unnamed',
                'notes' => 'Cash buys with no named house',
                'is_system' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_videos');
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('hide_amounts');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'phone_secondary', 'email', 'address', 'tin', 'contact_person', 'is_system', 'active',
            ]);
        });
        DB::table('features')->whereIn('key', ['owner_bill_sms', 'service_ops_report', 'job_videos'])->delete();
    }
};
