<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPhoto;
use App\Services\JobPhotoStore;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobPhotoController extends Controller
{
    public function index(Bill $bill): JsonResponse
    {
        $this->assertGarageJob($bill);

        return response()->json($bill->photos->map(fn (BillPhoto $photo) => $this->payload($photo))->values());
    }

    public function store(Request $request, Bill $bill, JobPhotoStore $store): JsonResponse
    {
        $this->assertGarageJob($bill);
        abort_if($bill->isClosed(), 422, 'Closed bills cannot be edited.');
        abort_if($bill->job_kind === Bill::JOB_KIND_PARTS_SALE, 422, 'Photos can only be added on repair or service jobs.');
        abort_if($bill->photos()->count() >= BillPhoto::MAX_PER_BILL, 422, 'This job already has 15 photos.');

        $photo = $request->file('photo');
        if (! $photo || ! $photo->isValid()) {
            $message = match ($photo?->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This photo is too large for the server. Try a smaller JPEG.',
                UPLOAD_ERR_PARTIAL => 'The photo upload was interrupted. Try again.',
                default => 'The photo did not reach the server. Try a smaller JPEG or PNG.',
            };

            return response()->json(['message' => $message], 422);
        }

        $request->validate([
            'photo' => ['required', 'file', 'max:'.BillPhoto::MAX_UPLOAD_KILOBYTES, 'mimes:jpeg,jpg,png,webp,gif'],
            'label' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $stored = $store->store(
                $request->file('photo'),
                (int) $bill->tenant_id,
                (int) $bill->id,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $photo = $bill->photos()->create([
            'path' => $stored['path'],
            'original_name' => $request->file('photo')->getClientOriginalName(),
            'label' => $request->input('label'),
            'size_bytes' => $stored['size_bytes'],
        ]);

        return response()->json($this->payload($photo->fresh()), 201);
    }

    public function file(Bill $bill, BillPhoto $photo): StreamedResponse
    {
        $this->assertGarageJob($bill);
        abort_unless($photo->bill_id === $bill->id, 404);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->original_name ?: 'job-photo.jpg', [
            'Content-Type' => 'image/jpeg',
        ]);
    }

    public function destroy(Bill $bill, BillPhoto $photo, JobPhotoStore $store): JsonResponse
    {
        $this->assertGarageJob($bill);
        abort_if($bill->isClosed(), 422, 'Closed bills cannot be edited.');
        abort_unless($photo->bill_id === $bill->id, 404);
        $store->delete($photo->path);
        $photo->delete();

        return response()->json(null, 204);
    }

    private function assertGarageJob(Bill $bill): void
    {
        abort_unless(
            (string) $bill->tenant?->business_type === BusinessTypes::GARAGE
                || (string) request()->user()?->tenant?->business_type === BusinessTypes::GARAGE,
            403,
            'Job photos are only available for garage shops.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BillPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'bill_id' => $photo->bill_id,
            'label' => $photo->label,
            'original_name' => $photo->original_name,
            'size_bytes' => $photo->size_bytes,
            'created_at' => $photo->created_at?->toIso8601String(),
            'expires_at' => $photo->expiresAt()->toDateString(),
        ];
    }
}
