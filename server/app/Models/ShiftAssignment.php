<?php

namespace App\Models;

use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'work_date', 'shift_template_id', 'template_name', 'template_type', 'status', 'revision', 'published_at', 'created_by'])]
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

    public function sessions(): HasMany
    {
        return $this->hasMany(ShiftSession::class)->orderBy('seq');
    }

    public function syncAttendanceStatus(): void
    {
        $sessionIds = $this->sessions()->pluck('id');
        $closed = AttendanceRecord::query()->whereIn('shift_session_id', $sessionIds)
            ->where(fn ($query) => $query->whereNotNull('check_out_at')->orWhereNotNull('provisional_out_at'))->count();
        $status = $sessionIds->isNotEmpty() && $closed === $sessionIds->count() ? 'COMPLETED' : 'IN_PROGRESS';

        if ($this->status !== $status && in_array($this->status, ['PUBLISHED', 'IN_PROGRESS'], true)) {
            $this->update(['status' => $status]);
        }
    }

    /** Bentuk JSON bersama untuk admin dan mobile. Jam ISO UTC + label jam lokal. */
    public function toApi(): array
    {
        $now = WorkTime::now();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'work_date' => $this->work_date,
            'template_name' => $this->template_name,
            'template_type' => $this->template_type,
            'status' => $this->status,
            'revision' => $this->revision,
            'editable' => in_array($this->status, ['DRAFT', 'PUBLISHED'], true) && $this->sessions->first()?->start_at->gt($now),
            'sessions' => $this->sessions->map(fn (ShiftSession $s) => [
                'id' => $s->id,
                'seq' => $s->seq,
                'start_at' => WorkTime::iso($s->start_at),
                'end_at' => WorkTime::iso($s->end_at),
                'local_start' => WorkTime::clock($s->start_at, $this->work_date),
                'local_end' => WorkTime::clock($s->end_at, $this->work_date),
                'unpaid_break_minutes' => $s->unpaid_break_minutes,
                'scheduled_minutes' => $s->scheduledMinutes(),
            ])->all(),
        ];
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
