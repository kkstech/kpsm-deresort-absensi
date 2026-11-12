<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttendancePhotoService
{
    public function __construct(private readonly PrivateImageService $images) {}

    /**
     * Process and store an attendance photo (file or base64), apply watermark, and encode to JPEG.
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

            $binary = file_get_contents($source->getRealPath());

            return is_string($binary) && strlen($binary) <= 5_242_880 ? $binary : false;
        }

        $base64 = $source;
        if (str_contains($base64, ';base64,')) {
            $base64 = explode(';base64,', $base64)[1];
        }

        if (strlen($base64) > 6_990_507) {
            return false;
        }

        $binary = base64_decode($base64, true);

        return is_string($binary) && strlen($binary) <= 5_242_880 ? $binary : false;
    }

    /**
     * Apply watermark, convert to JPEG, and store in private storage.
     */
    private function optimizeAndStore(Employee $employee, string $binary, ?CarbonInterface $timestamp = null): ?string
    {
        $image = $this->images->decode($binary);
        if (! $image) {
            return null;
        }

        $image = $this->images->resize($image, 2048);
        if (! $image) {
            return null;
        }

        // Apply visual watermark
        $this->applyWatermark($image, $employee, $timestamp);

        $encodedData = $this->images->encodeJpeg($image);
        imagedestroy($image);
        if ($encodedData === null) {
            return null;
        }

        $filename = 'att_'.$employee->id.'_'.time().'_'.Str::random(6).'.jpg';
        $photoPath = 'attendance_photos/'.$filename;
        if (! Storage::disk('local')->put($photoPath, $encodedData)) {
            return null;
        }

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
}
