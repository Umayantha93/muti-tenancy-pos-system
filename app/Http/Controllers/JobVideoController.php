<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillVideo;
use App\Services\JobVideoConverter;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobVideoController extends Controller
{
    public function index(Bill $bill): JsonResponse
    {
        $this->assertGarageJob($bill);

        return response()->json($bill->videos->map(fn (BillVideo $video) => $this->payload($video))->values());
    }

    public function store(Request $request, Bill $bill, JobVideoConverter $converter): JsonResponse
    {
        $this->assertGarageJob($bill);
        abort_if($bill->job_kind === Bill::JOB_KIND_PARTS_SALE, 422, 'Videos can only be added on repair or service jobs.');
        abort_if($bill->videos()->count() >= BillVideo::MAX_PER_BILL, 422, 'This job already has 5 videos.');

        $request->validate([
            'video' => ['required', 'file', 'max:102400', 'mimetypes:video/mp4,video/quicktime,video/webm,video/3gpp'],
            'label' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $converted = $converter->convert(
                $request->file('video'),
                (int) $bill->tenant_id,
                (int) $bill->id,
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $video = $bill->videos()->create([
            'path' => $converted['path'],
            'original_name' => $request->file('video')->getClientOriginalName(),
            'label' => $request->input('label'),
            'duration_seconds' => $converted['duration_seconds'],
            'size_bytes' => $converted['size_bytes'],
        ]);

        return response()->json($this->payload($video->fresh()), 201);
    }

    public function file(Bill $bill, BillVideo $video): StreamedResponse
    {
        $this->assertGarageJob($bill);
        abort_unless($video->bill_id === $bill->id, 404);
        abort_unless(Storage::disk('local')->exists($video->path), 404);

        return Storage::disk('local')->response($video->path, $video->original_name ?: 'job-video.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    public function destroy(Bill $bill, BillVideo $video, JobVideoConverter $converter): JsonResponse
    {
        $this->assertGarageJob($bill);
        abort_unless($video->bill_id === $bill->id, 404);
        $converter->delete($video->path);
        $video->delete();

        return response()->json(null, 204);
    }

    private function assertGarageJob(Bill $bill): void
    {
        abort_unless(
            (string) $bill->tenant?->business_type === BusinessTypes::GARAGE
                || (string) request()->user()?->tenant?->business_type === BusinessTypes::GARAGE,
            403,
            'Job videos are only available for garage shops.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BillVideo $video): array
    {
        return [
            'id' => $video->id,
            'bill_id' => $video->bill_id,
            'label' => $video->label,
            'original_name' => $video->original_name,
            'duration_seconds' => $video->duration_seconds,
            'size_bytes' => $video->size_bytes,
            'created_at' => $video->created_at?->toIso8601String(),
            'expires_at' => $video->expiresAt()->toDateString(),
        ];
    }
}
