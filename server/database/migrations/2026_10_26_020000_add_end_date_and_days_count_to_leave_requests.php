<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('work_date');
            $table->unsignedSmallInteger('days_count')->default(1)->after('end_date');
            $table->index(['employee_id', 'end_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'end_date', 'status']);
            $table->dropColumn(['end_date', 'days_count']);
        });
    }
};
