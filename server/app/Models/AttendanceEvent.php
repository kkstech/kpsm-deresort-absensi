<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['event_uuid', 'payload_hash', 'employee_id', 'shift_session_id', 'device_registration_id', 'sync_batch_id', 'type', 'source', 'server_received_at', 'device_time', 'device_timezone', 'effective_at', 'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'photo_path', 'is_mocked', 'local_seq', 'result', 'reason_code'])]
class AttendanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $appends = ['photo_url'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        if (app()->environment('testing')) {
            return Storage::disk('public')->exists($this->photo_path)
                ? url('storage/'.ltrim($this->photo_path, '/'))
                : null;
        }

        $relPath = ltrim($this->photo_path, '/');
        if (! is_file(public_path('storage/'.$relPath)) && ! is_file(storage_path('app/public/'.$relPath))) {
            return null;
        }

        return url('storage/'.$relPath);
    }

    protected function casts(): array
    {
        return [
            'server_received_at' => 'immutable_datetime',
            'device_time' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'is_mocked' => 'boolean',
        ];
    }
}
