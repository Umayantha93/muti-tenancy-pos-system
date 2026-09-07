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
        if (! $this->ffmpegAvailable()) {
            throw new RuntimeException('Video conversion is not available on this server (ffmpeg missing).');
        }

        $source = $file->getRealPath();
        if (! $source) {
            throw new RuntimeException('Could not read the uploaded video.');
        }

        $duration = $this->probeDuration($source);
        if ($duration > BillVideo::MAX_SECONDS + 0.5) {
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
            throw new RuntimeException('Could not compress this video. Try another clip.');
        }

        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > BillVideo::MAX_OUTPUT_BYTES) {
            @unlink($tmp);
            throw new RuntimeException('Compressed video is still too large. Use a shorter clip.');
        }

        $storedName = Str::uuid()->toString().'.mp4';
        $path = "job-videos/{$tenantId}/{$billId}/{$storedName}";
        Storage::disk('local')->put($path, file_get_contents($tmp));
        @unlink($tmp);

        return [
            'path' => $path,
            'duration_seconds' => max(1, (int) round($duration)),
            'size_bytes' => $size,
        ];
    }

    public function delete(string $path): void
    {
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }

    private function probeDuration(string $path): float
    {
        $result = Process::timeout(20)->run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('Could not read this video. Use an MP4 clip of 90 seconds or less.');
        }
        $seconds = (float) trim($result->output());
        if ($seconds <= 0) {
            throw new RuntimeException('Could not read this video duration.');
        }

        return $seconds;
    }
}
