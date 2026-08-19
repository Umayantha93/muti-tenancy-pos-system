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
            ['key' => 'photo_bookings', 'name' => 'Bookings', 'description' => 'Photography session bookings', 'group' => 'Service Intake', 'sort_order' => 11],
            ['key' => 'photo_packages', 'name' => 'Packages', 'description' => 'Photography packages and pricing', 'group' => 'Service Intake', 'sort_order' => 12],
            ['key' => 'retail_pos', 'name' => 'Point of sale', 'description' => 'Quick clothing sales at the counter', 'group' => 'Service Intake', 'sort_order' => 13],
            ['key' => 'cottage_stays', 'name' => 'Stays', 'description' => 'Cottage bookings and check-in', 'group' => 'Service Intake', 'sort_order' => 14],
            ['key' => 'customers', 'name' => 'Customers', 'description' => 'Customer directory and history', 'group' => 'Service Intake', 'sort_order' => 20],
            ['key' => 'billing', 'name' => 'Billing', 'description' => 'Orders, charges, and payments', 'group' => 'Service Intake', 'sort_order' => 30],
            ['key' => 'bill_sms', 'name' => 'Bill SMS', 'description' => 'Send quotation / paid bill links to customers by SMS', 'group' => 'Service Intake', 'sort_order' => 31],
            ['key' => 'bill_profits', 'name' => 'Bill Profits Analysis', 'description' => 'Bill revenue, inventory cost, and credit-bill profit reporting', 'group' => 'Service Intake', 'sort_order' => 32],
            ['key' => 'parts_inventory', 'name' => 'Parts inventory', 'description' => 'Garage parts and stock', 'group' => 'Inventory', 'sort_order' => 40],
            ['key' => 'product_catalog', 'name' => 'Product catalog', 'description' => 'Clothing SKUs, sizes, and stock', 'group' => 'Inventory', 'sort_order' => 41],
            ['key' => 'cottage_rooms', 'name' => 'Rooms', 'description' => 'Cottage rooms and rates', 'group' => 'Inventory', 'sort_order' => 42],
            ['key' => 'employees_management', 'name' => 'Team', 'description' => 'Employee profiles and records', 'group' => 'People', 'sort_order' => 50],
            ['key' => 'attendance', 'name' => 'Attendance', 'description' => 'Punch and monthly attendance', 'group' => 'People', 'sort_order' => 60],
            ['key' => 'payroll', 'name' => 'Payroll', 'description' => 'Attendance-based monthly payroll', 'group' => 'People', 'sort_order' => 70],
            ['key' => 'balance_sheet', 'name' => 'Finance', 'description' => 'Income, expenses and profit', 'group' => 'Finance', 'sort_order' => 80],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Business reporting and trends', 'group' => 'Finance', 'sort_order' => 90],
        ];
    }
}
