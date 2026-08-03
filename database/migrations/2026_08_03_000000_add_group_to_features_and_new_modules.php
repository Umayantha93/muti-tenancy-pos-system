<?php

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('group')->nullable()->after('description');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('group');
        });

        $modules = [
            ['key' => 'admit_vehicle', 'name' => 'Admit vehicle', 'description' => 'Admit vehicles and open job cards', 'group' => 'Service Intake', 'sort_order' => 10],
            ['key' => 'customers', 'name' => 'Customers', 'description' => 'Customer directory and job history', 'group' => 'Service Intake', 'sort_order' => 20],
            ['key' => 'billing', 'name' => 'Job cards', 'description' => 'Job cards, charges, and payments', 'group' => 'Service Intake', 'sort_order' => 30],
            ['key' => 'parts_inventory', 'name' => 'Parts inventory', 'description' => 'Parts and product stock', 'group' => 'Inventory', 'sort_order' => 40],
            ['key' => 'employees_management', 'name' => 'Team', 'description' => 'Employee profiles and records', 'group' => 'People', 'sort_order' => 50],
            ['key' => 'attendance', 'name' => 'Attendance', 'description' => 'Punch and monthly attendance', 'group' => 'People', 'sort_order' => 60],
            ['key' => 'payroll', 'name' => 'Payroll', 'description' => 'Attendance-based monthly payroll', 'group' => 'People', 'sort_order' => 70],
            ['key' => 'balance_sheet', 'name' => 'Finance', 'description' => 'Income, expenses and profit', 'group' => 'Finance', 'sort_order' => 80],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Business reporting and trends', 'group' => 'Finance', 'sort_order' => 90],
        ];

        foreach ($modules as $module) {
            Feature::updateOrCreate(['key' => $module['key']], $module);
        }

        $customers = Feature::where('key', 'customers')->first();
        $attendance = Feature::where('key', 'attendance')->first();

        Tenant::query()->each(function (Tenant $tenant) use ($customers, $attendance) {
            $enabled = $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key');

            if ($customers && $enabled->contains('admit_vehicle') && ! $enabled->contains('customers')) {
                $tenant->features()->syncWithoutDetaching([$customers->id => ['is_enabled' => true]]);
            }

            if ($attendance && $enabled->contains('employees_management') && ! $enabled->contains('attendance')) {
                $tenant->features()->syncWithoutDetaching([$attendance->id => ['is_enabled' => true]]);
            }
        });

        // Staff who already had the parent module keep access to the split child modules.
        if ($customers) {
            \Illuminate\Support\Facades\DB::table('user_permissions')
                ->join('features', 'features.id', '=', 'user_permissions.feature_id')
                ->where('features.key', 'admit_vehicle')
                ->where('user_permissions.can_access', true)
                ->orderBy('user_permissions.user_id')
                ->pluck('user_permissions.user_id')
                ->unique()
                ->each(function ($userId) use ($customers) {
                    \Illuminate\Support\Facades\DB::table('user_permissions')->updateOrInsert(
                        ['user_id' => $userId, 'feature_id' => $customers->id],
                        ['can_access' => true, 'updated_at' => now(), 'created_at' => now()]
                    );
                });
        }

        if ($attendance) {
            \Illuminate\Support\Facades\DB::table('user_permissions')
                ->join('features', 'features.id', '=', 'user_permissions.feature_id')
                ->where('features.key', 'employees_management')
                ->where('user_permissions.can_access', true)
                ->orderBy('user_permissions.user_id')
                ->pluck('user_permissions.user_id')
                ->unique()
                ->each(function ($userId) use ($attendance) {
                    \Illuminate\Support\Facades\DB::table('user_permissions')->updateOrInsert(
                        ['user_id' => $userId, 'feature_id' => $attendance->id],
                        ['can_access' => true, 'updated_at' => now(), 'created_at' => now()]
                    );
                });
        }
    }

    public function down(): void
    {
        Feature::whereIn('key', ['customers', 'attendance'])->delete();

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn(['group', 'sort_order']);
        });
    }
};
