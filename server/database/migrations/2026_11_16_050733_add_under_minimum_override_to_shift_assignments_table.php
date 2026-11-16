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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('allow_under_eight_hours');
        });
    }
};
