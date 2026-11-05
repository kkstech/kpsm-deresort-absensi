<?php

namespace App\Actions;

use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\LeaveYearQuota;

class CalculateLeaveBalance
{
    public function for(Employee $employee, int $year): array
    {
        $settings = LeaveYear::query()->firstOrCreate(['year' => $year]);
        $settings->ensureTypeQuotas();
        $month = (int) substr($employee->started_on, 5, 2);
        $hireYear = (int) substr($employee->started_on, 0, 4);
        $months = $hireYear === $year ? 13 - $month : ($hireYear < $year ? 12 : 0);
        $types = [];
        foreach (LeaveType::query()->orderBy('id')->get() as $type) {
            $quota = (int) LeaveYearQuota::query()
                ->where('leave_year_id', $settings->id)
                ->where('leave_type_id', $type->id)
                ->value('quota');
            $entitled = $hireYear === $year ? (int) floor($quota * $months / 12) : ($hireYear < $year ? $quota : 0);
            $adjustment = (int) LeaveAdjustment::query()->where('employee_id', $employee->id)->where('year', $year)->where('type', $type->code)->sum('delta');
            $requests = LeaveRequest::query()->where('employee_id', $employee->id)->whereYear('work_date', $year)->where('type', $type->code);
            $used = (int) (clone $requests)->where('status', 'APPROVED')->sum('days_count');
            $reserved = (int) (clone $requests)->where('status', 'PENDING')->sum('days_count');
            $total = max(0, $entitled + $adjustment);
            $types[] = [
                'code' => $type->code,
                'name' => $type->name,
                'is_active' => (bool) $type->is_active,
                'default_quota' => (int) $type->default_quota,
                'quota' => $quota,
                'entitled' => $total,
                'used' => $used,
                'reserved' => $reserved,
                'available' => max(0, $total - $used - $reserved),
            ];
        }

        $byCode = collect($types)->keyBy('code');

        return [
            'year' => $year,
            'opening_confirmed' => (bool) $settings->opening_confirmed_at,
            'types' => $types,
            'regular' => $byCode->get('regular', $this->emptyBalance()),
            'day_off' => $byCode->get('day_off', $this->emptyBalance()),
        ];
    }

    private function emptyBalance(): array
    {
        return ['entitled' => 0, 'used' => 0, 'reserved' => 0, 'available' => 0];
    }
}
