<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class JobPhotoStore
{
    /**
     * @return array{path: string, size_bytes: int}
     */
    public function store(UploadedFile $file, int $tenantId, int $billId): array
    {
        $source = $file->getRealPath();
        if (! $source || ! is_file($source)) {
            throw new RuntimeException('Could not read the uploaded photo.');
        }

        $binary = file_get_contents($source);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Could not read the uploaded photo.');
        }

        if (! function_exists('imagecreatefromstring')) {
            $path = "job-photos/{$tenantId}/{$billId}/".Str::uuid()->toString().'.jpg';
            $this->write($path, $binary);

            return [
                'path' => $path,
                'size_bytes' => strlen($binary),
            ];
        }

        $image = @imagecreatefromstring($binary);
        if (! $image) {
            throw new RuntimeException('Use a JPEG, PNG, or WebP photo.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $max = 1920;
        $scale = min(1, $max / max($width, $height, 1));
        $nextWidth = max(1, (int) round($width * $scale));
        $nextHeight = max(1, (int) round($height * $scale));

        if ($nextWidth !== $width || $nextHeight !== $height) {
            $resized = imagecreatetruecolor($nextWidth, $nextHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $nextWidth, $nextHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if ($jpeg === '' || strlen($jpeg) > 8 * 1024 * 1024) {
            throw new RuntimeException('This photo is still too large after compression.');
        }

        $path = "job-photos/{$tenantId}/{$billId}/".Str::uuid()->toString().'.jpg';
        $this->write($path, $jpeg);

        return [
            'path' => $path,
            'size_bytes' => strlen($jpeg),
        ];
    }

    public function delete(string $path): void
    {
        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }

    private function write(string $path, string $contents): void
    {
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Could not save the photo on the server.');
        }
    }
}
