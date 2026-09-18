<?php

use App\Models\Feature;
use App\Models\Tenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'admit_repair', 'name' => 'Repair jobs', 'description' => 'Mechanical repair admissions. Nested under Admit vehicle. Garage only.', 'group' => 'Service Intake', 'sort_order' => 11],
            ['key' => 'admit_service', 'name' => 'Service jobs', 'description' => 'Periodic service admissions. Nested under Admit vehicle. Garage only.', 'group' => 'Service Intake', 'sort_order' => 12],
            ['key' => 'job_board', 'name' => 'Job status board', 'description' => 'Kanban of open jobs. Nested under Admit vehicle. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 13],
            ['key' => 'job_bookings', 'name' => 'Bay calendar', 'description' => 'Day view of bays, time slots, vehicles, and technicians. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 16],
            ['key' => 'service_reminders', 'name' => 'Next-service reminders', 'description' => 'SMS or WhatsApp before the next-service due date. Needs Service jobs and Bill SMS. Off until enabled.', 'group' => 'Service Intake', 'sort_order' => 32],
            ['key' => 'purchase_orders', 'name' => 'Purchase orders', 'description' => 'PO, partial receive, GRN, and payables. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 44],
            ['key' => 'part_fitment', 'name' => 'Part fitment / substitutes', 'description' => 'Cross-reference and vehicle fitment at the counter. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 45],
            ['key' => 'serial_inventory', 'name' => 'IMEI / serial stock', 'description' => 'Unique-unit phones and devices by IMEI or serial. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 46],
            ['key' => 'cash_up', 'name' => 'Day-end cash-up', 'description' => 'Cash, card, bank, and cheque vs drawer count per cashier and shop.', 'group' => 'Finance', 'sort_order' => 81],
        ];

        foreach ($rows as $row) {
            Feature::query()->updateOrCreate(['key' => $row['key']], $row);
        }

        Feature::query()->where('key', 'owner_bill_sms')->update(['sort_order' => 14]);
        Feature::query()->where('key', 'job_videos')->update(['sort_order' => 15]);

        $repair = Feature::query()->where('key', 'admit_repair')->first();
        $service = Feature::query()->where('key', 'admit_service')->first();
        $cashUp = Feature::query()->where('key', 'cash_up')->first();
        if (! $repair || ! $service) {
            return;
        }

        Tenant::query()->where('business_type', BusinessTypes::GARAGE)->each(function (Tenant $tenant) use ($repair, $service) {
            $hasAdmit = $tenant->features()->where('features.key', 'admit_vehicle')->wherePivot('is_enabled', true)->exists();
            if (! $hasAdmit) {
                return;
            }
            $tenant->features()->syncWithoutDetaching([
                $repair->id => ['is_enabled' => true],
                $service->id => ['is_enabled' => true],
            ]);
        });

        if ($cashUp) {
            Tenant::query()->whereIn('business_type', [BusinessTypes::STORE, BusinessTypes::MOBILE_SHOP])->each(function (Tenant $tenant) use ($cashUp) {
                $tenant->features()->syncWithoutDetaching([
                    $cashUp->id => ['is_enabled' => true],
                ]);
            });
        }
    }

    public function down(): void
    {
        // Keep catalog keys; turning them off in admin is enough.
    }
};
