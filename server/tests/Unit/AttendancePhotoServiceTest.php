<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\AttendancePhotoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendancePhotoServiceTest extends TestCase
{
    private AttendancePhotoService $service;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttendancePhotoService::class);
        $this->employee = new Employee([
            'name' => 'Budi Pratama',
            'code' => 'EMP-099',
        ]);
        $this->employee->id = 99;
        Storage::fake('public');
    }

    public function test_returns_null_on_empty_source(): void
    {
        $this->assertNull($this->service->processAndStore($this->employee, null));
        $this->assertNull($this->service->processAndStore($this->employee, ''));
    }

    public function test_converts_base64_photo_to_watermarked_webp(): void
    {
        // Generate a 200x200 PNG image
        $gd = imagecreatetruecolor(200, 200);
        $blue = imagecolorallocate($gd, 40, 100, 180);
        imagefill($gd, 0, 0, $blue);
        ob_start();
        imagepng($gd);
        $pngData = ob_get_clean();
        $base64 = 'data:image/png;base64,'.base64_encode($pngData);

        $timestamp = CarbonImmutable::parse('2026-10-29 08:30:00', 'Asia/Jakarta');
        $result = $this->service->processAndStore($this->employee, $base64, $timestamp);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('attendance_photos/att_99_', $result);
        $this->assertStringEndsWith('.webp', $result);
        Storage::disk('public')->assertExists($result);

        // Verify the stored content is valid WebP
        $storedBinary = Storage::disk('public')->get($result);
        $this->assertGreaterThan(0, strlen($storedBinary));
        $storedGd = @imagecreatefromstring($storedBinary);
        $this->assertNotFalse($storedGd);
        $this->assertSame(200, imagesx($storedGd));
        $this->assertSame(200, imagesy($storedGd));
    }

    public function test_converts_uploaded_file_photo(): void
    {
        $file = UploadedFile::fake()->image('selfie.jpg', 300, 300);
        $result = $this->service->processAndStore($this->employee, $file);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('.webp', $result);
        Storage::disk('public')->assertExists($result);
    }
}
