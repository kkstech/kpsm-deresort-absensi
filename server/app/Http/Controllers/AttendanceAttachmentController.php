<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceAttachmentController extends Controller
{
    public function admin(Request $request, int $attachment): StreamedResponse
    {
        $item = AttendanceAttachment::query()->with('attachable.employee')->findOrFail($attachment);
        abort_unless($request->user()->canSeeEmployee($item->attachable->employee), 403);

        return Storage::disk($item->disk)->download($item->path, $item->original_name);
    }

    public function preview(Request $request, int $attachment): StreamedResponse
    {
        $item = AttendanceAttachment::query()->with('attachable.employee')->findOrFail($attachment);
        abort_unless($request->user()->canSeeEmployee($item->attachable->employee), 403);
        abort_unless(in_array($item->mime_type, ['image/jpeg', 'image/png'], true), Response::HTTP_UNSUPPORTED_MEDIA_TYPE);

        return Storage::disk($item->disk)->response($item->path, $item->original_name, [
            'Content-Type' => $item->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function mobile(Request $request, int $attachment): StreamedResponse
    {
        $item = AttendanceAttachment::query()->with('attachable.employee')->findOrFail($attachment);
        if ($item->attachable->employee->user_id !== $request->user()->id) {
            throw new BusinessRuleException('FORBIDDEN', 'Bukti ini bukan milik Anda.', 403);
        }

        return Storage::disk($item->disk)->download($item->path, $item->original_name);
    }
}
