<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_levels', function (Blueprint $table) {
            $table->boolean('requires_super_admin_approval')->default(false)->after('name');
        });

        foreach (['leave_requests', 'late_permissions', 'attendance_corrections', 'overtime_orders', 'shift_exchange_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('approval_tier', 16)->default('admin');
                $table->index(['status', 'approval_tier']);
            });
        }

        DB::table('leave_requests')->where('status', 'PENDING')->update(['approval_tier' => 'super_admin']);
    }

    public function down(): void
    {
        foreach (['leave_requests', 'late_permissions', 'attendance_corrections', 'overtime_orders', 'shift_exchange_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['status', 'approval_tier']);
                $table->dropColumn('approval_tier');
            });
        }

        Schema::table('employee_levels', function (Blueprint $table) {
            $table->dropColumn('requires_super_admin_approval');
        });
    }
};
