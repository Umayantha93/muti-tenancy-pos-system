<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\JobProgressPhoto;
use App\Services\JobPhotoStore;
use App\Support\BusinessTypes;
use App\Support\JobProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobProgressController extends Controller
{
    public function show(Bill $bill): JsonResponse
    {
        $this->assertTrackable($bill);

        return response()->json(JobProgress::present($bill));
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        $this->assertTrackable($bill);
        abort_if($bill->isClosed(), 422, 'Closed jobs cannot change progress.');
        $keys = JobProgress::keys($this->type($bill));
        $data = $request->validate([
            'step' => ['required', Rule::in($keys)],
        ]);
        $bill->update(['progress_step' => $data['step'], 'updated_by' => $request->user()->id]);

        return response()->json(JobProgress::present($bill->fresh()));
    }

    public function storePhoto(Request $request, Bill $bill, JobPhotoStore $store): JsonResponse
    {
        $this->assertTrackable($bill);
        abort_if($bill->isClosed(), 422, 'Closed jobs cannot change progress.');
        $keys = JobProgress::keys($this->type($bill));
        $request->validate([
            'step' => ['required', Rule::in($keys)],
            'photo' => ['required', 'file', 'max:12288', 'mimes:jpeg,jpg,png,webp,gif'],
        ]);
        $step = (string) $request->input('step');
        abort_if(
            $bill->progressPhotos()->where('step_key', $step)->count() >= JobProgressPhoto::MAX_PER_STEP,
            422,
            'This step already has 6 photos.',
        );

        try {
            $stored = $store->store($request->file('photo'), (int) $bill->tenant_id, (int) $bill->id);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $bill->progressPhotos()->create([
            'step_key' => $step,
            'path' => $stored['path'],
            'original_name' => $request->file('photo')->getClientOriginalName(),
            'size_bytes' => $stored['size_bytes'],
        ]);
        $bill->ensureShareToken();

        return response()->json(JobProgress::present($bill->fresh()), 201);
    }

    public function destroyPhoto(Bill $bill, JobProgressPhoto $progressPhoto, JobPhotoStore $store): JsonResponse
    {
        $this->assertTrackable($bill);
        abort_unless($progressPhoto->bill_id === $bill->id, 404);
        abort_if($bill->isClosed(), 422, 'Closed jobs cannot change progress.');
        $store->delete($progressPhoto->path);
        $progressPhoto->delete();

        return response()->json(JobProgress::present($bill->fresh()));
    }

    public function shared(string $token): JsonResponse
    {
        $bill = $this->sharedBill($token);
        $payload = JobProgress::present($bill);
        $bill->loadMissing([
            'customer' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name']),
            'vehicle' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'number_plate']),
            'tenant:id,business_name,business_type',
        ]);

        return response()->json([
            ...$payload,
            'bill_number' => $bill->bill_number,
            'customer_name' => $bill->customer?->name,
            'vehicle_number' => $bill->vehicle?->number_plate,
            'business_name' => $bill->tenant?->business_name,
        ]);
    }

    public function sharedPhoto(string $token, JobProgressPhoto $progressPhoto): StreamedResponse
    {
        $bill = $this->sharedBill($token);
        abort_unless($progressPhoto->bill_id === $bill->id, 404);
        abort_unless(Storage::disk('local')->exists($progressPhoto->path), 404);

        return Storage::disk('local')->response($progressPhoto->path, $progressPhoto->original_name ?: 'progress.jpg', [
            'Content-Type' => 'image/jpeg',
        ]);
    }

    private function sharedBill(string $token): Bill
    {
        $token = Bill::normalizeShareToken($token);
        abort_unless(strlen($token) >= 8, 404);
        $bill = Bill::withoutGlobalScopes()->where('share_token', $token)->firstOrFail();
        $bill->loadMissing('tenant:id,business_type,business_name');
        $this->assertTrackable($bill);
        abort_unless($this->featureOn((int) $bill->tenant_id), 404);

        return $bill;
    }

    private function assertTrackable(Bill $bill): void
    {
        abort_unless(in_array($this->type($bill), [BusinessTypes::GARAGE, BusinessTypes::PAINT], true), 404);
        abort_if($bill->job_kind === Bill::JOB_KIND_PARTS_SALE, 422, 'Progress is for repair and service jobs.');
    }

    private function type(Bill $bill): string
    {
        $bill->loadMissing('tenant:id,business_type');

        return BusinessTypes::normalizeLegacy((string) ($bill->tenant?->business_type ?? ''));
    }

    private function featureOn(int $tenantId): bool
    {
        return DB::table('tenant_features')
            ->join('features', 'features.id', '=', 'tenant_features.feature_id')
            ->where('tenant_features.tenant_id', $tenantId)
            ->where('tenant_features.is_enabled', true)
            ->where('features.key', 'job_progress')
            ->exists();
    }
}
