<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Part;
use App\Models\Payroll;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PosDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $owners = User::with('tenant')
            ->where('role', 'business_owner')
            ->where('status', 'active')
            ->get();

        Model::unguarded(function () use ($owners): void {
            foreach ($owners as $owner) {
                if (! $owner->tenant_id) {
                    continue;
                }
                if ($owner->tenant?->business_type === \App\Support\BusinessTypes::STORE) {
                    continue;
                }

                $tenantId = (int) $owner->tenant_id;
                $customers = $this->seedCustomers($tenantId);
                $vehicles = $this->seedVehicles($tenantId, $customers);
                $parts = $this->seedParts($tenantId);
                $employees = $this->seedEmployees($tenantId);

                $this->seedAttendanceAndPayroll($tenantId, $owner->id, $employees);
                $this->seedExpenses($tenantId, $owner->id);
                $this->seedBills($tenantId, $owner->id, $customers, $vehicles, $parts);
            }
        });
    }

    private function seedCustomers(int $tenantId)
    {
        return collect([
            ['name' => 'Nimal Perera', 'phone' => "077{$tenantId}1001", 'address' => 'Colombo 05'],
            ['name' => 'Amara Silva', 'phone' => "077{$tenantId}1002", 'address' => 'Maharagama'],
            ['name' => 'Kasun Fernando', 'phone' => "077{$tenantId}1003", 'address' => 'Nugegoda'],
            ['name' => 'Ishara Jayasuriya', 'phone' => "077{$tenantId}1004", 'address' => 'Kottawa'],
        ])->map(fn (array $customer) => Customer::updateOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $customer['phone']],
            ['tenant_id' => $tenantId, ...$customer]
        ))->values();
    }

    private function seedVehicles(int $tenantId, $customers)
    {
        $vehicleDefinitions = [
            ['number_plate' => sprintf('CAB-%03d', $tenantId), 'chassis' => sprintf('CHS%03dA1001', $tenantId), 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2018],
            ['number_plate' => sprintf('CAR-%03d', $tenantId), 'chassis' => sprintf('CHS%03dA1002', $tenantId), 'make' => 'Nissan', 'model' => 'Sunny', 'year' => 2017],
            ['number_plate' => sprintf('CAD-%03d', $tenantId), 'chassis' => sprintf('CHS%03dA1003', $tenantId), 'make' => 'Honda', 'model' => 'Vezel', 'year' => 2019],
            ['number_plate' => sprintf('CAA-%03d', $tenantId), 'chassis' => sprintf('CHS%03dA1004', $tenantId), 'make' => 'Suzuki', 'model' => 'WagonR', 'year' => 2020],
        ];

        return $customers->values()->map(function (Customer $customer, int $index) use ($tenantId, $vehicleDefinitions) {
            $vehicle = $vehicleDefinitions[$index];

            return Vehicle::updateOrCreate(
                ['tenant_id' => $tenantId, 'chassis_number' => $vehicle['chassis']],
                [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customer->id,
                    'number_plate' => $vehicle['number_plate'],
                    'chassis_number' => $vehicle['chassis'],
                    'make' => $vehicle['make'],
                    'model' => $vehicle['model'],
                    'year' => $vehicle['year'],
                ]
            );
        })->values();
    }

    private function seedParts(int $tenantId)
    {
        $parts = [
            ['name' => 'Engine Oil 5W-30', 'brand' => 'Castrol', 'type' => 'Lubricant', 'model' => null, 'year' => null, 'price' => 5200.00, 'cost_price' => 4100.00, 'stock_qty' => 50],
            ['name' => 'Oil Filter', 'brand' => 'Bosch', 'type' => 'Filter', 'model' => 'Corolla', 'year' => 2018, 'price' => 3200.00, 'cost_price' => 2400.00, 'stock_qty' => 30],
            ['name' => 'Air Filter', 'brand' => 'Denso', 'type' => 'Filter', 'model' => 'Sunny', 'year' => 2017, 'price' => 3500.00, 'cost_price' => 2700.00, 'stock_qty' => 20],
            ['name' => 'Brake Pads', 'brand' => 'Nissin', 'type' => 'Braking', 'model' => 'Vezel', 'year' => 2019, 'price' => 7800.00, 'cost_price' => 6200.00, 'stock_qty' => 18],
            ['name' => 'Coolant 1L', 'brand' => 'Prestone', 'type' => 'Fluids', 'model' => null, 'year' => null, 'price' => 2500.00, 'cost_price' => 1900.00, 'stock_qty' => 40],
        ];

        return collect($parts)->map(function (array $part, int $index) use ($tenantId) {
            $sku = sprintf('T%03d-P%03d', $tenantId, $index + 1);

            return Part::updateOrCreate(
                ['tenant_id' => $tenantId, 'sku' => $sku],
                ['tenant_id' => $tenantId, 'sku' => $sku, ...$part]
            );
        })->values();
    }

    private function seedEmployees(int $tenantId)
    {
        $employees = [
            ['name' => 'Lakshan Weerasinghe', 'nic' => sprintf('1990%03d123V', $tenantId), 'phone' => sprintf('071%03d2001', $tenantId), 'position' => 'Technician', 'base_salary' => 85000.00, 'overtime_hourly_rate' => 800.00, 'fingerprint_id' => sprintf('FP-%03d-01', $tenantId)],
            ['name' => 'Dinuka Ramanayake', 'nic' => sprintf('1992%03d456V', $tenantId), 'phone' => sprintf('071%03d2002', $tenantId), 'position' => 'Supervisor', 'base_salary' => 98000.00, 'overtime_hourly_rate' => 900.00, 'fingerprint_id' => sprintf('FP-%03d-02', $tenantId)],
        ];

        return collect($employees)->map(fn (array $employee) => Employee::updateOrCreate(
            ['tenant_id' => $tenantId, 'nic' => $employee['nic']],
            ['tenant_id' => $tenantId, ...$employee, 'active' => true]
        ))->values();
    }

    private function seedAttendanceAndPayroll(int $tenantId, int $ownerId, $employees): void
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        foreach ($employees as $employee) {
            $totalHours = 0.0;
            $totalOvertime = 0.0;

            for ($dayOffset = 1; $dayOffset <= 5; $dayOffset++) {
                $date = now()->subDays(6 - $dayOffset)->toDateString();
                $checkIn = Carbon::parse("{$date} 08:30:00");
                $workedHours = 8.0 + ($dayOffset % 2 === 0 ? 1.5 : 0.5);
                $overtime = max(0.0, $workedHours - 8.0);
                $checkOut = $checkIn->copy()->addHours((int) floor($workedHours))->addMinutes((int) (($workedHours - floor($workedHours)) * 60));

                Attendance::updateOrCreate(
                    ['employee_id' => $employee->id, 'date' => $date],
                    [
                        'tenant_id' => $tenantId,
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'hours_worked' => $workedHours,
                        'overtime_hours' => $overtime,
                        'source' => 'fingerprint',
                    ]
                );

                $totalHours += $workedHours;
                $totalOvertime += $overtime;
            }

            $overtimePay = round($totalOvertime * (float) $employee->overtime_hourly_rate, 2);
            $netSalary = round((float) $employee->base_salary + $overtimePay, 2);

            Payroll::updateOrCreate(
                ['employee_id' => $employee->id, 'month' => $month, 'year' => $year],
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'month' => $month,
                    'year' => $year,
                    'days_present' => 5,
                    'days_absent' => 0,
                    'hours_worked' => $totalHours,
                    'overtime_hours' => $totalOvertime,
                    'base_salary' => $employee->base_salary,
                    'overtime_pay' => $overtimePay,
                    'bonus' => 0,
                    'deductions' => 0,
                    'net_salary' => $netSalary,
                    'generated_at' => now(),
                    'generated_by' => $ownerId,
                ]
            );
        }
    }

    private function seedExpenses(int $tenantId, int $ownerId): void
    {
        $expenses = [
            ['category' => 'Utilities', 'description' => 'Electricity bill', 'amount' => 18500.00, 'expense_date' => now()->startOfMonth()->addDays(2)->toDateString()],
            ['category' => 'Supplies', 'description' => 'Workshop consumables', 'amount' => 12250.00, 'expense_date' => now()->startOfMonth()->addDays(5)->toDateString()],
        ];

        foreach ($expenses as $expense) {
            Expense::updateOrCreate(
                ['tenant_id' => $tenantId, 'description' => $expense['description'], 'expense_date' => $expense['expense_date']],
                [
                    'tenant_id' => $tenantId,
                    'category' => $expense['category'],
                    'description' => $expense['description'],
                    'amount' => $expense['amount'],
                    'expense_date' => $expense['expense_date'],
                    'created_by' => $ownerId,
                    'updated_by' => $ownerId,
                ]
            );
        }
    }

    private function seedBills(int $tenantId, int $ownerId, $customers, $vehicles, $parts): void
    {
        for ($index = 0; $index < 2; $index++) {
            $customer = $customers[$index];
            $vehicle = $vehicles[$index];
            $part = $parts[$index];
            $billNumber = sprintf('INV-%03d-%04d', $tenantId, $index + 1);

            $items = [
                [
                    'part_id' => $part->id,
                    'type' => 'part',
                    'description' => $part->name,
                    'quantity' => 1,
                    'unit_price' => (float) $part->price,
                ],
                [
                    'part_id' => null,
                    'type' => 'labor',
                    'description' => 'Labor charge',
                    'quantity' => 1,
                    'unit_price' => 4500.00,
                ],
            ];

            $subtotal = collect($items)->sum(fn (array $item) => $item['quantity'] * $item['unit_price']);
            $amountPaid = $index === 0 ? $subtotal : round($subtotal * 0.6, 2);
            $balanceDue = round($subtotal - $amountPaid, 2);

            $bill = Bill::updateOrCreate(
                ['tenant_id' => $tenantId, 'bill_number' => $billNumber],
                [
                    'tenant_id' => $tenantId,
                    'bill_number' => $billNumber,
                    'vehicle_id' => $vehicle->id,
                    'customer_id' => $customer->id,
                    'admission_date' => now()->subDays($index + 1)->toDateString(),
                    'odometer' => 120000 + (($tenantId * 100) + $index),
                    'notes' => 'Routine service and quality checks',
                    'status' => $balanceDue > 0 ? 'open' : 'paid',
                    'subtotal' => $subtotal,
                    'total_deductions' => 0,
                    'amount_paid' => $amountPaid,
                    'balance_due' => $balanceDue,
                    'created_by' => $ownerId,
                    'updated_by' => $ownerId,
                ]
            );

            foreach ($items as $item) {
                $lineTotal = round($item['quantity'] * $item['unit_price'], 2);
                BillItem::updateOrCreate(
                    ['tenant_id' => $tenantId, 'bill_id' => $bill->id, 'description' => $item['description']],
                    [
                        'tenant_id' => $tenantId,
                        'bill_id' => $bill->id,
                        'part_id' => $item['part_id'],
                        'type' => $item['type'],
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $lineTotal,
                    ]
                );
            }

            BillPayment::updateOrCreate(
                ['tenant_id' => $tenantId, 'bill_id' => $bill->id, 'reference' => "PAY-{$billNumber}"],
                [
                    'tenant_id' => $tenantId,
                    'bill_id' => $bill->id,
                    'amount' => $amountPaid,
                    'method' => 'cash',
                    'reference' => "PAY-{$billNumber}",
                    'paid_at' => now()->subDays($index),
                    'received_by' => $ownerId,
                ]
            );
        }
    }
}
