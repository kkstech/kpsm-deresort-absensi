<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_exchange_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_assignment_id')->constrained('shift_assignments')->restrictOnDelete();
            $table->foreignId('target_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('target_assignment_id')->nullable()->constrained('shift_assignments')->nullOnDelete();
            $table->string('swap_mode', 20)->default('direct'); // direct | delegation
            $table->string('vacancy_action', 20)->nullable(); // roster_off | regular | day_off
            $table->text('reason');
            $table->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | CANCELLED
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['target_employee_id', 'status']);
            $table->index(['shift_assignment_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_exchange_requests');
    }
};
