<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_uuid', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('event_count')->default(0);
            $table->json('summary')->nullable();
            $table->dateTime('received_at');
            $table->timestamps();
        });

        // Event mentah: tidak pernah diubah atau dihapus. Koreksi disimpan di tabel lain.
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uuid', 64)->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sync_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // CHECK_IN | CHECK_OUT
            $table->string('source'); // ONLINE | OFFLINE
            $table->dateTime('server_received_at');
            $table->dateTime('device_time')->nullable();
            $table->string('device_timezone', 64)->nullable();
            $table->dateTime('effective_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedInteger('local_seq')->nullable();
            $table->string('result'); // ACCEPTED | REVIEW_REQUIRED | REJECTED
            $table->string('reason_code')->nullable();
            $table->dateTime('created_at');
            $table->index(['employee_id', 'effective_at']);
            $table->index('shift_session_id');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('check_in_event_id')->nullable()->constrained('attendance_events')->nullOnDelete();
            $table->foreignId('check_out_event_id')->nullable()->constrained('attendance_events')->nullOnDelete();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->dateTime('provisional_out_at')->nullable();
            $table->string('status'); // IN_PROGRESS | COMPLETE | PROVISIONAL | VERIFIED
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->unsignedInteger('raw_late_minutes')->default(0);
            $table->boolean('is_late')->default(false);
            $table->unsignedInteger('verified_minutes')->default(0);
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index('needs_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('sync_batches');
    }
};
