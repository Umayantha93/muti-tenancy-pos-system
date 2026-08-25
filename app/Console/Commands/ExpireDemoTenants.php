<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ExpireDemoTenants extends Command
{
    protected $signature = 'tenants:expire-demos';

    protected $description = 'Deactivate demo tenants whose 21-day access has ended.';

    public function handle(): int
    {
        $expired = Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('demo_ends_at')
            ->where('demo_ends_at', '<=', now())
            ->get();

        foreach ($expired as $tenant) {
            $tenant->expireDemoIfNeeded();
            $this->info("Deactivated demo tenant #{$tenant->id} {$tenant->business_name}");
        }

        $this->info($expired->count().' demo tenant(s) expired.');

        return self::SUCCESS;
    }
}
