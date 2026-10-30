<?php

namespace App\Actions;

use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\ShiftSession;
use App\Models\WorkLocation;

class RefreshOpenPolicySnapshots
{
    public function forEmployees(iterable $employeeIds): void
    {
        $ids = collect($employeeIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $sessions = ShiftSession::query()
            ->whereHas('assignment', fn ($query) => $query->whereIn('employee_id', $ids)->where('status', '!=', 'CANCELLED'))
            ->whereDoesntHave('attendanceRecord')
            ->with(['assignment.employee.workLocation'])
            ->get();

        foreach ($sessions as $session) {
            $session->update([
                'policy_snapshot' => AttendancePolicy::snapshotFor($session->assignment->employee, $session->assignment->work_date),
            ]);
        }
    }

    public function forLocation(WorkLocation $location): void
    {
        $this->forEmployees(Employee::query()->where('work_location_id', $location->id)->pluck('id'));
    }

    public function forPolicy(AttendancePolicy $policy): void
    {
        $employees = Employee::query();
        if ($policy->scope_type === 'employee') {
            $this->forEmployees([$policy->employee_id]);

            return;
        }
        if ($policy->scope_type === 'department') {
            $this->forEmployees($employees->where('department_id', $policy->department_id)->pluck('id'));

            return;
        }
        $this->forEmployees($employees->pluck('id'));
    }
}
