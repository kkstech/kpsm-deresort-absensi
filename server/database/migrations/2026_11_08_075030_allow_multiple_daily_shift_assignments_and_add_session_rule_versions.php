<?php

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
            $table->index(['employee_id', 'work_date']);
            $table->dropUnique('shift_assignments_employee_id_work_date_unique');
        });

        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('rules_version')->default(0)->after('schedule_revision');
        });

        $now = now('UTC')->toDateTimeString();
        DB::table('shift_sessions')->orderBy('id')->chunkById(500, function ($sessions) use ($now) {
            foreach ($sessions as $session) {
                $assignmentVersion = DB::table('shift_assignments')->where('id', $session->shift_assignment_id)->value('rules_version') ?? 0;
                $hasAttendance = DB::table('attendance_records')->where('shift_session_id', $session->id)->exists();
                $isFutureUnstarted = $session->start_at > $now && ! $hasAttendance;

                DB::table('shift_sessions')->where('id', $session->id)->update([
                    'rules_version' => $isFutureUnstarted ? 3 : $assignmentVersion,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropColumn('rules_version');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'work_date']);
        });
    }
};
