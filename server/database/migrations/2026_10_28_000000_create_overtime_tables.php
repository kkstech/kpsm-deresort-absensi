<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('overtime_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('initiator_type', 20)->default('admin'); // admin | employee
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('planned_minutes');
            $table->unsignedSmallInteger('actual_minutes')->default(0);
            $table->unsignedSmallInteger('verified_minutes')->default(0);
            $table->boolean('is_day_off')->default(false);
            $table->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | CANCELLED
            $table->string('consent_status', 20)->default('PENDING'); // PENDING | ACCEPTED | DECLINED
            $table->dateTime('consent_at')->nullable();
            $table->text('consent_notes')->nullable();
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decision_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('overtime_order_id')->nullable()->after('late_permission_id')->constrained('overtime_orders')->nullOnDelete();
            $table->unsignedSmallInteger('verified_overtime_minutes')->default(0)->after('verified_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['overtime_order_id']);
            $table->dropColumn(['overtime_order_id', 'verified_overtime_minutes']);
        });
        Schema::dropIfExists('overtime_orders');
    }
};
