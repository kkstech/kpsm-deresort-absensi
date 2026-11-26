<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['late_permissions', 'attendance_corrections'] as $table) {
            DB::table($table)
                ->join('shift_sessions', 'shift_sessions.id', '=', "{$table}.shift_session_id")
                ->join('shift_assignments', 'shift_assignments.id', '=', 'shift_sessions.shift_assignment_id')
                ->where("{$table}.status", 'PENDING')
                ->get(["{$table}.id", "{$table}.employee_id", 'shift_assignments.work_date'])
                ->each(fn ($item) => DB::table($table)->where('id', $item->id)->update([
                    'approval_tier' => Employee::query()->findOrFail($item->employee_id)->approvalTierFor((string) $item->work_date),
                ]));
        }
    }

    public function down(): void {}
};
