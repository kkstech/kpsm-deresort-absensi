<?php

namespace App\Actions;

use App\Models\AllowancePolicy;
use App\Models\AllowanceResult;
use App\Models\AttendanceCorrection;
use App\Models\EmployeeAllowanceOverride;
use App\Models\EmployeeLevel;
use App\Models\EmployeeLevelAssignment;
use App\Models\LatePermission;
use App\Models\ShiftAssignment;
use App\Support\Audit;
use App\Support\WorkTime;
use Illuminate\Support\Facades\DB;

class CalculateCycleAllowance
{
    public function refresh(ShiftAssignment $assignment): ?AllowanceResult
    {
        $assignment = ShiftAssignment::query()->with('employee', 'sessions.attendanceRecord')
            ->findOrFail($assignment->id);
        $result = AllowanceResult::query()->where('shift_assignment_id', $assignment->id)->first();
        $result ??= $this->snapshot($assignment);
        $verifiedMinutes = $assignment->sessions->sum(fn ($session) => $session->attendanceRecord?->verified_minutes ?? 0);
        $sessionIds = $assignment->sessions->pluck('id');
        $hasApprovedException = LatePermission::query()->whereIn('shift_session_id', $sessionIds)->where('status', 'APPROVED')->exists()
            || AttendanceCorrection::query()->whereIn('shift_session_id', $sessionIds)->where('status', 'APPROVED')->exists();
        $closed = $assignment->sessions->isNotEmpty() && $assignment->sessions->every(fn ($session) => ($session->attendanceRecord && ($session->attendanceRecord->check_out_at || $session->attendanceRecord->provisional_out_at))
            || (! $session->attendanceRecord && $session->end_at->lte(WorkTime::now()))
        );

        $status = match (true) {
            ! $result->rate_snapshot['configured'] => 'CONFIGURATION_REQUIRED',
            $verifiedMinutes >= 480 || ($hasApprovedException && $closed) => 'ELIGIBLE',
            $closed => 'NO_VERIFIED_ATTENDANCE',
            default => 'PENDING_VERIFICATION',
        };
        $rate = $result->rate_snapshot;
        $before = $result->attributesToArray();
        $result->fill([
            'verified_minutes' => $verifiedMinutes,
            'status' => $status,
            'meal_amount' => $status === 'ELIGIBLE' ? $rate['meal_amount'] : 0,
            'transport_amount' => $status === 'ELIGIBLE' ? $rate['transport_amount'] : 0,
            'calculated_at' => $status === 'PENDING_VERIFICATION' ? null : WorkTime::now(),
        ])->save();
        if (($before['status'] ?? null) !== $status || (int) ($before['meal_amount'] ?? 0) !== (int) $result->meal_amount || (int) ($before['transport_amount'] ?? 0) !== (int) $result->transport_amount) {
            Audit::log('allowances.cycle_calculated', $result, $before, $result->attributesToArray());
        }

        return $result;
    }

    public function snapshot(ShiftAssignment $assignment): AllowanceResult
    {
        return DB::transaction(function () use ($assignment) {
            $locked = ShiftAssignment::query()->with('employee')->lockForUpdate()->findOrFail($assignment->id);
            $existing = AllowanceResult::query()->where('shift_assignment_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $date = $locked->work_date;
            $employee = $locked->employee;
            $levelAssignment = EmployeeLevelAssignment::query()
                ->where('employee_id', $employee->id)
                ->where('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
                ->orderByDesc('effective_from')->first();
            $levelId = $levelAssignment?->level_id ?? $employee->level_id;
            $level = $levelId ? EmployeeLevel::query()->find($levelId) : null;
            $policy = $levelId ? AllowancePolicy::query()
                ->where('level_id', $levelId)
                ->where('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
                ->orderByDesc('effective_from')->first() : null;
            $override = EmployeeAllowanceOverride::query()
                ->where('employee_id', $employee->id)
                ->where('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
                ->orderByDesc('effective_from')->first();
            $meal = $override?->meal_amount ?? $policy?->meal_amount;
            $transport = $override?->transport_amount ?? $policy?->transport_amount;
            $snapshot = [
                'configured' => $levelId !== null && $policy !== null,
                'level_id' => $levelId,
                'level_name' => $level?->name,
                'policy_id' => $policy?->id,
                'override_id' => $override?->id,
                'effective_date' => $date,
                'meal_amount' => (int) ($meal ?? 0),
                'transport_amount' => (int) ($transport ?? 0),
            ];

            return AllowanceResult::query()->create([
                'shift_assignment_id' => $locked->id,
                'employee_id' => $employee->id,
                'work_date' => $date,
                'level_id' => $levelId,
                'rate_snapshot' => $snapshot,
                'status' => $snapshot['configured'] ? 'PENDING_VERIFICATION' : 'CONFIGURATION_REQUIRED',
            ]);
        });
    }
}
