<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Feature::query()->updateOrCreate(
            ['key' => 'bill_whatsapp'],
            [
                'name' => 'WhatsApp bill share',
                'description' => 'Share quotation / paid bill links with customers on WhatsApp. Nested under Billing. Off until super-admin enables it.',
                'group' => 'Service Intake',
                'sort_order' => 32,
            ],
        );
    }

    public function down(): void
    {
        Feature::query()->where('key', 'bill_whatsapp')->delete();
    }
};
