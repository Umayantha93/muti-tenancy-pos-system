<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'superadmin@bay06.lk'], [
            'tenant_id' => null,
            'name' => 'Platform Administrator',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        foreach ($this->tenants() as $tenantData) {
            $tenant = Tenant::updateOrCreate(['owner_email' => $tenantData['owner_email']], [
                'business_name' => $tenantData['business_name'],
                'business_type' => $tenantData['business_type'],
                'owner_name' => $tenantData['owner_name'],
                'owner_phone' => $tenantData['owner_phone'],
                'contact_email' => $tenantData['owner_email'],
                'contact_phone' => $tenantData['owner_phone'],
                'status' => 'active',
                'plan' => $tenantData['plan'],
            ]);

            $tenant->features()->sync($this->featureMatrix($tenantData['business_type']));

            User::updateOrCreate(['email' => $tenantData['owner_email']], [
                'tenant_id' => $tenant->id,
                'name' => $tenantData['owner_name'],
                'password' => Hash::make('password'),
                'role' => 'business_owner',
                'status' => 'active',
            ]);
        }
    }

    /**
     * @return array<int, array{business_name: string, business_type: string, owner_name: string, owner_phone: string, owner_email: string, plan: string}>
     */
    private function tenants(): array
    {
        return [
            [
                'business_name' => 'Bay 06 Garage',
                'business_type' => 'garage',
                'owner_name' => 'Garage Owner',
                'owner_phone' => '0771234567',
                'owner_email' => 'admin@garage.lk',
                'plan' => 'garage-pro',
            ],
            [
                'business_name' => 'Bay 06 Spare Hub',
                'business_type' => 'shop',
                'owner_name' => 'Spare Parts Owner',
                'owner_phone' => '0772234567',
                'owner_email' => 'owner2@garage.lk',
                'plan' => 'retail-pro',
            ],
            [
                'business_name' => 'Bay 06 Supermarket',
                'business_type' => 'supermarket',
                'owner_name' => 'Supermarket Owner',
                'owner_phone' => '0777654321',
                'owner_email' => 'owner@supermarket.lk',
                'plan' => 'retail-pro',
            ],
        ];
    }

    /**
     * @return array<int, array{is_enabled: bool}>
     */
    private function featureMatrix(string $businessType): array
    {
        $keys = $businessType === 'garage'
            ? ['admit_vehicle', 'billing', 'payroll', 'balance_sheet', 'parts_inventory', 'employees_management', 'reports']
            : ['billing', 'parts_inventory', 'reports', 'balance_sheet'];

        return Feature::whereIn('key', $keys)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['is_enabled' => true]])->all();
    }
}
