<?php

use App\Models\LaborCategory;
use App\Models\Tenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()
            ->where('business_type', BusinessTypes::PAINT)
            ->pluck('id')
            ->each(function ($tenantId) {
                $names = LaborCategory::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->pluck('name');
                $isHourCatalog = $names->contains('Prep') && $names->contains('Paint') && $names->contains('Finish');
                if ($isHourCatalog) {
                    return;
                }

                $isPanelCatalog = $names->contains('Bumpers') && $names->contains('Body panels') && $names->contains('Trim');
                if (! $isPanelCatalog && $names->isNotEmpty()) {
                    return;
                }

                LaborCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
                LaborCategory::seedDefaultsFor((int) $tenantId, BusinessTypes::PAINT);
            });
    }

    public function down(): void
    {
        // Paint catalogs are owner-editable; do not restore the per-panel seed.
    }
};
