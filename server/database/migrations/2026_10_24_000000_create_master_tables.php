<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mode'); // none | radius
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_meters')->nullable();
            $table->unsignedInteger('max_accuracy_meters')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('role')->default('employee'); // super_admin | admin | employee
            $table->boolean('can_view_all')->default(false);
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status'); // active | inactive
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
        });

        Schema::create('employee_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // regular | special
            $table->string('name')->unique();
            $table->string('repeat_pattern')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_template_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->time('local_start');
            $table->time('local_end');
            $table->unsignedTinyInteger('day_offset')->default(0);
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->timestamps();
            $table->unique(['shift_template_id', 'seq']);
        });

        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type'); // global | department | employee
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->boolean('use_location_check')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });

        // ponytail: table only. Write a row from master CRUD; no model observer until that screen exists.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attendance_policies');
        Schema::dropIfExists('shift_template_sessions');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('employee_placements');
        Schema::dropIfExists('employees');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['is_active', 'role', 'can_view_all']);
        });

        Schema::dropIfExists('work_locations');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
