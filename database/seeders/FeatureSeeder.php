<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->features() as $feature) {
            Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, group: string, sort_order: int}>
     */
    private function features(): array
    {
        return [
            ['key' => 'admit_vehicle', 'name' => 'Admit vehicle', 'description' => 'Admit vehicles and open job cards', 'group' => 'Service Intake', 'sort_order' => 10],
            ['key' => 'admit_repair', 'name' => 'Repair jobs', 'description' => 'Mechanical repair admissions. Nested under Admit vehicle. Garage only.', 'group' => 'Service Intake', 'sort_order' => 11],
            ['key' => 'admit_service', 'name' => 'Service jobs', 'description' => 'Periodic service admissions. Nested under Admit vehicle. Garage only.', 'group' => 'Service Intake', 'sort_order' => 12],
            ['key' => 'job_board', 'name' => 'Job status board', 'description' => 'Kanban of open jobs. Nested under Admit vehicle. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 13],
            ['key' => 'owner_bill_sms', 'name' => 'Owner bill SMS', 'description' => 'When staff send a bill SMS, also send a copy of the same link to the shop owner. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 14],
            ['key' => 'job_videos', 'name' => 'Job videos', 'description' => 'Up to 5 compressed clips on a garage job. Staff-only. Deleted after 6 months. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 15],
            ['key' => 'job_bookings', 'name' => 'Bay calendar', 'description' => 'Day view of bays, time slots, vehicles, and technicians. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 16],
            ['key' => 'photo_bookings', 'name' => 'Bookings', 'description' => 'Photography session bookings', 'group' => 'Service Intake', 'sort_order' => 17],
            ['key' => 'photo_packages', 'name' => 'Packages', 'description' => 'Photography packages and pricing', 'group' => 'Service Intake', 'sort_order' => 18],
            ['key' => 'retail_pos', 'name' => 'Point of sale', 'description' => 'Quick clothing sales at the counter', 'group' => 'Service Intake', 'sort_order' => 19],
            ['key' => 'cottage_stays', 'name' => 'Stays', 'description' => 'Cottage bookings and check-in', 'group' => 'Service Intake', 'sort_order' => 21],
            ['key' => 'customers', 'name' => 'Customers', 'description' => 'Customer directory and history', 'group' => 'Service Intake', 'sort_order' => 20],
            ['key' => 'billing', 'name' => 'Billing', 'description' => 'Orders, charges, and payments', 'group' => 'Service Intake', 'sort_order' => 30],
            ['key' => 'bill_sms', 'name' => 'Bill SMS', 'description' => 'Send quotation / paid bill links to customers by SMS', 'group' => 'Service Intake', 'sort_order' => 31],
            ['key' => 'service_reminders', 'name' => 'Next-service reminders', 'description' => 'SMS or WhatsApp before the next-service due date. Needs Service jobs and Bill SMS. Off until enabled.', 'group' => 'Service Intake', 'sort_order' => 32],
            ['key' => 'bill_profits', 'name' => 'Bill Profits Analysis', 'description' => 'Bill revenue, inventory cost, and credit-bill profit reporting', 'group' => 'Service Intake', 'sort_order' => 33],
            ['key' => 'repair_bills', 'name' => 'Repair', 'description' => 'Repair bills and repair profit for stores (phones, parts counters). Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 34],
            ['key' => 'warranties', 'name' => 'Warranties', 'description' => 'Add a warranty on the job or sale in months or years.', 'group' => 'Service Intake', 'sort_order' => 35],
            ['key' => 'parts_inventory', 'name' => 'Parts inventory', 'description' => 'Garage parts and stock', 'group' => 'Inventory', 'sort_order' => 40],
            ['key' => 'product_catalog', 'name' => 'Product catalog', 'description' => 'Clothing SKUs, sizes, and stock', 'group' => 'Inventory', 'sort_order' => 41],
            ['key' => 'cottage_rooms', 'name' => 'Rooms', 'description' => 'Cottage rooms and rates', 'group' => 'Inventory', 'sort_order' => 42],
            ['key' => 'suppliers', 'name' => 'Suppliers', 'description' => 'Supplier directory and goods received notes', 'group' => 'Inventory', 'sort_order' => 43],
            ['key' => 'purchase_orders', 'name' => 'Purchase orders', 'description' => 'PO, partial receive, GRN, and payables. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 44],
            ['key' => 'part_fitment', 'name' => 'Part fitment / substitutes', 'description' => 'Cross-reference and vehicle fitment at the counter. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 45],
            ['key' => 'serial_inventory', 'name' => 'IMEI / serial stock', 'description' => 'Unique-unit phones and devices by IMEI or serial. Off until super-admin enables it.', 'group' => 'Inventory', 'sort_order' => 46],
            ['key' => 'employees_management', 'name' => 'Team', 'description' => 'Employee profiles and records', 'group' => 'People', 'sort_order' => 50],
            ['key' => 'attendance', 'name' => 'Attendance', 'description' => 'Punch and monthly attendance', 'group' => 'People', 'sort_order' => 60],
            ['key' => 'payroll', 'name' => 'Payroll', 'description' => 'Attendance-based monthly payroll', 'group' => 'People', 'sort_order' => 70],
            ['key' => 'balance_sheet', 'name' => 'Finance', 'description' => 'Income, expenses and profit', 'group' => 'Finance', 'sort_order' => 80],
            ['key' => 'cash_up', 'name' => 'Day-end cash-up', 'description' => 'Cash, card, bank, and cheque vs drawer count per cashier and shop.', 'group' => 'Finance', 'sort_order' => 81],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Business reporting and trends', 'group' => 'Finance', 'sort_order' => 90],
            ['key' => 'service_ops_report', 'name' => 'Service operations report', 'description' => 'Count billed garage service addons (sold qty vs inside full service) with revenue. Off until super-admin enables it.', 'group' => 'Finance', 'sort_order' => 91],
        ];
    }
}
