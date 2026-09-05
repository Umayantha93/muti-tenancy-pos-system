<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BranchQuery
{
    /**
     * Shop id for reads. Null means the owner asked for every shop.
     */
    public static function idForRead(?Request $request = null): ?int
    {
        $request ??= request();
        $user = $request?->user();
        if (! $user || $user->role === 'super_admin') {
            $raw = $request?->input('branch_id');

            return is_numeric($raw) ? (int) $raw : BranchContext::id();
        }
        if ($user->role === 'staff') {
            return BranchContext::id();
        }

        $raw = $request?->input('branch_id');
        if ($raw === 'all' || $raw === '0') {
            return null;
        }
        if (is_numeric($raw)) {
            $id = (int) $raw;
            abort_unless(
                Branch::query()->where('tenant_id', $user->tenant_id)->whereKey($id)->exists(),
                404,
                'Shop not found.',
            );

            return $id;
        }

        return BranchContext::id();
    }

    public static function constrain(Builder $query, string $column = 'branch_id'): Builder
    {
        $id = self::idForRead();
        if ($id !== null) {
            $query->where($query->qualifyColumn($column), $id);
        }

        return $query;
    }

    public static function constrainViaBill(Builder $query, string $relation = 'bill'): Builder
    {
        $id = self::idForRead();
        if ($id !== null) {
            $query->whereHas($relation, fn (Builder $bill) => $bill->where('branch_id', $id));
        }

        return $query;
    }

    public static function constrainViaEmployee(Builder $query): Builder
    {
        $id = self::idForRead();
        if ($id !== null) {
            $query->where(function (Builder $nested) use ($id) {
                $nested->where('branch_id', $id)
                    ->orWhereHas('employee', fn (Builder $employee) => $employee->where('home_branch_id', $id));
            });
        }

        return $query;
    }
}
