<?php

namespace App\Services\Commission;

interface CommissionScheme
{
    public function key(): string;

    public function label(): string;

    public function summary(): string;

    /**
     * @return array<string, bool|int|float>
     */
    public function rules(): array;

    public function supportsJobDeduction(): bool;
}
