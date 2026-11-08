<?php

namespace App\Models;

use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_number',
    'employee_id',
    'work_date',
    'shift_session_id',
    'initiator_type',
    'created_by',
    'title',
    'start_at',
    'end_at',
    'planned_minutes',
    'actual_minutes',
    'verified_minutes',
    'is_day_off',
    'status',
    'approval_tier',
    'consent_status',
    'consent_at',
    'consent_notes',
    'decision_by',
    'decision_at',
    'decision_notes',
])]
class OvertimeOrder extends Model
{
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by');
    }

    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class, 'overtime_order_id');
    }

    public function toApi(): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $session = $this->relationLoaded('session') ? $this->session : null;
        $session?->loadMissing('assignment');

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'employee_id' => $this->employee_id,
            'employee_name' => $employee?->name,
            'department_name' => $employee?->department?->name,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'code' => $employee->code,
                'avatar_url' => $employee->avatar_url,
                'department' => $employee->department?->name,
            ] : null,
            'work_date' => $this->work_date,
            'shift_session_id' => $this->shift_session_id,
            'session_name' => $session ? ($session->assignment->template_name.' · Sesi '.$session->seq) : null,
            'initiator_type' => $this->initiator_type,
            'title' => $this->title,
            'start_at' => WorkTime::iso($this->start_at),
            'end_at' => WorkTime::iso($this->end_at),
            'local_start' => WorkTime::local($this->start_at)->format('H:i'),
            'local_end' => WorkTime::local($this->end_at)->format('H:i'),
            'planned_minutes' => $this->planned_minutes,
            'actual_minutes' => $this->actual_minutes,
            'verified_minutes' => $this->verified_minutes,
            'is_day_off' => (bool) $this->is_day_off,
            'status' => $this->status,
            'approval_tier' => $this->approval_tier,
            'consent_status' => $this->consent_status,
            'consent_at' => WorkTime::iso($this->consent_at),
            'consent_notes' => $this->consent_notes,
            'decision_by_name' => $this->decisionMaker?->name,
            'decision_at' => WorkTime::iso($this->decision_at),
            'decision_notes' => $this->decision_notes,
            'created_at' => WorkTime::iso($this->created_at),
        ];
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'string',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'consent_at' => 'immutable_datetime',
            'decision_at' => 'immutable_datetime',
            'is_day_off' => 'boolean',
            'planned_minutes' => 'integer',
            'actual_minutes' => 'integer',
            'verified_minutes' => 'integer',
        ];
    }
}
