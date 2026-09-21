<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillVideo;
use App\Models\Feature;
use App\Models\Part;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobPhotosStockUnitAndPdfTest extends TestCase
{
    public function test_job_photos_are_off_until_enabled(): void
    {
        $this->seed(\Database\Seeders\FeatureSeeder::class);
        $superAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($superAdmin);

        $catalog = $this->getJson('/api/super-admin/feature-catalog?business_type=garage')->assertOk();
        $this->assertContains('job_photos', $catalog->json('optional'));
        $this->assertSame('admit_vehicle', $catalog->json('nested.job_photos'));

        $garage = $this->postJson('/api/super-admin/tenants', [
            'business_name' => 'Photo Garage',
            'business_type' => 'garage',
            'owner_name' => 'Shop Owner',
            'owner_phone' => '0771002003',
            'owner_email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'payment_plan' => 'monthly',
            'plan_amount' => 15000,
        ])->assertCreated();
        $this->assertFalse(collect($garage->json('features'))->pluck('key')->contains('job_photos'));
    }

    public function test_photos_upload_and_show_on_the_bill_link(): void
    {
        $user = $this->garageUser(['job_photos']);
        Sanctum::actingAs($user);
        $bill = $this->openJob();

        Storage::fake('local');
        $this->post('/api/bills/'.$bill->id.'/photos', [
            'photo' => UploadedFile::fake()->image('before.jpg', 640, 480),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('original_name', 'before.jpg');

        $list = $this->getJson('/api/bills/'.$bill->id.'/photos')->assertOk()->json();
        $this->assertCount(1, $list);
        $photoId = $list[0]['id'];

        $shared = $this->getJson('/api/bills/shared/'.$bill->share_token)
            ->assertOk()
            ->json();
        $this->assertCount(1, $shared['photos']);
        $this->assertSame($photoId, $shared['photos'][0]['id']);

        $this->get('/api/bills/shared/'.$bill->share_token.'/photos/'.$photoId.'/file')
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_shared_bill_includes_videos(): void
    {
        $user = $this->garageUser(['job_videos']);
        Sanctum::actingAs($user);
        $bill = $this->openJob();

        Storage::fake('local');
        $path = 'job-videos/'.$bill->tenant_id.'/'.$bill->id.'/clip.mp4';
        Storage::disk('local')->put($path, 'fake-mp4');
        $video = BillVideo::query()->create([
            'tenant_id' => $bill->tenant_id,
            'bill_id' => $bill->id,
            'path' => $path,
            'original_name' => 'clip.mp4',
            'duration_seconds' => 12,
            'size_bytes' => 1200,
        ]);

        $shared = $this->getJson('/api/bills/shared/'.$bill->share_token)->assertOk()->json();
        $this->assertSame($video->id, $shared['videos'][0]['id']);

        $this->get('/api/bills/shared/'.$bill->share_token.'/videos/'.$video->id.'/file')
            ->assertOk();
    }

    public function test_garage_can_stock_in_litres_and_restock_millilitres(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);

        $part = $this->postJson('/api/parts', [
            'name' => 'Engine oil 5W-30',
            'brand' => 'Castrol',
            'type' => 'Lubricant',
            'price' => 5200,
            'cost_price' => 4100,
            'stock_qty' => 10,
            'stock_unit' => 'l',
        ])->assertCreated()->json();
        $this->assertSame('l', $part['stock_unit']);
        $this->assertEquals(10, $part['stock_qty']);

        $this->postJson('/api/parts/'.$part['id'].'/restock', [
            'quantity' => 2,
            'unit_cost' => 4100,
            'payment_status' => 'paid',
        ])->assertOk();

        $this->assertEquals(12, (float) Part::query()->findOrFail($part['id'])->stock_qty);
        $this->assertSame('l', Part::query()->findOrFail($part['id'])->stock_unit);

        $this->postJson('/api/parts/'.$part['id'].'/restock', [
            'quantity' => 0.5,
            'unit_cost' => 4100,
            'payment_status' => 'paid',
        ])->assertOk();

        $this->assertEquals(12.5, (float) Part::query()->findOrFail($part['id'])->stock_qty);
    }

    public function test_item_stock_rejects_decimal_qty(): void
    {
        $user = $this->garageUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/parts', [
            'name' => 'Oil Filter',
            'brand' => 'Bosch',
            'type' => 'Filter',
            'price' => 1500,
            'cost_price' => 900,
            'stock_qty' => 1.5,
            'stock_unit' => 'qty',
        ])->assertUnprocessable();

        $part = $this->postJson('/api/parts', [
            'name' => 'Oil Filter',
            'brand' => 'Bosch',
            'type' => 'Filter',
            'price' => 1500,
            'cost_price' => 900,
            'stock_qty' => 2,
            'stock_unit' => 'qty',
        ])->assertCreated()->json();

        $this->postJson('/api/parts/'.$part['id'].'/restock', [
            'quantity' => 1.5,
            'unit_cost' => 900,
            'payment_status' => 'paid',
        ])->assertUnprocessable();
    }

    private function openJob(): Bill
    {
        $billId = $this->postJson('/api/bills', [
            'customer_name' => 'Nimal Perera',
            'customer_phone' => '0771234567',
            'number_plate' => 'CAB-'.fake()->unique()->numerify('####'),
            'job_kind' => 'repair',
        ])->assertCreated()->json('id');

        return Bill::withoutGlobalScopes()->with('tenant')->findOrFail($billId);
    }

    /**
     * @param  list<string>  $extraFeatures
     */
    private function garageUser(array $extraFeatures = []): User
    {
        $keys = [
            'admit_vehicle', 'customers', 'billing', 'payroll', 'balance_sheet',
            'parts_inventory', 'employees_management', 'attendance', 'reports',
            ...$extraFeatures,
        ];
        $features = collect($keys)->unique()->map(
            fn (string $key) => Feature::firstOrCreate(['key' => $key], ['name' => str($key)->headline(), 'group' => 'Other', 'sort_order' => 0])
        );
        $tenant = Tenant::create([
            'business_name' => fake()->company(), 'business_type' => 'garage', 'owner_name' => fake()->name(),
            'owner_phone' => '0771234567', 'owner_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);
        $tenant->features()->sync($features->mapWithKeys(fn (Feature $feature) => [$feature->id => ['is_enabled' => true]]));

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'business_owner', 'status' => 'active']);
    }
}
