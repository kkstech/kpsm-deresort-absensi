<?php

namespace App\Actions;

use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;

class CalculateLeaveBalance
{
    public function for(Employee $employee, int $year): array
    {
        $settings = LeaveYear::query()->firstOrCreate(['year' => $year], ['regular_quota' => 12, 'day_off_quota' => 4]);
        $month = (int) substr($employee->started_on, 5, 2);
        $hireYear = (int) substr($employee->started_on, 0, 4);
        $months = $hireYear === $year ? 13 - $month : ($hireYear < $year ? 12 : 0);
        $result = [];
        foreach (['regular' => (int) $settings->regular_quota, 'day_off' => (int) $settings->day_off_quota] as $type => $quota) {
            $entitled = $hireYear === $year ? (int) floor($quota * $months / 12) : ($hireYear < $year ? $quota : 0);
            $adjustment = (int) LeaveAdjustment::query()->where('employee_id', $employee->id)->where('year', $year)->where('type', $type)->sum('delta');
            $requests = LeaveRequest::query()->where('employee_id', $employee->id)->whereYear('work_date', $year)->where('type', $type);
            $used = (clone $requests)->where('status', 'APPROVED')->count();
            $reserved = (clone $requests)->where('status', 'PENDING')->count();
            $total = max(0, $entitled + $adjustment);
            $result[$type] = ['entitled' => $total, 'used' => $used, 'reserved' => $reserved, 'available' => max(0, $total - $used - $reserved)];
        }

        return ['year' => $year, 'opening_confirmed' => (bool) $settings->opening_confirmed_at, 'regular' => $result['regular'], 'day_off' => $result['day_off']];
    }
}
