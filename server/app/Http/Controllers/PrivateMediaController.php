<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateMediaController extends Controller
{
    public function employeeAvatar(Request $request, int $employee): StreamedResponse
    {
        $item = Employee::query()->findOrFail($employee);
        abort_unless($request->user()->canSeeEmployee($item), 403);

        return $this->image($item->avatar_path);
    }

    public function userAvatar(Request $request, int $user): StreamedResponse
    {
        $item = User::query()->with('employee')->findOrFail($user);
        abort_unless($request->user()->is($item) || $request->user()->isAdmin(), 403);

        return $this->image($item->avatar_path ?? $item->employee?->avatar_path);
    }

    public function attendanceRecordPhoto(Request $request, int $record, string $photo): StreamedResponse
    {
        $item = AttendanceRecord::query()->with('employee')->findOrFail($record);
        abort_unless($request->user()->canSeeEmployee($item->employee), 403);
        abort_unless(in_array($photo, ['check-in', 'check-out'], true), 404);

        return $this->image($photo === 'check-in' ? $item->check_in_photo_path : $item->check_out_photo_path);
    }

    public function attendanceEventPhoto(Request $request, int $event): StreamedResponse
    {
        $item = AttendanceEvent::query()->with('employee')->findOrFail($event);
        abort_unless($request->user()->canSeeEmployee($item->employee), 403);

        return $this->image($item->photo_path);
    }

    private function image(?string $path): StreamedResponse
    {
        abort_unless($path
            && ! str_contains($path, '..')
            && (str_starts_with($path, 'avatars/') || str_starts_with($path, 'attendance_photos/'))
            && basename($path) === substr($path, strpos($path, '/') + 1), 404);

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            $disk = Storage::disk('public');
        }
        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], 'inline');
    }
}
