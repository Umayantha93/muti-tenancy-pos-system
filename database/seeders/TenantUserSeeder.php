<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\LaborCategory;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use App\Support\PaintStockDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'superadmin@bay06.lk'], [
            'tenant_id' => null,
            'name' => 'Platform Administrator',
            'password' => Hash::make('Umayantha@1234'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        foreach ($this->tenants() as $tenantData) {
            $tenant = Tenant::updateOrCreate(['owner_email' => $tenantData['owner_email']], [
                'business_name' => $tenantData['business_name'],
                'business_type' => $tenantData['business_type'],
                'owner_name' => $tenantData['owner_name'],
                'owner_phone' => $tenantData['owner_phone'],
                'owner_phones' => [['label' => 'Primary', 'number' => $tenantData['owner_phone']]],
                'contact_email' => $tenantData['owner_email'],
                'contact_phone' => $tenantData['owner_phone'],
                'contact_phones' => [['label' => 'Business', 'number' => $tenantData['owner_phone']]],
                'status' => 'active',
                'plan' => $tenantData['plan'],
                'payment_plan' => $tenantData['payment_plan'],
                'plan_amount' => $tenantData['plan_amount'],
            ]);

            $tenant->features()->sync($this->featureMatrix($tenantData['business_type']));
            $branch = \App\Models\Branch::ensureDefault($tenant);

            if (BusinessTypes::usesVehicleJobs($tenantData['business_type'])) {
                ServiceAddon::seedDefaultsFor((int) $tenant->id, $tenantData['business_type']);
            }
            if (BusinessTypes::usesLaborCatalog($tenantData['business_type'])) {
                LaborCategory::seedDefaultsFor((int) $tenant->id, $tenantData['business_type']);
            }
            if ($tenantData['business_type'] === BusinessTypes::PAINT) {
                PaintStockDefaults::seedFor((int) $tenant->id);
            }

            User::updateOrCreate(['email' => $tenantData['owner_email']], [
                'tenant_id' => $tenant->id,
                'name' => $tenantData['owner_name'],
                'password' => Hash::make('password'),
                'role' => 'business_owner',
                'status' => 'active',
                'home_branch_id' => $branch->id,
                'last_branch_id' => $branch->id,
            ]);
        }
    }

    /**
     * @return array<int, array{business_name: string, business_type: string, owner_name: string, owner_phone: string, owner_email: string, plan: string, payment_plan: string, plan_amount: float}>
     */
    private function tenants(): array
    {
        return [
            [
                'business_name' => 'Bay 06 Garage',
                'business_type' => BusinessTypes::GARAGE,
                'owner_name' => 'Garage Owner',
                'owner_phone' => '0771234567',
                'owner_email' => 'admin@garage.lk',
                'plan' => 'garage-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 15000,
            ],
            [
                'business_name' => 'Lens & Light Studio',
                'business_type' => BusinessTypes::PHOTOGRAPHY,
                'owner_name' => 'Photo Owner',
                'owner_phone' => '0772234567',
                'owner_email' => 'owner@photo.lk',
                'plan' => 'studio-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 12000,
            ],
            [
                'business_name' => 'Thread & Co',
                'business_type' => BusinessTypes::CLOTHING,
                'owner_name' => 'Boutique Owner',
                'owner_phone' => '0777654321',
                'owner_email' => 'owner@clothing.lk',
                'plan' => 'retail-pro',
                'payment_plan' => 'yearly',
                'plan_amount' => 120000,
            ],
            [
                'business_name' => 'Hillside Cottages',
                'business_type' => BusinessTypes::COTTAGE,
                'owner_name' => 'Cottage Owner',
                'owner_phone' => '0779988776',
                'owner_email' => 'owner@cottage.lk',
                'plan' => 'stay-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 18000,
            ],
            [
                'business_name' => 'MyDearShop',
                'business_type' => BusinessTypes::STORE,
                'owner_name' => 'Charls',
                'owner_phone' => '0771002003',
                'owner_email' => 'charlsjayasundara@gmail.com',
                'plan' => 'store-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 15000,
            ],
            [
                'business_name' => 'SpeedFix Auto Care',
                'business_type' => BusinessTypes::GARAGE,
                'owner_name' => 'SpeedFix Owner',
                'owner_phone' => '0772003004',
                'owner_email' => 'owner@speedfix.lk',
                'plan' => 'garage-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 15000,
            ],
            [
                'business_name' => 'Mr Paint',
                'business_type' => BusinessTypes::PAINT,
                'owner_name' => 'Paint Owner',
                'owner_phone' => '0773004005',
                'owner_email' => 'owner@paint.lk',
                'plan' => 'paint-pro',
                'payment_plan' => 'monthly',
                'plan_amount' => 15000,
            ],
        ];
    }

    /**
     * @return array<int, array{is_enabled: bool}>
     */
    private function featureMatrix(string $businessType): array
    {
        $keys = BusinessTypes::defaults($businessType);
        if ($businessType === BusinessTypes::STORE) {
            $keys[] = 'warranties';
        }

        return Feature::whereIn('key', $keys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['is_enabled' => true]])
            ->all();
    }
}
