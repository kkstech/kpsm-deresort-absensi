<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendancePhotoService
{
    /**
     * Process and store an attendance photo (file or base64), apply watermark, and encode to WebP.
     */
    public function processAndStore(Employee $employee, UploadedFile|string|null $source, ?CarbonInterface $timestamp = null): ?string
    {
        if (empty($source)) {
            return null;
        }

        $binary = $this->extractBinary($source);
        if ($binary === false || strlen($binary) === 0) {
            return null;
        }

        return $this->optimizeAndStore($employee, $binary, $timestamp);
    }

    /**
     * Extract raw binary string from UploadedFile or Base64 string.
     */
    private function extractBinary(UploadedFile|string $source): string|false
    {
        if ($source instanceof UploadedFile) {
            if (! $source->isValid()) {
                return false;
            }

            return file_get_contents($source->getRealPath());
        }

        $base64 = $source;
        if (str_contains($base64, ';base64,')) {
            $base64 = explode(';base64,', $base64)[1];
        }

        return base64_decode($base64, true);
    }

    /**
     * Apply watermark, convert to WebP, and store in public storage.
     */
    private function optimizeAndStore(Employee $employee, string $binary, ?CarbonInterface $timestamp = null): ?string
    {
        if (! extension_loaded('gd')) {
            return $this->fallbackStore($employee, $binary);
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return $this->fallbackStore($employee, $binary);
        }

        // Apply visual watermark
        $this->applyWatermark($image, $employee, $timestamp);

        // Encode to WebP
        $format = function_exists('imagewebp') ? 'webp' : 'jpg';
        ob_start();
        if ($format === 'webp') {
            imagewebp($image, null, 75);
        } else {
            imagejpeg($image, null, 75);
        }
        $encodedData = ob_get_clean();

        if (empty($encodedData)) {
            return $this->fallbackStore($employee, $binary);
        }

        $filename = 'att_'.$employee->id.'_'.time().'_'.Str::random(6).'.'.$format;
        $photoPath = 'attendance_photos/'.$filename;
        Storage::disk('public')->put($photoPath, $encodedData);

        return $photoPath;
    }

    /**
     * Overlay watermark banner at the bottom of the photo.
     */
    private function applyWatermark(&$image, Employee $employee, ?CarbonInterface $timestamp = null): void
    {
        $w = imagesx($image);
        $h = imagesy($image);

        // Proportional banner height (between 52px and 90px)
        $barHeight = max(52, min(90, (int) round($h * 0.14)));

        imagealphablending($image, true);
        imagesavealpha($image, true);

        // Dark translucent gradient/bar at bottom
        $barColor = imagecolorallocatealpha($image, 10, 20, 16, 45);
        if ($barColor !== false) {
            imagefilledrectangle($image, 0, $h - $barHeight, $w, $h, $barColor);
        }

        $textColor = imagecolorallocate($image, 255, 255, 255);
        $subColor = imagecolorallocate($image, 218, 235, 224);

        $dt = ($timestamp ?? now())->timezone(config('attendance.timezone', 'Asia/Jakarta'));
        $timeStr = $dt->translatedFormat('d M Y, H:i:s').' WIB';
        $empStr = $employee->name.($employee->code ? ' · '.$employee->code : '');

        $fontPath = public_path('fonts/plus-jakarta-600.ttf');
        if (file_exists($fontPath) && function_exists('imagettftext')) {
            $fontSize = max(10, min(16, (int) round($barHeight * 0.22)));
            $subSize = max(9, min(13, (int) round($fontSize * 0.82)));

            // Text with subtle shadow for optimal readability
            $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 70);
            if ($shadowColor !== false) {
                imagettftext($image, $fontSize, 0, 17, $h - (int) ($barHeight * 0.52) + 1, $shadowColor, $fontPath, $empStr);
                imagettftext($image, $subSize, 0, 17, $h - (int) ($barHeight * 0.18) + 1, $shadowColor, $fontPath, $timeStr);
            }

            imagettftext($image, $fontSize, 0, 16, $h - (int) ($barHeight * 0.52), $textColor, $fontPath, $empStr);
            imagettftext($image, $subSize, 0, 16, $h - (int) ($barHeight * 0.18), $subColor, $fontPath, $timeStr);
        } else {
            // Built-in GD bitmap font fallback
            imagestring($image, 4, 14, $h - $barHeight + 8, $empStr, $textColor);
            imagestring($image, 3, 14, $h - $barHeight + 28, $timeStr, $subColor);
        }
    }

    /**
     * Fallback to store raw binary as JPG when GD fails.
     */
    private function fallbackStore(Employee $employee, string $binary): ?string
    {
        $filename = 'att_'.$employee->id.'_'.time().'_'.Str::random(6).'.jpg';
        $photoPath = 'attendance_photos/'.$filename;
        Storage::disk('public')->put($photoPath, $binary);

        return $photoPath;
    }
}
