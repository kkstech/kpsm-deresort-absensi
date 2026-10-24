<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bulan kerja lokal (YYYY-MM). Status FINALIZED mengunci semua perubahan di bulan itu.
        Schema::create('attendance_periods', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique();
            $table->string('status')->default('OPEN'); // OPEN | FINALIZED
            $table->unsignedInteger('revision')->default(0);
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_name');
            $table->string('template_type');
            $table->string('status')->default('DRAFT'); // DRAFT | PUBLISHED | IN_PROGRESS | COMPLETED | CANCELLED
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('shift_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->json('policy_snapshot');
            $table->timestamps();
            $table->unique(['shift_assignment_id', 'seq']);
            $table->index('start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_sessions');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('attendance_periods');
    }
};
