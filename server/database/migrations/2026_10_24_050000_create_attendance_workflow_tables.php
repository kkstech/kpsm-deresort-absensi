<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->boolean('late_permission_proof_required')->default(false);
            $table->boolean('correction_proof_required')->default(false);
        });

        Schema::create('late_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('direct_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('allowed_until')->nullable();
            $table->unsignedSmallInteger('allowed_minutes')->nullable();
            $table->text('reason');
            $table->string('status')->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['shift_session_id', 'status']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('requested_check_in_at')->nullable();
            $table->dateTime('requested_check_out_at')->nullable();
            $table->text('reason');
            $table->string('status')->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['shift_session_id', 'status']);
        });

        Schema::create('attendance_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('late_permission_id')->nullable()->after('check_out_event_id')->constrained('late_permissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('late_permission_id');
        });
        Schema::dropIfExists('attendance_attachments');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('late_permissions');
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['late_permission_proof_required', 'correction_proof_required']);
        });
    }
};
