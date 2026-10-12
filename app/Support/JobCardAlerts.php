<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\JobCardAlert;
use App\Models\User;

class JobCardAlerts
{
    /**
     * Tell staff with Job cards that an admit-only login opened a job.
     */
    public static function notifyDesk(Bill $bill, User $actor): void
    {
        if ($bill->vehicle_id === null || $actor->canAccessFeature('billing') || ! $actor->tenant_id) {
            return;
        }

        $tenantHasBilling = $actor->tenant?->features()
            ->where('features.key', 'billing')
            ->wherePivot('is_enabled', true)
            ->exists() ?? false;
        if (! $tenantHasBilling) {
            return;
        }

        $billingId = Feature::query()->where('key', 'billing')->value('id');
        if (! $billingId) {
            return;
        }

        $recipients = User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('status', 'active')
            ->whereKeyNot($actor->id)
            ->where('role', 'staff')
            ->whereHas('permissions', function ($permissions) use ($billingId) {
                $permissions->where('features.id', $billingId)
                    ->where('user_permissions.can_access', true);
            })
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $now = now();
        $plate = $bill->vehicle?->number_plate;
        JobCardAlert::insert($recipients->map(fn (int $userId) => [
            'tenant_id' => $actor->tenant_id,
            'user_id' => $userId,
            'bill_id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'vehicle_label' => $plate,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }
}
