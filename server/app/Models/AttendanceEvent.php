<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        if (! $this->photo_path || ! $this->exists) {
            return null;
        }

        return url('/private-media/attendance-events/'.$this->id.'/photo');
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
