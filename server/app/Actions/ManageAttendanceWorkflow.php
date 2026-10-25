<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceCorrection;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LatePermission;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ManageAttendanceWorkflow
{
    public function submitLatePermission(User $actor, Employee $employee, int $sessionId, array $data, ?UploadedFile $evidence = null): LatePermission
    {
        return $this->transactionWithEvidence($evidence, function (?string $path) use ($actor, $employee, $sessionId, $data, $evidence) {
            [$lockedEmployee, $session] = $this->lockedSession($sessionId);
            if ($lockedEmployee->id !== $employee->id || $employee->user_id !== $actor->id) {
                throw new BusinessRuleException('FORBIDDEN', 'Sesi ini bukan milik Anda.', 403);
            }
            $this->assertRequestable($session);
            $this->assertOpen($session);
            if (WorkTime::now()->gt($session->start_at->addHours(config('attendance.late_request_deadline_hours')))) {
                throw new BusinessRuleException('LATE_REQUEST_EXPIRED', 'Batas pengajuan izin terlambat sudah lewat.');
            }
            $this->assertNoActivePermission($session);
            $this->assertLateTarget($session, $data);
            $this->assertEvidence($session, 'late', $evidence);

            $permission = LatePermission::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $lockedEmployee->id,
                'requester_id' => $actor->id,
                'allowed_until' => isset($data['allowed_until']) ? CarbonImmutable::parse($data['allowed_until'])->utc() : null,
                'allowed_minutes' => $data['allowed_minutes'] ?? null,
                'reason' => $data['reason'],
                'status' => 'PENDING',
            ]);
            $this->attachEvidence($permission, $actor, $evidence, $path);
            Audit::log('attendance.late_permission.requested', $permission);

            return $permission->load('attachments');
        });
    }

    public function createDirectLatePermission(User $actor, int $sessionId, array $data, ?UploadedFile $evidence = null): LatePermission
    {
        if (! $actor->isSuperAdmin()) {
            throw new BusinessRuleException('FORBIDDEN', 'Izin terlambat langsung hanya dapat dibuat Super Admin.', 403);
        }

        return $this->transactionWithEvidence($evidence, function (?string $path) use ($actor, $sessionId, $data, $evidence) {
            [, $session, $record] = $this->lockedSession($sessionId);
            $this->assertRequestable($session);
            $this->assertOpen($session);
            $this->assertNoActivePermission($session);
            $this->assertLateTarget($session, $data);
            $this->assertEvidence($session, 'late', $evidence);

            $permission = LatePermission::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $session->assignment->employee_id,
                'direct_by' => $actor->id,
                'allowed_until' => isset($data['allowed_until']) ? CarbonImmutable::parse($data['allowed_until'])->utc() : null,
                'allowed_minutes' => $data['allowed_minutes'] ?? null,
                'reason' => $data['reason'],
                'status' => 'APPROVED',
                'decided_by' => $actor->id,
                'decided_at' => WorkTime::now(),
                'decision_reason' => $data['reason'],
            ]);
            $this->attachEvidence($permission, $actor, $evidence, $path);
            if ($record?->check_in_at && $this->permissionCovers($session, $permission, $record->check_in_at)) {
                $before = $this->recordSnapshot($record);
                $record->update(['late_permission_id' => $permission->id]);
                Audit::log('attendance.late_permission.applied', $record, $before, $this->recordSnapshot($record));
            }
            Audit::log('attendance.late_permission.direct', $permission);

            return $permission->load('attachments');
        });
    }

    public function decideLatePermission(User $actor, int $permissionId, string $decision, string $reason): LatePermission
    {
        $item = LatePermission::query()->findOrFail($permissionId);

        return DB::transaction(function () use ($actor, $item, $permissionId, $decision, $reason) {
            [$employee, $session, $record] = $this->lockedSession($item->shift_session_id);
            $permission = LatePermission::query()->lockForUpdate()->findOrFail($permissionId);
            $this->authorizeApprover($actor, $employee, $permission->requester_id);
            $this->assertOpen($session);

            if ($permission->status !== 'PENDING') {
                if ($permission->status === $decision && (int) $permission->decided_by === $actor->id && $permission->decision_reason === $reason) {
                    return $permission->load('attachments');
                }
                throw new BusinessRuleException('STATE_CONFLICT', 'Permintaan ini sudah diputuskan.');
            }

            $before = $permission->attributesToArray();
            $permission->update([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => WorkTime::now(),
                'decision_reason' => $reason,
            ]);
            if ($decision === 'APPROVED' && $record?->check_in_at && $this->permissionCovers($session, $permission, $record->check_in_at)) {
                $recordBefore = $this->recordSnapshot($record);
                $record->update(['late_permission_id' => $permission->id]);
                Audit::log('attendance.late_permission.applied', $record, $recordBefore, $this->recordSnapshot($record));
            }
            Audit::log('attendance.late_permission.decided', $permission, $before, $permission->attributesToArray());

            return $permission->load('attachments');
        });
    }

    public function submitCorrection(User $actor, Employee $employee, int $sessionId, array $data, ?UploadedFile $evidence = null): AttendanceCorrection
    {
        return $this->transactionWithEvidence($evidence, function (?string $path) use ($actor, $employee, $sessionId, $data, $evidence) {
            [$lockedEmployee, $session] = $this->lockedSession($sessionId);
            if ($lockedEmployee->id !== $employee->id || $employee->user_id !== $actor->id) {
                throw new BusinessRuleException('FORBIDDEN', 'Sesi ini bukan milik Anda.', 403);
            }
            $this->assertRequestable($session);
            $this->assertOpen($session);
            $this->assertEvidence($session, 'correction', $evidence);
            if (AttendanceCorrection::query()->where('shift_session_id', $sessionId)->where('status', 'PENDING')->exists()) {
                throw new BusinessRuleException('REQUEST_PENDING', 'Sudah ada koreksi yang menunggu keputusan.');
            }

            $correction = AttendanceCorrection::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $lockedEmployee->id,
                'requester_id' => $actor->id,
                'requested_check_in_at' => isset($data['requested_check_in_at']) ? CarbonImmutable::parse($data['requested_check_in_at'])->utc() : null,
                'requested_check_out_at' => isset($data['requested_check_out_at']) ? CarbonImmutable::parse($data['requested_check_out_at'])->utc() : null,
                'reason' => $data['reason'],
                'status' => 'PENDING',
            ]);
            $this->attachEvidence($correction, $actor, $evidence, $path);
            Audit::log('attendance.correction.requested', $correction);

            return $correction->load('attachments');
        });
    }

    public function decideCorrection(User $actor, int $correctionId, string $decision, string $reason): AttendanceCorrection
    {
        if (! $actor->isAdmin()) {
            throw new BusinessRuleException('FORBIDDEN', 'Koreksi absensi hanya dapat diputuskan Admin.', 403);
        }
        $item = AttendanceCorrection::query()->findOrFail($correctionId);

        return DB::transaction(function () use ($actor, $item, $correctionId, $decision, $reason) {
            [, $session, $record] = $this->lockedSession($item->shift_session_id);
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correctionId);
            $this->assertOpen($session);

            if ($correction->status !== 'PENDING') {
                if ($correction->status === $decision && (int) $correction->decided_by === $actor->id && $correction->decision_reason === $reason) {
                    return $correction->load('attachments');
                }
                throw new BusinessRuleException('STATE_CONFLICT', 'Permintaan ini sudah diputuskan.');
            }

            $before = $correction->attributesToArray();
            $updates = ['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => WorkTime::now(), 'decision_reason' => $reason];
            if ($decision === 'APPROVED') {
                $recordBefore = $this->recordSnapshot($record);
                $record = $this->applyCorrection($session, $record, [
                    'requested_check_in_at' => $correction->requested_check_in_at,
                    'requested_check_out_at' => $correction->requested_check_out_at,
                ], $actor);
                $updates['applied_by'] = $actor->id;
                $updates['before_json'] = $recordBefore;
                $updates['after_json'] = $this->recordSnapshot($record);
                Audit::log('attendance.correction.applied', $record, $recordBefore, $updates['after_json']);
            }
            $correction->update($updates);
            Audit::log('attendance.correction.decided', $correction, $before, $correction->attributesToArray());

            return $correction->load('attachments');
        });
    }

    public function directCorrection(User $actor, int $sessionId, array $data, ?UploadedFile $evidence = null): AttendanceCorrection
    {
        if (! $actor->isAdmin()) {
            throw new BusinessRuleException('FORBIDDEN', 'Koreksi langsung hanya dapat dilakukan Admin.', 403);
        }

        return $this->transactionWithEvidence($evidence, function (?string $path) use ($actor, $sessionId, $data, $evidence) {
            [$employee, $session, $record] = $this->lockedSession($sessionId);
            $this->assertOpen($session);
            $this->assertEvidence($session, 'correction', $evidence);
            $before = $this->recordSnapshot($record);
            $afterRecord = $this->applyCorrection($session, $record, [
                'requested_check_in_at' => $data['check_in_at'] ?? null,
                'requested_check_out_at' => $data['check_out_at'] ?? null,
            ], $actor);
            $correction = AttendanceCorrection::query()->create([
                'shift_session_id' => $session->id,
                'employee_id' => $employee->id,
                'requester_id' => null,
                'requested_check_in_at' => isset($data['check_in_at']) ? CarbonImmutable::parse($data['check_in_at'])->utc() : null,
                'requested_check_out_at' => isset($data['check_out_at']) ? CarbonImmutable::parse($data['check_out_at'])->utc() : null,
                'reason' => $data['reason'],
                'status' => 'APPROVED',
                'decided_by' => $actor->id,
                'decided_at' => WorkTime::now(),
                'decision_reason' => $data['reason'],
                'applied_by' => $actor->id,
                'before_json' => $before,
                'after_json' => $this->recordSnapshot($afterRecord),
            ]);
            $this->attachEvidence($correction, $actor, $evidence, $path);
            Audit::log('attendance.correction.direct', $afterRecord, $before, $this->recordSnapshot($afterRecord));
            Audit::log('attendance.correction.created_direct', $correction);

            return $correction->load('attachments');
        });
    }

    public function verifyCheckout(User $actor, int $sessionId, string $checkOutAt, string $reason): AttendanceRecord
    {
        if (! $actor->isAdmin()) {
            throw new BusinessRuleException('FORBIDDEN', 'Verifikasi absensi hanya dapat dilakukan Admin.', 403);
        }

        $employeeId = DB::table('shift_sessions')->join('shift_assignments', 'shift_assignments.id', '=', 'shift_sessions.shift_assignment_id')
            ->where('shift_sessions.id', $sessionId)->value('shift_assignments.employee_id');

        return DB::transaction(function () use ($actor, $sessionId, $checkOutAt, $reason, $employeeId) {
            [, $session, $record] = $this->lockedSession($sessionId, (int) $employeeId);
            if (! $record || ! $record->check_in_at) {
                throw new BusinessRuleException('NOT_CHECKED_IN', 'Sesi belum memiliki check-in.');
            }
            $this->assertOpen($session);
            $checkOut = CarbonImmutable::parse($checkOutAt)->utc();
            $this->assertSessionTimes($session, $record->check_in_at, $checkOut);
            if ($record->status === 'VERIFIED') {
                if ($record->check_out_at?->equalTo($checkOut)) {
                    return $record;
                }
                throw new BusinessRuleException('STATE_CONFLICT', 'Checkout sudah diverifikasi; gunakan koreksi untuk mengubahnya.');
            }
            if (! $record->needs_review && ! $record->provisional_out_at) {
                throw new BusinessRuleException('NOT_REVIEWABLE', 'Sesi ini tidak menunggu verifikasi.');
            }

            $before = $this->recordSnapshot($record);
            $record->update([
                'check_out_at' => $checkOut,
                'status' => 'VERIFIED',
                'needs_review' => false,
                'review_reason' => null,
                'verified_minutes' => AttendanceRecord::paidMinutes($session, $record->check_in_at, $checkOut),
                'verified_at' => WorkTime::now(),
                'verified_by' => $actor->id,
            ]);
            $session->assignment->syncAttendanceStatus();
            app(CalculateCycleAllowance::class)->refresh($session->assignment);
            Audit::log('attendance.checkout.verified', $record, $before, [...$this->recordSnapshot($record), 'verification_reason' => $reason]);

            return $record->fresh();
        });
    }

    public function cancelLatePermission(User $actor, int $permissionId): LatePermission
    {
        $item = LatePermission::query()->findOrFail($permissionId);

        return DB::transaction(function () use ($actor, $item, $permissionId) {
            [$employee, $session] = $this->lockedSession($item->shift_session_id);
            $permission = LatePermission::query()->lockForUpdate()->findOrFail($permissionId);
            if ($employee->user_id !== $actor->id || $permission->requester_id !== $actor->id || $permission->status !== 'PENDING') {
                throw new BusinessRuleException('FORBIDDEN', 'Permintaan ini tidak dapat dibatalkan.', 403);
            }
            $this->assertOpen($session);
            $before = $permission->attributesToArray();
            $permission->update(['status' => 'CANCELLED']);
            Audit::log('attendance.late_permission.cancelled', $permission, $before, $permission->attributesToArray());

            return $permission;
        });
    }

    public function cancelCorrection(User $actor, int $correctionId): AttendanceCorrection
    {
        $item = AttendanceCorrection::query()->findOrFail($correctionId);

        return DB::transaction(function () use ($actor, $item, $correctionId) {
            [$employee, $session] = $this->lockedSession($item->shift_session_id);
            $correction = AttendanceCorrection::query()->lockForUpdate()->findOrFail($correctionId);
            if ($employee->user_id !== $actor->id || $correction->requester_id !== $actor->id || $correction->status !== 'PENDING') {
                throw new BusinessRuleException('FORBIDDEN', 'Permintaan ini tidak dapat dibatalkan.', 403);
            }
            $this->assertOpen($session);
            $before = $correction->attributesToArray();
            $correction->update(['status' => 'CANCELLED']);
            Audit::log('attendance.correction.cancelled', $correction, $before, $correction->attributesToArray());

            return $correction;
        });
    }

    private function lockedSession(int $sessionId, ?int $expectedEmployeeId = null): array
    {
        $employeeId = $expectedEmployeeId ?? DB::table('shift_sessions')
            ->join('shift_assignments', 'shift_assignments.id', '=', 'shift_sessions.shift_assignment_id')
            ->where('shift_sessions.id', $sessionId)->value('shift_assignments.employee_id');
        $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);
        $session = ShiftSession::query()->with('assignment')->lockForUpdate()->findOrFail($sessionId);
        if ($session->assignment->employee_id !== $employee->id) {
            throw new BusinessRuleException('STATE_CONFLICT', 'Pemilik sesi berubah. Coba lagi.');
        }
        $record = AttendanceRecord::query()->where('shift_session_id', $sessionId)->lockForUpdate()->first();

        return [$employee, $session, $record];
    }

    private function assertRequestable(ShiftSession $session): void
    {
        if (! in_array($session->assignment->status, ['PUBLISHED', 'IN_PROGRESS', 'COMPLETED'], true)) {
            throw new BusinessRuleException('SCHEDULE_NOT_PUBLISHED', 'Permintaan hanya dapat dibuat untuk jadwal terbit.');
        }
    }

    private function assertOpen(ShiftSession $session): void
    {
        AttendancePeriod::assertOpen($session->assignment->work_date);
    }

    private function assertNoActivePermission(ShiftSession $session): void
    {
        if (LatePermission::query()->where('shift_session_id', $session->id)->whereIn('status', ['PENDING', 'APPROVED'])->exists()) {
            throw new BusinessRuleException('REQUEST_PENDING', 'Sudah ada izin terlambat aktif untuk sesi ini.');
        }
    }

    private function assertLateTarget(ShiftSession $session, array $data): void
    {
        if (isset($data['allowed_until'])) {
            $allowedUntil = CarbonImmutable::parse($data['allowed_until'])->utc();
            if ($allowedUntil->lt($session->start_at) || $allowedUntil->gt($session->end_at)) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Target jam hadir harus berada dalam jendela sesi.', 422);
            }
        }
        if (isset($data['allowed_minutes']) && ($data['allowed_minutes'] < 0 || $data['allowed_minutes'] > 1440)) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Durasi izin harus 0 sampai 1440 menit.', 422);
        }
        if (! isset($data['allowed_until']) && ! isset($data['allowed_minutes'])) {
            throw new BusinessRuleException('VALIDATION_ERROR', 'Isi target jam hadir atau durasi izin.', 422);
        }
    }

    private function assertEvidence(ShiftSession $session, string $type, ?UploadedFile $evidence): void
    {
        $key = $type === 'late' ? 'late_permission_proof_required' : 'correction_proof_required';
        if (($session->policy_snapshot[$key] ?? false) && ! $evidence) {
            throw new BusinessRuleException('EVIDENCE_REQUIRED', 'Bukti pendukung wajib untuk permintaan ini.', 422, ['evidence' => ['Wajib dilampirkan.']]);
        }
    }

    private function authorizeApprover(User $actor, Employee $employee, ?int $requesterId): void
    {
        if ($actor->id === $requesterId) {
            throw new BusinessRuleException('SELF_APPROVAL', 'Pengaju tidak dapat menyetujui permintaannya sendiri.', 403);
        }
        if ($actor->isAdmin()) {
            return;
        }
        if ($actor->isSupervisor() && $actor->canSeeEmployee($employee)) {
            return;
        }
        throw new BusinessRuleException('FORBIDDEN', 'Tidak berwenang menyetujui izin ini.', 403);
    }

    private function permissionCovers(ShiftSession $session, LatePermission $permission, CarbonImmutable $checkIn): bool
    {
        return $permission->allowed_until
            ? $checkIn->lte($permission->allowed_until)
            : WorkTime::minutesBetween($session->start_at, $checkIn) <= $permission->allowed_minutes;
    }

    private function applyCorrection(ShiftSession $session, ?AttendanceRecord $record, array $data, User $actor): AttendanceRecord
    {
        $checkIn = array_key_exists('requested_check_in_at', $data) && $data['requested_check_in_at'] !== null
            ? CarbonImmutable::parse($data['requested_check_in_at'])->utc() : $record?->check_in_at;
        $checkOut = array_key_exists('requested_check_out_at', $data) && $data['requested_check_out_at'] !== null
            ? CarbonImmutable::parse($data['requested_check_out_at'])->utc() : $record?->check_out_at;
        $this->assertSessionTimes($session, $checkIn, $checkOut);
        if (! $checkIn) {
            throw new BusinessRuleException('CHECK_IN_REQUIRED', 'Koreksi checkout memerlukan waktu check-in.');
        }

        $record ??= new AttendanceRecord(['shift_session_id' => $session->id, 'employee_id' => $session->assignment->employee_id]);
        $before = $this->recordSnapshot($record);
        $late = WorkTime::minutesBetween($session->start_at, $checkIn);
        $permission = LatePermission::query()->where('shift_session_id', $session->id)->where('status', 'APPROVED')
            ->orderByDesc('id')->get()->first(fn (LatePermission $item) => $this->permissionCovers($session, $item, $checkIn));
        $provisionalPending = ! $checkOut && $record->provisional_out_at !== null;
        $record->fill([
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'raw_late_minutes' => $late,
            'is_late' => $late > ($session->policy_snapshot['grace_minutes'] ?? 0),
            'late_permission_id' => $permission?->id,
            'status' => $checkOut ? 'VERIFIED' : ($provisionalPending ? 'PROVISIONAL' : 'IN_PROGRESS'),
            'needs_review' => $provisionalPending,
            'review_reason' => $provisionalPending ? 'AUTO_CHECKOUT' : null,
            'verified_minutes' => $checkOut ? AttendanceRecord::paidMinutes($session, $checkIn, $checkOut) : 0,
            'verified_at' => $checkOut ? WorkTime::now() : null,
            'verified_by' => $checkOut ? $actor->id : null,
        ])->save();
        $session->assignment->syncAttendanceStatus();
        app(CalculateCycleAllowance::class)->refresh($session->assignment);

        return $record;
    }

    private function assertSessionTimes(ShiftSession $session, ?CarbonImmutable $checkIn, ?CarbonImmutable $checkOut): void
    {
        if ($checkIn && ($checkIn->lt($session->start_at->subMinutes(config('attendance.checkin_early_minutes'))) || $checkIn->gte($session->end_at))) {
            throw new BusinessRuleException('OUTSIDE_WINDOW', 'Waktu check-in berada di luar jendela sesi.', 422);
        }
        if ($checkOut && (! $checkIn || $checkOut->lte($checkIn) || $checkOut->gt($session->end_at->addMinutes(config('attendance.checkout_late_minutes'))))) {
            throw new BusinessRuleException('OUTSIDE_WINDOW', 'Waktu checkout berada di luar jendela sesi.', 422);
        }
    }

    private function recordSnapshot(?AttendanceRecord $record): ?array
    {
        return $record?->only([
            'check_in_event_id', 'check_out_event_id', 'late_permission_id', 'check_in_at', 'check_out_at',
            'provisional_out_at', 'status', 'needs_review', 'review_reason', 'raw_late_minutes', 'is_late',
            'verified_minutes', 'verified_at', 'verified_by',
        ]);
    }

    private function transactionWithEvidence(?UploadedFile $file, callable $operation): mixed
    {
        $path = $file?->store('attendance-evidence', 'local');
        if ($file && ! $path) {
            throw new BusinessRuleException('UPLOAD_FAILED', 'Bukti tidak dapat disimpan.', 500);
        }
        try {
            return DB::transaction(fn () => $operation($path));
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function attachEvidence(Model $parent, User $actor, ?UploadedFile $file, ?string $path): void
    {
        if (! $file || ! $path) {
            return;
        }
        $parent->attachments()->create([
            'uploaded_by' => $actor->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => basename($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
        ]);
    }
}
