<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceAttachment;
use Illuminate\Http\Request;
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

    public function mobile(Request $request, int $attachment): StreamedResponse
    {
        $item = AttendanceAttachment::query()->with('attachable.employee')->findOrFail($attachment);
        if ($item->attachable->employee->user_id !== $request->user()->id) {
            throw new BusinessRuleException('FORBIDDEN', 'Bukti ini bukan milik Anda.', 403);
        }

        return Storage::disk($item->disk)->download($item->path, $item->original_name);
    }
}
