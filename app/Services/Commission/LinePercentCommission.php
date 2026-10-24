<?php

namespace App\Services\Commission;

/**
 * Earlier type: a percent of each technician's labor, deducted on the job card.
 * Kept so a tenant can be switched back without losing the calculation.
 */
class LinePercentCommission implements CommissionScheme
{
    public function key(): string
    {
        return CommissionSchemes::LINE_PERCENT;
    }

    public function label(): string
    {
        return 'Percent of labor';
    }

    public function summary(): string
    {
        return 'Each technician has a percent of their own labor and service. That amount is deducted on the job card.';
    }

    public function rules(): array
    {
        return [
            'uses_employee_percent' => true,
        ];
    }

    public function supportsJobDeduction(): bool
    {
        return true;
    }
}
