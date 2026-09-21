<?php

namespace App\Services;

use App\Models\BillVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class JobVideoConverter
{
    public function ffmpegAvailable(): bool
    {
        $result = Process::timeout(10)->run(['ffmpeg', '-version']);

        return $result->successful();
    }

    /**
     * @return array{path: string, duration_seconds: int, size_bytes: int}
     */
    public function convert(UploadedFile $file, int $tenantId, int $billId): array
    {
        $source = $file->getRealPath();
        if (! $source) {
            throw new RuntimeException('Could not read the uploaded video.');
        }

        if (! $this->ffmpegAvailable()) {
            return $this->storeOriginal($file, $tenantId, $billId);
        }

        $duration = $this->probeDuration($source);
        if ($duration !== null && $duration > BillVideo::MAX_SECONDS + 0.5) {
            throw new RuntimeException('Each clip must be 90 seconds or shorter. Trim it on the phone first.');
        }

        $tmp = sys_get_temp_dir().'/jobvid-'.Str::uuid().'.mp4';
        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $source,
            '-vf', 'scale=-2:720',
            '-c:v', 'libx264', '-preset', 'veryfast',
            '-b:v', '1500k', '-maxrate', '1800k', '-bufsize', '3600k',
            '-c:a', 'aac', '-b:a', '96k',
            '-movflags', '+faststart',
            '-t', (string) BillVideo::MAX_SECONDS,
            $tmp,
        ]);

        if (! $result->successful() || ! is_file($tmp)) {
            @unlink($tmp);

            return $this->storeOriginal($file, $tenantId, $billId);
        }

        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > BillVideo::MAX_OUTPUT_BYTES) {
            @unlink($tmp);

            return $this->storeOriginal($file, $tenantId, $billId);
        }

        $storedName = Str::uuid()->toString().'.mp4';
        $path = "job-videos/{$tenantId}/{$billId}/{$storedName}";
        Storage::disk('local')->put($path, file_get_contents($tmp));
        @unlink($tmp);

        return [
            'path' => $path,
            'duration_seconds' => max(1, (int) round($duration ?? 1)),
            'size_bytes' => $size,
        ];
    }

    /**
     * @return array{path: string, duration_seconds: int, size_bytes: int}
     */
    private function storeOriginal(UploadedFile $file, int $tenantId, int $billId): array
    {
        $source = $file->getRealPath();
        if (! $source || ! is_file($source)) {
            throw new RuntimeException('Could not read the uploaded video.');
        }

        $size = (int) filesize($source);
        if ($size <= 0 || $size > BillVideo::MAX_OUTPUT_BYTES) {
            throw new RuntimeException('This video is too large. Use a clip under 40 MB, or 90 seconds or less.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'mp4';
        if (! in_array($extension, ['mp4', 'mov', 'webm', '3gp'], true)) {
            $extension = 'mp4';
        }

        $path = "job-videos/{$tenantId}/{$billId}/".Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, file_get_contents($source) ?: '');

        $duration = $this->ffmpegAvailable() ? $this->probeDuration($source) : null;

        return [
            'path' => $path,
            'duration_seconds' => max(1, (int) round($duration ?? 1)),
            'size_bytes' => $size,
        ];
    }

    public function delete(string $path): void
    {
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }

    private function probeDuration(string $path): ?float
    {
        $result = Process::timeout(20)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        if (! $result->successful()) {
            return null;
        }
        $seconds = (float) trim($result->output());

        return $seconds > 0 ? $seconds : null;
    }
}
