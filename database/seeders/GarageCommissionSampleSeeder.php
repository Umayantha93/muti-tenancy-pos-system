<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BillCalculator;
use App\Services\Commission\LaborExcessCommission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sample monthly staff and paid job cards for tenant 8 (admin@garage.lk).
 *
 * Kasun Perera, salary 50,000, repair labor 80,000 → commission 3,000.
 * Nadeesha Silva, salary 45,000, service labor counted 10,000 → no commission.
 * Ruwan Fernando, salary 40,000, labor counted 45,000 → commission 500.
 */
class GarageCommissionSampleSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->find(8);
        $owner = User::query()->where('tenant_id', 8)->where('email', 'admin@garage.lk')->first();
        if (! $tenant || $tenant->owner_email !== 'admin@garage.lk' || ! $owner) {
            throw new RuntimeException('Tenant 8 (admin@garage.lk) was not found.');
        }

        Auth::login($owner);
        $tenant->update(['commission_type' => 'labor_excess']);

        DB::transaction(function () use ($tenant, $owner) {
            Bill::query()->where('internal_notes', 'commission-sample')->get()->each->delete();

            $kasun = $this->employee('199503150801V', 'Kasun Perera', 'Mechanic', 50000, '0771800001');
            $nadeesha = $this->employee('199608220802V', 'Nadeesha Silva', 'Service technician', 45000, '0771800002');
            $ruwan = $this->employee('199401110803V', 'Ruwan Fernando', 'Technician', 40000, '0771800003');

            $customer = Customer::query()->firstOrCreate(
                ['phone' => '0771800099'],
                ['name' => 'Commission Sample', 'sms_opt_in' => false],
            );

            $jobs = [
                $this->card(2, 'repair', 'CMS-8001', [$kasun->id], true, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Clutch replacement', 'unit_price' => 25000, 'employee_id' => $kasun->id],
                    ['type' => 'part', 'section' => 'repair', 'description' => 'Clutch kit', 'unit_price' => 8000, 'employee_id' => null],
                ]),
                $this->card(8, 'repair', 'CMS-8002', [$kasun->id], true, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Engine labor', 'unit_price' => 35000, 'employee_id' => $kasun->id],
                ]),
                $this->card(11, 'both', 'CMS-8003', [$kasun->id, $nadeesha->id], true, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Gearbox labor', 'unit_price' => 10000, 'employee_id' => $kasun->id],
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Body wash', 'unit_price' => 2000, 'employee_id' => $nadeesha->id],
                ]),
                $this->card(16, 'repair', 'CMS-8004', [$kasun->id], true, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Suspension labor', 'unit_price' => 10000, 'employee_id' => $kasun->id],
                ]),
                $this->card(19, 'repair', 'CMS-8005', [$kasun->id], false, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Unpaid engine labor', 'unit_price' => 50000, 'employee_id' => $kasun->id],
                ]),
                $this->card(3, 'service', 'CMS-8006', [$nadeesha->id], true, [
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Body wash', 'unit_price' => 1000, 'employee_id' => $nadeesha->id],
                ]),
                $this->card(6, 'service', 'CMS-8007', [$nadeesha->id], true, [
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Body wash', 'unit_price' => 1000, 'employee_id' => $nadeesha->id],
                ]),
                $this->card(18, 'service', 'CMS-8008', [$nadeesha->id], true, [
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Full service', 'unit_price' => 16000, 'employee_id' => $nadeesha->id],
                ]),
                $this->card(5, 'repair', 'CMS-8009', [$ruwan->id], true, [
                    ['type' => 'labor', 'section' => 'repair', 'description' => 'Brake labor', 'unit_price' => 30000, 'employee_id' => $ruwan->id],
                ]),
                $this->card(14, 'service', 'CMS-8010', [$ruwan->id], true, [
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Detailing', 'unit_price' => 20000, 'employee_id' => $ruwan->id],
                ]),
                $this->card(21, 'service', 'CMS-8011', [$ruwan->id], true, [
                    ['type' => 'service_addon', 'section' => 'service', 'description' => 'Body wash', 'unit_price' => 10000, 'employee_id' => $ruwan->id],
                ]),
            ];

            $calculator = app(BillCalculator::class);
            foreach ($jobs as $job) {
                $this->openJob($tenant, $owner, $customer, $calculator, $job);
            }
        });

        $report = app(LaborExcessCommission::class)->month((int) now()->year, (int) now()->month);
        $byName = collect($report['employees'])->keyBy('name');
        $checks = [
            'Kasun Perera' => ['pool' => 80000.0, 'commission' => 3000.0, 'has' => true],
            'Nadeesha Silva' => ['pool' => 10000.0, 'commission' => 0.0, 'has' => false],
            'Ruwan Fernando' => ['pool' => 45000.0, 'commission' => 500.0, 'has' => true],
        ];
        foreach ($checks as $name => $expect) {
            $row = $byName->get($name);
            $pool = round((float) ($row['labor_pool'] ?? -1), 2);
            $commission = round((float) ($row['commission'] ?? -1), 2);
            $has = (bool) ($row['has_commission'] ?? false);
            if (! $row || $pool !== $expect['pool'] || $commission !== $expect['commission'] || $has !== $expect['has']) {
                throw new RuntimeException($name.' sample commission did not match. Got '.json_encode($row));
            }
            $this->command?->info(sprintf('%s labor %s commission %s', $name, number_format($pool, 2), $has ? number_format($commission, 2) : 'none'));
        }

        Auth::logout();
    }

    private function employee(string $nic, string $name, string $position, float $salary, string $phone): Employee
    {
        return Employee::query()->updateOrCreate(
            ['nic' => $nic],
            [
                'name' => $name,
                'position' => $position,
                'base_salary' => $salary,
                'pay_type' => 'monthly',
                'phone' => $phone,
                'active' => true,
            ],
        );
    }

    /**
     * @param  list<int>  $team
     * @param  list<array{type: string, section: string, description: string, unit_price: float|int, employee_id: int|null}>  $lines
     * @return array<string, mixed>
     */
    private function card(int $day, string $kind, string $plate, array $team, bool $paid, array $lines): array
    {
        return [
            'date' => now()->startOfMonth()->day($day)->toDateString(),
            'kind' => $kind,
            'plate' => $plate,
            'team' => $team,
            'paid' => $paid,
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function openJob(Tenant $tenant, User $owner, Customer $customer, BillCalculator $calculator, array $job): void
    {
        $vehicle = Vehicle::query()->updateOrCreate(
            ['number_plate' => $job['plate']],
            [
                'customer_id' => $customer->id,
                'asset_kind' => 'vehicle',
                'make' => 'Toyota',
                'model' => 'Aqua',
            ],
        );
        $number = $tenant->claimNextBillNumber();
        if (! $number) {
            throw new RuntimeException('Tenant 8 has no locked bill numbers.');
        }

        $bill = Bill::query()->create([
            'bill_number' => $number,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'admission_date' => $job['date'],
            'job_kind' => $job['kind'],
            'status' => 'open',
            'notes' => 'Sample job for commission',
            'internal_notes' => 'commission-sample',
            'created_by' => $owner->id,
            'branch_id' => 9,
            'vat_rate' => 0,
            'sscl_rate' => 0,
        ]);
        $bill->employees()->sync(collect($job['team'])->mapWithKeys(fn (int $id) => [
            $id => ['tenant_id' => $tenant->id],
        ])->all());

        foreach ($job['lines'] as $line) {
            BillItem::query()->create([
                'bill_id' => $bill->id,
                'type' => $line['type'],
                'section' => $line['section'],
                'employee_id' => $line['employee_id'],
                'description' => $line['description'],
                'quantity' => 1,
                'unit_price' => $line['unit_price'],
                'line_discount' => 0,
                'line_total' => $line['unit_price'],
            ]);
        }

        $calculator->recalculate($bill->fresh());
        if (! $job['paid']) {
            return;
        }

        $due = (float) $bill->fresh()->balance_due;
        BillPayment::query()->create([
            'bill_id' => $bill->id,
            'amount' => $due,
            'method' => 'cash',
            'paid_at' => $job['date'].' 12:00:00',
            'received_by' => $owner->id,
        ]);
        $calculator->recalculate($bill->fresh());
    }
}
