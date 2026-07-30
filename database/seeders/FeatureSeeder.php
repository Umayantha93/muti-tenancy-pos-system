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
     * @return array<int, array{key: string, name: string, description: string}>
     */
    private function features(): array
    {
        return [
            ['key' => 'admit_vehicle', 'name' => 'Service Intake', 'description' => 'Vehicle admission and job cards'],
            ['key' => 'billing', 'name' => 'Billing', 'description' => 'Charges, deductions and payments'],
            ['key' => 'payroll', 'name' => 'Payroll', 'description' => 'Attendance-based monthly payroll'],
            ['key' => 'balance_sheet', 'name' => 'Finance', 'description' => 'Income, expenses and profit'],
            ['key' => 'parts_inventory', 'name' => 'Inventory', 'description' => 'Parts and product stock'],
            ['key' => 'employees_management', 'name' => 'Employees', 'description' => 'Employee and attendance management'],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Business reporting and trends'],
        ];
    }
}
