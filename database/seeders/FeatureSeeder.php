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
            ['key' => 'customers', 'name' => 'Customers', 'description' => 'Customer directory and job history', 'group' => 'Service Intake', 'sort_order' => 20],
            ['key' => 'billing', 'name' => 'Job cards', 'description' => 'Job cards, charges, and payments', 'group' => 'Service Intake', 'sort_order' => 30],
            ['key' => 'parts_inventory', 'name' => 'Parts inventory', 'description' => 'Parts and product stock', 'group' => 'Inventory', 'sort_order' => 40],
            ['key' => 'employees_management', 'name' => 'Team', 'description' => 'Employee profiles and records', 'group' => 'People', 'sort_order' => 50],
            ['key' => 'attendance', 'name' => 'Attendance', 'description' => 'Punch and monthly attendance', 'group' => 'People', 'sort_order' => 60],
            ['key' => 'payroll', 'name' => 'Payroll', 'description' => 'Attendance-based monthly payroll', 'group' => 'People', 'sort_order' => 70],
            ['key' => 'balance_sheet', 'name' => 'Finance', 'description' => 'Income, expenses and profit', 'group' => 'Finance', 'sort_order' => 80],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Business reporting and trends', 'group' => 'Finance', 'sort_order' => 90],
        ];
    }
}
