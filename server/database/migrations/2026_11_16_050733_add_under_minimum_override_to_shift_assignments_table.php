<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->boolean('allow_under_eight_hours')->default(false)->after('template_type');
        });

        DB::table('shift_assignments')->select('id')->orderBy('id')->chunkById(200, function ($assignments): void {
            foreach ($assignments as $assignment) {
                $sessions = DB::table('shift_sessions')
                    ->where('shift_assignment_id', $assignment->id)
                    ->whereNull('retired_at')
                    ->get(['start_at', 'end_at', 'unpaid_break_minutes']);
                $workMinutes = $sessions->sum(function ($session): int {
                    $start = CarbonImmutable::parse($session->start_at, 'UTC')->getTimestamp();
                    $end = CarbonImmutable::parse($session->end_at, 'UTC')->getTimestamp();

                    return max(0, intdiv($end - $start, 60) - (int) $session->unpaid_break_minutes);
                });

                if ($sessions->isNotEmpty() && $workMinutes < 480) {
                    DB::table('shift_assignments')->where('id', $assignment->id)->update(['allow_under_eight_hours' => true]);
                }
            }
        });

        $activeAssignments = DB::table('shift_assignments')
            ->whereIn('status', ['DRAFT', 'PUBLISHED', 'IN_PROGRESS'])
            ->where(function ($query): void {
                $query->where('work_date', '>=', now('Asia/Jakarta')->toDateString())
                    ->orWhereExists(function ($openRecords): void {
                        $openRecords->selectRaw('1')
                            ->from('shift_sessions')
                            ->join('attendance_records', 'attendance_records.shift_session_id', '=', 'shift_sessions.id')
                            ->whereColumn('shift_sessions.shift_assignment_id', 'shift_assignments.id')
                            ->whereNull('shift_sessions.retired_at')
                            ->whereNotNull('attendance_records.check_in_at')
                            ->whereNull('attendance_records.check_out_at')
                            ->whereNull('attendance_records.provisional_out_at');
                    });
            });
        $assignmentIds = (clone $activeAssignments)->pluck('id');

        $activeAssignments->update(['rules_version' => 5]);
        DB::table('shift_sessions')->whereIn('shift_assignment_id', $assignmentIds)->whereNull('retired_at')->update(['rules_version' => 5]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $assignmentIds = DB::table('shift_assignments')->where('rules_version', 5)->pluck('id');
        DB::table('shift_sessions')->whereIn('shift_assignment_id', $assignmentIds)->whereNull('retired_at')->update(['rules_version' => 4]);
        DB::table('shift_assignments')->whereIn('id', $assignmentIds)->update(['rules_version' => 4]);

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('allow_under_eight_hours');
        });
    }
};
