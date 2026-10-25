<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('rules_version')->default(0);
        });

        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->unsignedInteger('schedule_revision')->default(1);
            $table->timestamp('retired_at')->nullable();
            $table->dropUnique('shift_sessions_shift_assignment_id_seq_unique');
            $table->unique(['shift_assignment_id', 'schedule_revision', 'seq'], 'shift_sessions_revision_seq_unique');
            $table->index(['shift_assignment_id', 'retired_at']);
        });

        Schema::table('shift_template_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_day_offset')->default(0)->after('local_end');
        });
    }

    public function down(): void
    {
        Schema::table('shift_template_sessions', function (Blueprint $table) {
            $table->dropColumn('start_day_offset');
        });

        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropIndex(['shift_assignment_id', 'retired_at']);
            $table->dropUnique('shift_sessions_revision_seq_unique');
            $table->dropColumn(['schedule_revision', 'retired_at']);
            $table->unique(['shift_assignment_id', 'seq']);
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('rules_version');
        });
    }
};
