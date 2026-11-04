<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Feature;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobProgressTest extends TestCase
{
    public function test_progress_is_closed_until_super_admin_enables_it(): void
    {
        [, $owner] = $this->shop();
        Sanctum::actingAs($owner);
        $billId = $this->openJob();

        $this->getJson("/api/bills/{$billId}/progress")->assertForbidden();
        $this->assertContains('job_progress', BusinessTypes::optionalFeatures(BusinessTypes::GARAGE));
        $this->assertContains('job_progress', BusinessTypes::optionalFeatures(BusinessTypes::PAINT));
        $this->assertNotContains('job_progress', BusinessTypes::optionalFeatures(BusinessTypes::TYRE));
    }

    public function test_a_job_moves_through_steps_with_a_photo_and_a_customer_link(): void
    {
        [$tenant, $owner] = $this->shop();
        $this->enable($tenant);
        Sanctum::actingAs($owner);
        $billId = $this->openJob();

        $this->postJson("/api/bills/{$billId}/items", [
            'type' => 'labor',
            'description' => 'Repairing brake pads',
            'quantity' => 1,
            'unit_price' => 2500,
        ])->assertCreated();

        $this->getJson("/api/bills/{$billId}/progress")->assertOk()
            ->assertJsonPath('current_step', 'received')
            ->assertJsonPath('complete', false)
            ->assertJsonPath('labor.0.description', 'Repairing brake pads');

        $this->putJson("/api/bills/{$billId}/progress", ['step' => 'working'])->assertOk()
            ->assertJsonPath('current_step', 'working')
            ->assertJsonPath('complete', false);

        $this->putJson("/api/bills/{$billId}/progress", ['step' => 'painting'])->assertStatus(422);

        Storage::fake('local');
        $this->post("/api/bills/{$billId}/progress/photos", [
            'step' => 'working',
            'photo' => UploadedFile::fake()->image('bay.jpg', 640, 480),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('current_step', 'working');

        $this->putJson("/api/bills/{$billId}/progress", ['step' => 'complete'])->assertOk()
            ->assertJsonPath('complete', true);

        $token = Bill::query()->findOrFail($billId)->share_token;
        $shared = $this->getJson("/api/bills/shared/{$token}/progress")->assertOk();
        $this->assertTrue($shared->json('complete'));
        $this->assertSame('Kamal Silva', $shared->json('customer_name'));
        $this->assertSame('Repairing brake pads', $shared->json('labor.0.description'));
        $this->assertNotEmpty($shared->json('vehicle_number'));
        $working = collect($shared->json('steps'))->firstWhere('key', 'working');
        $this->assertCount(1, $working['photos']);
        $this->assertSame('done', collect($shared->json('steps'))->firstWhere('key', 'complete')['state']);

        $this->get('/api/bills/shared/'.$token.'/progress-photos/'.$working['photos'][0]['id'].'/file')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_paint_shops_use_paint_steps(): void
    {
        [$tenant, $owner] = $this->shop(BusinessTypes::PAINT);
        $this->enable($tenant);
        Sanctum::actingAs($owner);
        $billId = $this->openJob();

        $this->putJson("/api/bills/{$billId}/progress", ['step' => 'painting'])->assertOk()
            ->assertJsonPath('current_step', 'painting')
            ->assertJsonPath('complete', false);
    }

    public function test_other_trades_cannot_open_progress(): void
    {
        [$tenant, $owner] = $this->shop(BusinessTypes::TYRE);
        $this->enable($tenant);
        Sanctum::actingAs($owner);
        $billId = $this->openJob();

        $this->getJson("/api/bills/{$billId}/progress")->assertNotFound();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function shop(string $type = BusinessTypes::GARAGE): array
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $tenant = Tenant::create([
            'business_name' => 'Progress Shop',
            'business_type' => $type,
            'owner_name' => 'Owner',
            'owner_phone' => '0771234567',
            'owner_email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);
        $features = Feature::whereIn('key', BusinessTypes::defaults($type))->get();
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);

        return [$tenant, $user];
    }

    private function enable(Tenant $tenant): void
    {
        $feature = Feature::query()->where('key', 'job_progress')->firstOrFail();
        $tenant->features()->syncWithoutDetaching([$feature->id => ['is_enabled' => true]]);
    }

    private function openJob(): int
    {
        return $this->postJson('/api/bills', [
            'customer_name' => 'Kamal Silva',
            'customer_phone' => '0771111001',
            'number_plate' => 'WP-'.fake()->unique()->numerify('####'),
        ])->assertCreated()->json('id');
    }
}
