<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $activeAssignments = DB::table('shift_assignments')
            ->whereIn('status', ['DRAFT', 'PUBLISHED', 'IN_PROGRESS'])
            ->where(function ($query) {
                $query->where('work_date', '>=', now('Asia/Jakarta')->toDateString())
                    ->orWhereExists(function ($openRecords) {
                        $openRecords->selectRaw('1')
                            ->from('shift_sessions')
                            ->join('attendance_records', 'attendance_records.shift_session_id', '=', 'shift_sessions.id')
                            ->whereColumn('shift_sessions.shift_assignment_id', 'shift_assignments.id')
                            ->whereNotNull('attendance_records.check_in_at')
                            ->whereNull('attendance_records.check_out_at')
                            ->whereNull('attendance_records.provisional_out_at');
                    });
            });

        $assignmentIds = (clone $activeAssignments)->pluck('id');

        $activeAssignments->update(['rules_version' => 4]);

        DB::table('shift_sessions')
            ->whereIn('shift_assignment_id', $assignmentIds)
            ->whereNull('retired_at')
            ->update(['rules_version' => 4]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $assignmentIds = DB::table('shift_assignments')
            ->whereIn('status', ['DRAFT', 'PUBLISHED', 'IN_PROGRESS'])
            ->where('rules_version', 4)
            ->pluck('id');

        DB::table('shift_sessions')
            ->whereIn('shift_assignment_id', $assignmentIds)
            ->whereNull('retired_at')
            ->update(['rules_version' => 3]);

        DB::table('shift_assignments')->whereIn('id', $assignmentIds)->update(['rules_version' => 3]);
    }
};
