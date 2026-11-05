<?php

namespace App\Models;

use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'work_date', 'shift_template_id', 'shift_category_id', 'template_name', 'template_type', 'status', 'revision', 'rules_version', 'published_at', 'created_by'])]
class ShiftAssignment extends Model
{
    public const ACTIVE = ['DRAFT', 'PUBLISHED', 'IN_PROGRESS', 'COMPLETED'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ShiftCategory::class, 'shift_category_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ShiftSession::class)->whereNull('retired_at')->orderBy('seq');
    }

    public function sessionHistory(): HasMany
    {
        return $this->hasMany(ShiftSession::class)->orderBy('schedule_revision')->orderBy('seq');
    }

    public function syncAttendanceStatus(): void
    {
        $sessionIds = $this->sessions()->pluck('id');
        $sessions = $this->sessions()->get();
        $now = WorkTime::now();
        $closed = 0;
        foreach ($sessions as $session) {
            $record = AttendanceRecord::query()->where('shift_session_id', $session->id)->first();
            if (($record && ($record->check_out_at || $record->provisional_out_at)) || (! $record && $session->end_at->lte($now))) {
                $closed++;
            }
        }
        $status = $sessionIds->isNotEmpty() && $closed === $sessionIds->count() ? 'COMPLETED' : 'IN_PROGRESS';

        if ($this->status !== $status && in_array($this->status, ['PUBLISHED', 'IN_PROGRESS'], true)) {
            $this->update(['status' => $status]);
        }
    }

    /** Bentuk JSON bersama untuk admin dan mobile. Jam ISO UTC + label jam lokal. */
    public function toApi(): array
    {
        $now = WorkTime::now();
        $this->loadMissing(['employee.workLocation', 'category']);
        $unrecordedPolicySnapshot = null;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'work_date' => $this->work_date,
            'shift_template_id' => $this->shift_template_id,
            'template_name' => $this->template_name,
            'template_type' => $this->template_type,
            'shift_category_id' => $this->shift_category_id,
            'shift_category_name' => $this->category?->name ?? 'Belum dikategorikan',
            'status' => $this->status,
            'revision' => $this->revision,
            'rules_version' => $this->rules_version,
            'scheduled_minutes' => $this->sessions->sum(fn (ShiftSession $session) => $session->scheduledMinutes()),
            'editable' => in_array($this->status, ['DRAFT', 'PUBLISHED'], true) && $this->sessions->first()?->start_at->gt($now),
            'sessions_editable' => in_array($this->status, ['DRAFT', 'PUBLISHED', 'IN_PROGRESS'], true)
                && $this->sessions->contains(fn (ShiftSession $session) => $session->end_at->gt($now)),
            'attendance_block_message' => $this->employee?->attendanceBlockMessage($this->work_date),
            'sessions' => $this->sessions->map(function (ShiftSession $s) use (&$unrecordedPolicySnapshot) {
                $snapshot = $s->policy_snapshot;
                if (! $s->attendanceRecord) {
                    $this->loadMissing('employee.workLocation');
                    $unrecordedPolicySnapshot ??= AttendancePolicy::snapshotFor($this->employee, $this->work_date);
                    $snapshot = $unrecordedPolicySnapshot;
                }
                $checkInEarlyMinutes = (int) ($snapshot['checkin_early_minutes'] ?? SiteSetting::checkInEarlyMinutes());

                return [
                    'id' => $s->id,
                    'seq' => $s->seq,
                    'start_at' => WorkTime::iso($s->start_at),
                    'end_at' => WorkTime::iso($s->end_at),
                    'local_start_date' => WorkTime::local($s->start_at)->toDateString(),
                    'local_start_time' => WorkTime::local($s->start_at)->format('H:i'),
                    'local_end_date' => WorkTime::local($s->end_at)->toDateString(),
                    'local_end_time' => WorkTime::local($s->end_at)->format('H:i'),
                    'local_start' => WorkTime::clock($s->start_at, $this->work_date),
                    'local_end' => WorkTime::clock($s->end_at, $this->work_date),
                    'unpaid_break_minutes' => $s->unpaid_break_minutes,
                    'scheduled_minutes' => $s->scheduledMinutes(),
                    'attendance_policy' => [
                        'location_required' => (bool) ($snapshot['use_location_check'] ?? false),
                        'max_accuracy_meters' => $snapshot['location']['max_accuracy_meters'] ?? null,
                        'late_permission_proof_required' => (bool) ($snapshot['late_permission_proof_required'] ?? false),
                        'correction_proof_required' => (bool) ($snapshot['correction_proof_required'] ?? false),
                    ],
                    'attendance_window' => [
                        'check_in_opens_at' => $checkInEarlyMinutes === 0 ? null : WorkTime::iso($s->start_at->subMinutes($checkInEarlyMinutes)),
                        'check_in_closes_at' => $checkInEarlyMinutes === 0 ? null : WorkTime::iso($s->end_at),
                        'check_in_unrestricted' => $checkInEarlyMinutes === 0,
                        'max_elapsed_minutes' => (int) $this->rules_version >= 2
                            ? ($this->template_type === 'special' ? 24 * 60 : 13 * 60)
                            : null,
                        'checkout_grace_minutes' => (int) $this->rules_version >= 2 && $this->template_type === 'special' ? 0 : config('attendance.checkout_late_minutes'),
                    ],
                ];
            })->all(),
        ];
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'revision' => 'integer',
            'rules_version' => 'integer',
        ];
    }
}
