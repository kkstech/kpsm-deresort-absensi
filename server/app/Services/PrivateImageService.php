<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PrivateImageService
{
    public function storeAvatar(UploadedFile $file, string $path): ?string
    {
        if (! $file->isValid()) {
            return null;
        }

        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            return null;
        }

        $image = $this->decode($binary);
        if (! $image) {
            return null;
        }

        $image = $this->resize($image, 512);
        if (! $image) {
            return null;
        }
        $jpeg = $this->encodeJpeg($image);
        imagedestroy($image);

        if ($jpeg === null) {
            return null;
        }

        if (! Storage::disk('local')->put($path, $jpeg)) {
            throw new RuntimeException('Avatar could not be stored.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'avatars/') || basename($path) !== substr($path, strlen('avatars/'))) {
            return;
        }

        Storage::disk('local')->delete($path);
        Storage::disk('public')->delete($path);
    }

    public function decode(string $binary): \GdImage|false
    {
        if (! function_exists('getimagesizefromstring') || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $imageInfo = @getimagesizefromstring($binary);
        if (! $imageInfo
            || ! in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/png'], true)
            || ($imageInfo[0] ?? 0) < 1
            || ($imageInfo[1] ?? 0) < 1
            || $imageInfo[0] > 6000
            || $imageInfo[1] > 6000
            || $imageInfo[0] * $imageInfo[1] > 20_000_000) {
            return false;
        }

        $image = @imagecreatefromstring($binary);

        return $image instanceof \GdImage ? $image : false;
    }

    public function resize(\GdImage $image, int $maxDimension): \GdImage|false
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxDimension / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if (! $resized) {
            imagedestroy($image);

            return false;
        }

        $background = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $background);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    public function encodeJpeg(\GdImage $image): ?string
    {
        ob_start();
        $encoded = imagejpeg($image, null, 85);
        $binary = ob_get_clean();

        return $encoded && is_string($binary) && $binary !== '' ? $binary : null;
    }
}
