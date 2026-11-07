<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pendingLeaves = DB::table('leave_requests')
            ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
            ->where('leave_requests.status', 'PENDING')
            ->get([
                'leave_requests.id',
                'leave_requests.work_date',
                'employees.id as employee_id',
                'employees.level_id',
            ]);

        foreach ($pendingLeaves as $leave) {
            $assignment = DB::table('employee_level_assignments')
                ->where('employee_id', $leave->employee_id)
                ->where('effective_from', '<=', $leave->work_date)
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $leave->work_date))
                ->orderByDesc('effective_from')
                ->first(['level_id']);

            $levelId = $assignment?->level_id ?? $leave->level_id;
            $requiresSuperAdmin = $levelId
                ? DB::table('employee_levels')->where('id', $levelId)->value('requires_super_admin_approval')
                : false;

            DB::table('leave_requests')->where('id', $leave->id)->update([
                'approval_tier' => $requiresSuperAdmin ? 'super_admin' : 'admin',
            ]);
        }
    }

    public function down(): void {}
};
