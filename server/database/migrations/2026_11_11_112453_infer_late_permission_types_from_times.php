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
        DB::table('late_permissions')
            ->whereNotNull('leave_at')
            ->whereNotNull('return_at')
            ->where('permission_type', '!=', 'temporary_exit')
            ->update(['permission_type' => 'temporary_exit']);

        DB::table('late_permissions')
            ->whereNotNull('leave_at')
            ->whereNull('return_at')
            ->where('permission_type', 'late_arrival')
            ->update(['permission_type' => 'early_departure']);
    }

    public function down(): void
    {
        // The corrected request types cannot be reversed without losing their original intent.
    }
};
