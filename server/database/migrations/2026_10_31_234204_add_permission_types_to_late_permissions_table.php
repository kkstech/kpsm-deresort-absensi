<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('late_permissions', function (Blueprint $table) {
            $table->string('permission_type', 32)->default('late_arrival')->after('shift_session_id');
            $table->dateTime('leave_at')->nullable()->after('allowed_minutes');
            $table->dateTime('return_at')->nullable()->after('leave_at');
            $table->index(['shift_session_id', 'permission_type', 'status'], 'late_permissions_type_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('late_permissions', function (Blueprint $table) {
            $table->dropIndex('late_permissions_type_status_index');
            $table->dropColumn(['permission_type', 'leave_at', 'return_at']);
        });
    }
};
