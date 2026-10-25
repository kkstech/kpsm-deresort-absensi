<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable()->after('position_id')->constrained('employee_levels')->nullOnDelete();
        });

        Schema::create('employment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('unknown'); // unknown | permanent | contract
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'starts_on']);
            $table->index(['employee_id', 'ends_on']);
        });

        Schema::create('employee_level_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('employee_levels')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_to']);
        });

        foreach (DB::table('employees')->get(['id', 'started_on', 'ended_on']) as $employee) {
            DB::table('employment_terms')->insert([
                'employee_id' => $employee->id,
                'type' => 'unknown',
                'starts_on' => $employee->started_on,
                'ends_on' => $employee->ended_on,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('allowance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained('employee_levels')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('meal_amount');
            $table->unsignedBigInteger('transport_amount');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['level_id', 'effective_from']);
            $table->index(['level_id', 'effective_to']);
        });

        Schema::create('employee_allowance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('meal_amount')->nullable();
            $table->unsignedBigInteger('transport_amount')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_to']);
        });

        Schema::create('allowance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_assignment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('level_id')->nullable()->constrained('employee_levels')->nullOnDelete();
            $table->json('rate_snapshot');
            $table->unsignedBigInteger('meal_amount')->default(0);
            $table->unsignedBigInteger('transport_amount')->default(0);
            $table->unsignedInteger('verified_minutes')->default(0);
            $table->string('status', 32)->default('PENDING_VERIFICATION');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();
            $table->index(['work_date', 'status']);
            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('leave_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedSmallInteger('regular_quota')->default(12);
            $table->unsignedSmallInteger('day_off_quota')->default(4);
            $table->dateTime('opening_confirmed_at')->nullable();
            $table->foreignId('opening_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('type', 16); // regular | day_off
            $table->string('status', 16)->default('PENDING');
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('direct_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'work_date', 'status']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('type', 16);
            $table->smallInteger('delta');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'year', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_years');
        Schema::dropIfExists('allowance_results');
        Schema::dropIfExists('employee_allowance_overrides');
        Schema::dropIfExists('allowance_policies');
        Schema::dropIfExists('employment_terms');
        Schema::dropIfExists('employee_level_assignments');
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('level_id'));
        Schema::dropIfExists('employee_levels');
    }
};
