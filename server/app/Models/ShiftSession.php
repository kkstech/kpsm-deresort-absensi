<?php

namespace App\Models;

use App\Support\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['shift_assignment_id', 'seq', 'start_at', 'end_at', 'unpaid_break_minutes', 'policy_snapshot', 'schedule_revision', 'rules_version', 'retired_at'])]
class ShiftSession extends Model
{
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class, 'shift_session_id');
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'shift_session_id')->orderBy('effective_at')->orderBy('id');
    }

    public function overtimeOrders(): HasMany
    {
        return $this->hasMany(OvertimeOrder::class, 'shift_session_id');
    }

    public function latePermissions(): HasMany
    {
        return $this->hasMany(LatePermission::class, 'shift_session_id');
    }

    public function approvedLatePermissions(): HasMany
    {
        return $this->hasMany(LatePermission::class, 'shift_session_id')->where('status', 'APPROVED');
    }

    /**
     * Build approved permissions data for API and UI rendering.
     * Computes effective_start, effective_end, and list of approved permission cards.
     */
    public function approvedPermissionsPayload(): array
    {
        $permissions = $this->relationLoaded('latePermissions')
            ? $this->latePermissions->where('status', 'APPROVED')
            : $this->approvedLatePermissions()->get();

        $effectiveStart = null;
        $effectiveEnd = null;
        $list = [];

        foreach ($permissions as $perm) {
            $type = $perm->permission_type ?? 'late_arrival';
            $item = [
                'id' => $perm->id,
                'permission_type' => $type,
                'reason' => $perm->reason,
            ];

            if ($type === 'late_arrival') {
                $targetTime = null;
                if ($perm->allowed_until) {
                    $targetTime = WorkTime::local($perm->allowed_until)?->format('H:i');
                } elseif ($perm->allowed_minutes) {
                    $targetTime = WorkTime::local($this->start_at->addMinutes($perm->allowed_minutes))?->format('H:i');
                }
                $effectiveStart = $targetTime;
                $item['type_label'] = 'Izin Terlambat';
                $item['time_label'] = $targetTime ? "Target hadir pk {$targetTime}" : "Izin {$perm->allowed_minutes} menit";
                $item['effective_clock'] = $targetTime;
                $item['badge_label'] = $targetTime ? "Izin Hadir {$targetTime}" : "Izin {$perm->allowed_minutes}m";
            } elseif ($type === 'early_departure') {
                $leaveTime = $perm->leave_at ? WorkTime::local($perm->leave_at)?->format('H:i') : null;
                $effectiveEnd = $leaveTime;
                $item['type_label'] = 'Izin Pulang Lebih Awal';
                $item['time_label'] = $leaveTime ? "Rencana pulang pk {$leaveTime}" : 'Pulang lebih awal';
                $item['effective_clock'] = $leaveTime;
                $item['badge_label'] = $leaveTime ? "Pulang Awal {$leaveTime}" : 'Pulang Awal';
            } elseif ($type === 'temporary_exit') {
                $leaveTime = $perm->leave_at ? WorkTime::local($perm->leave_at)?->format('H:i') : '—';
                $returnTime = $perm->return_at ? WorkTime::local($perm->return_at)?->format('H:i') : '—';
                $item['type_label'] = 'Izin Keluar Sementara';
                $item['time_label'] = "Keluar {$leaveTime} · kembali {$returnTime}";
                $item['leave_clock'] = $leaveTime;
                $item['return_clock'] = $returnTime;
                $item['badge_label'] = "Keluar {$leaveTime}–{$returnTime}";
            }

            $list[] = $item;
        }

        return [
            'effective_start' => $effectiveStart,
            'effective_end' => $effectiveEnd,
            'approved_permissions' => $list,
        ];
    }

    /** Menit kerja terjadwal: panjang sesi dikurangi istirahat tak dibayar. */
    public function scheduledMinutes(): int
    {
        return max(0, intdiv($this->end_at->getTimestamp() - $this->start_at->getTimestamp(), 60) - $this->unpaid_break_minutes);
    }

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'unpaid_break_minutes' => 'integer',
            'policy_snapshot' => 'array',
            'schedule_revision' => 'integer',
            'rules_version' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
