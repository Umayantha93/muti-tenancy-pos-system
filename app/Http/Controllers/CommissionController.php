<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Commission\CommissionSchemes;
use App\Services\Commission\LaborExcessCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CommissionController extends Controller
{
    public function month(Request $request, LaborExcessCommission $commission): JsonResponse
    {
        $this->guard($request);
        $period = $this->period($request);

        return $this->moneyJson($commission->month((int) $period->year, (int) $period->month));
    }

    public function days(Request $request, Employee $employee, LaborExcessCommission $commission): JsonResponse
    {
        $this->guard($request);
        $period = $this->period($request);

        return $this->moneyJson($commission->days($employee, (int) $period->year, (int) $period->month));
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->canAccessFeature('job_commissions'), 403, 'Job commission is not enabled for this account.');
        abort_unless(
            CommissionSchemes::for($request->user()->tenant)->key() === CommissionSchemes::LABOR_EXCESS,
            422,
            'This account uses a different commission type.',
        );
    }

    private function period(Request $request): Carbon
    {
        $month = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ])['month'] ?? now()->format('Y-m');

        return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
    }
}
