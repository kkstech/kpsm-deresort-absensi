<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OvertimeOrder;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManageOvertime
{
    /** Admin menerbitkan SPL untuk satu atau beberapa karyawan. */
    public function createOrders(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $workDate = $data['work_date'];
            AttendancePeriod::assertOpen($workDate);

            $employees = Employee::query()->whereIn('id', $data['employee_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($employees->isEmpty()) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Pilih minimal satu karyawan aktif.', 422);
            }

            $startOffset = (int) ($data['start_day_offset'] ?? 0);
            $startAt = WorkTime::at($workDate, $data['start_time'], $startOffset);
            $endOffset = isset($data['end_day_offset'])
                ? (int) $data['end_day_offset']
                : $startOffset + ((! empty($data['is_cross_day']) || $data['end_time'] < $data['start_time']) ? 1 : 0);
            $endAt = WorkTime::at($workDate, $data['end_time'], $endOffset);
            $plannedMinutes = WorkTime::minutesBetween($startAt, $endAt);
            if ($plannedMinutes <= 0) {
                throw new BusinessRuleException('OVERTIME_INVALID', 'Jam selesai lembur harus setelah jam mulai.', 422);
            }

            $targets = collect($data['session_targets'] ?? [])->keyBy('employee_id');
            if ($targets->keys()->diff($employees->pluck('id'))->isNotEmpty()) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Pilihan sesi shift harus berasal dari karyawan yang dipilih.', 422);
            }

            $created = [];
            foreach ($employees as $employee) {
                $assignments = ShiftAssignment::query()
                    ->with('sessions')
                    ->where('employee_id', $employee->id)
                    ->where('work_date', $workDate)
                    ->where('status', '!=', 'CANCELLED')
                    ->get();
                $sessions = $assignments->flatMap(fn (ShiftAssignment $item) => $item->sessions);
                $target = $targets->get($employee->id);
                $targetId = isset($target['shift_session_id'])
                    ? (int) $target['shift_session_id']
                    : null;

                $isDayOff = false;
                $session = $targetId !== null ? $sessions->firstWhere('id', $targetId) : null;
                if ($targetId !== null && ! $session) {
                    throw new BusinessRuleException('SHIFT_SESSION_INVALID', "Sesi lembur yang dipilih bukan milik {$employee->name} pada tanggal {$workDate}.", 422);
                }
                if ($session === null && $sessions->count() === 1) {
                    $session = $sessions->first();
                } elseif ($session === null && $sessions->count() > 1) {
                    throw new BusinessRuleException('SHIFT_SESSION_REQUIRED', "Pilih sesi shift untuk {$employee->name} sebelum menerbitkan SPL.", 422, [
                        'employee_id' => $employee->id,
                        'shift_session_ids' => $sessions->pluck('id')->all(),
                    ]);
                }

                if ($session) {
                    $assignment = $assignments->firstWhere('id', $session->shift_assignment_id);
                    $this->assertNoOvertimeConflict($employee, $startAt, $endAt);
                } else {
                    if ($assignments->isNotEmpty()) {
                        throw new BusinessRuleException('SHIFT_SESSION_REQUIRED', "Pilih sesi shift untuk {$employee->name} sebelum menerbitkan SPL.", 422, [
                            'employee_id' => $employee->id,
                        ]);
                    }
                    // Lembur di hari libur: buat jadwal khusus agar bisa check-in/out
                    $isDayOff = true;
                    $assignment = ShiftAssignment::query()->create([
                        'employee_id' => $employee->id,
                        'work_date' => $workDate,
                        'template_name' => 'Lembur Hari Libur',
                        'template_type' => 'special',
                        'rules_version' => 4,
                        'status' => 'PUBLISHED',
                        'published_at' => now(),
                        'created_by' => $actor->id,
                    ]);

                    $policy = AttendancePolicy::resolveFor($employee, $workDate);
                    $session = $assignment->sessions()->create([
                        'seq' => 1,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                        'unpaid_break_minutes' => 0,
                        'policy_snapshot' => $policy,
                        'schedule_revision' => 1,
                        'rules_version' => 4,
                    ]);
                }

                $orderNumber = 'SPL-'.CarbonImmutable::parse($workDate)->format('ymd').'-'.strtoupper(Str::random(5));

                $order = OvertimeOrder::query()->create([
                    'order_number' => $orderNumber,
                    'employee_id' => $employee->id,
                    'work_date' => $workDate,
                    'shift_session_id' => $session?->id,
                    'initiator_type' => 'admin',
                    'created_by' => $actor->id,
                    'title' => $data['title'],
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'planned_minutes' => $plannedMinutes,
                    'is_day_off' => $isDayOff,
                    'status' => 'APPROVED',
                    'consent_status' => 'PENDING',
                    'decision_by' => $actor->id,
                    'decision_at' => now(),
                    'decision_notes' => 'Diterbitkan langsung oleh manajemen',
                ]);

                Audit::log('overtime.ordered', $order, null, $order->toArray());
                $created[] = $order;
            }

            return $created;
        });
    }

    /** Karyawan mengajukan permohonan lembur dari mobile app. */
    public function submitRequest(Employee $employee, array $data): OvertimeOrder
    {
        return DB::transaction(function () use ($employee, $data) {
            Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $workDate = $data['work_date'];
            AttendancePeriod::assertOpen($workDate);

            $assignments = ShiftAssignment::query()
                ->with('sessions.attendanceRecord')
                ->where('employee_id', $employee->id)
                ->where('work_date', $workDate)
                ->where('status', '!=', 'CANCELLED')
                ->get();
            $sessions = $assignments->flatMap(fn (ShiftAssignment $assignment) => $assignment->sessions);
            $targetId = isset($data['shift_session_id']) ? (int) $data['shift_session_id'] : null;
            $session = $targetId !== null ? $sessions->firstWhere('id', $targetId) : null;
            if ($targetId !== null && ! $session) {
                throw new BusinessRuleException('SHIFT_SESSION_INVALID', 'Sesi shift yang dipilih tidak termasuk jadwal Anda pada tanggal tersebut.', 422);
            }
            if ($session === null && $sessions->count() === 1) {
                $session = $sessions->first();
            } elseif ($session === null && $sessions->count() > 1) {
                throw new BusinessRuleException('SHIFT_SESSION_REQUIRED', 'Pilih sesi shift yang menjadi dasar lembur.', 422, [
                    'shift_session_ids' => $sessions->pluck('id')->all(),
                ]);
            }
            if (! $session?->attendanceRecord?->check_in_at) {
                throw new BusinessRuleException(
                    'SHIFT_NOT_STARTED',
                    'Pengajuan lembur dapat dikirim setelah Anda check-in pada sesi yang dipilih.',
                    422,
                );
            }

            $startDate = $data['start_date'] ?? WorkTime::local($session->end_at)->toDateString();
            $endDate = $data['end_date'] ?? CarbonImmutable::parse($startDate)
                ->addDays((! empty($data['is_cross_day']) || $data['end_time'] < $data['start_time']) ? 1 : 0)
                ->toDateString();
            $startAt = WorkTime::at($startDate, $data['start_time']);
            $endAt = WorkTime::at($endDate, $data['end_time']);
            $plannedMinutes = WorkTime::minutesBetween($startAt, $endAt);
            if ($plannedMinutes <= 0) {
                throw new BusinessRuleException('OVERTIME_INVALID', 'Jam selesai lembur harus setelah jam mulai.', 422);
            }
            $this->assertNoOvertimeConflict($employee, $startAt, $endAt);

            $orderNumber = 'REQ-SPL-'.CarbonImmutable::parse($workDate)->format('ymd').'-'.strtoupper(Str::random(5));

            $order = OvertimeOrder::query()->create([
                'order_number' => $orderNumber,
                'employee_id' => $employee->id,
                'work_date' => $workDate,
                'approval_tier' => $employee->approvalTierFor($workDate),
                'shift_session_id' => $session->id,
                'initiator_type' => 'employee',
                'created_by' => $employee->user_id,
                'title' => $data['title'],
                'start_at' => $startAt,
                'end_at' => $endAt,
                'planned_minutes' => $plannedMinutes,
                'is_day_off' => false,
                'status' => 'PENDING',
                'consent_status' => 'ACCEPTED',
                'consent_at' => now(),
                'consent_notes' => 'Diajukan langsung oleh karyawan',
            ]);

            Audit::log('overtime.requested', $order, null, $order->toArray());

            return $order;
        });
    }

    /** Karyawan memberikan persetujuan digital atas SPL dari admin. */
    public function submitConsent(OvertimeOrder $order, bool $accepted, ?string $notes, Employee $employee): OvertimeOrder
    {
        return DB::transaction(function () use ($order, $accepted, $notes, $employee) {
            $order = OvertimeOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->employee_id !== $employee->id) {
                throw new BusinessRuleException('FORBIDDEN', 'Bukan perintah lembur Anda.', 403);
            }

            if ($order->consent_status !== 'PENDING') {
                throw new BusinessRuleException('ALREADY_DECIDED', 'Persetujuan lembur sudah diberikan sebelumnya.', 422);
            }

            $before = $order->toArray();
            $order->update([
                'consent_status' => $accepted ? 'ACCEPTED' : 'DECLINED',
                'consent_at' => now(),
                'consent_notes' => $notes,
            ]);

            if ($accepted && $order->status === 'APPROVED' && $order->shift_session_id) {
                $session = ShiftSession::query()->find($order->shift_session_id);
                $record = $session ? AttendanceRecord::query()->where('shift_session_id', $session->id)->first() : null;
                if ($session && $record?->check_out_at) {
                    $this->calculateRealization($session, $record, $order);
                }
            }

            Audit::log('overtime.consented', $order, $before, $order->toArray());

            return $order;
        });
    }

    /** Admin memutuskan persetujuan / penolakan pengajuan lembur dari karyawan. */
    public function decide(OvertimeOrder $order, string $decision, ?string $notes, User $actor): OvertimeOrder
    {
        return DB::transaction(function () use ($order, $decision, $notes, $actor) {
            Employee::query()->lockForUpdate()->findOrFail($order->employee_id);
            $session = $order->shift_session_id
                ? ShiftSession::query()->lockForUpdate()->find($order->shift_session_id)
                : null;
            $record = $session
                ? AttendanceRecord::query()->where('shift_session_id', $session->id)->lockForUpdate()->first()
                : null;
            $order = OvertimeOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $actor->canApproveTier($order->approval_tier)) {
                throw new BusinessRuleException('FORBIDDEN', 'Pengajuan lembur ini membutuhkan persetujuan Super Admin.', 403);
            }

            if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Keputusan harus APPROVED atau REJECTED.', 422);
            }

            if ($order->status !== 'PENDING') {
                throw new BusinessRuleException('ALREADY_DECIDED', 'Pengajuan lembur ini sudah diputuskan.', 422);
            }

            $before = $order->toArray();
            $order->update([
                'status' => $decision,
                'decision_by' => $actor->id,
                'decision_at' => now(),
                'decision_notes' => $notes,
            ]);

            if ($decision === 'APPROVED' && $session && $record?->check_out_at) {
                $this->calculateRealization($session, $record, $order);
            }

            Audit::log('overtime.decided', $order, $before, $order->toArray());

            return $order;
        });
    }

    /** Pembatalan lembur oleh admin atau karyawan. */
    public function cancel(OvertimeOrder $order, User $actor): OvertimeOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = OvertimeOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, ['REJECTED', 'CANCELLED'], true)) {
                throw new BusinessRuleException('ALREADY_CANCELLED', 'Lembur sudah tidak aktif.', 422);
            }

            $before = $order->toArray();
            $order->update(['status' => 'CANCELLED']);

            Audit::log('overtime.cancelled', $order, $before, ['actor' => $actor->id]);

            return $order;
        });
    }

    /** Hitung realisasi lembur dari rentang yang disetujui dan waktu check-out aktual. */
    public function calculateRealization(ShiftSession $session, AttendanceRecord $record, ?OvertimeOrder $order = null): void
    {
        if (! $record->check_out_at) {
            return;
        }

        if ($order === null) {
            $order = OvertimeOrder::query()
                ->where('employee_id', $record->employee_id)
                ->where('work_date', $session->assignment->work_date)
                ->where('status', 'APPROVED')
                ->where('consent_status', 'ACCEPTED')
                ->where('shift_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                $sessionCount = ShiftSession::query()->whereNull('retired_at')
                    ->whereHas('assignment', fn ($query) => $query->where('employee_id', $record->employee_id)->where('work_date', $session->assignment->work_date)->where('status', '!=', 'CANCELLED'))
                    ->count();
                if ($sessionCount === 1) {
                    $order = OvertimeOrder::query()
                        ->where('employee_id', $record->employee_id)
                        ->where('work_date', $session->assignment->work_date)
                        ->whereNull('shift_session_id')
                        ->where('status', 'APPROVED')
                        ->where('consent_status', 'ACCEPTED')
                        ->lockForUpdate()
                        ->first();
                }
            }
        }

        if (! $order || $order->status !== 'APPROVED' || $order->consent_status !== 'ACCEPTED') {
            return;
        }

        $overtimeMinutes = 0;
        $rawMinutes = 0;

        if ($order->is_day_off) {
            if ($record->check_in_at && $record->check_out_at) {
                $rawMinutes = WorkTime::minutesBetween($record->check_in_at, $record->check_out_at);
                $overtimeMinutes = min($rawMinutes, $order->planned_minutes);
            }
        } else {
            $overtimeStart = $session->end_at;
            foreach ([$order->start_at, $record->check_in_at] as $candidate) {
                if ($candidate && $candidate->gt($overtimeStart)) {
                    $overtimeStart = $candidate;
                }
            }
            $overtimeEnd = $record->check_out_at->lt($order->end_at) ? $record->check_out_at : $order->end_at;

            if ($overtimeEnd->gt($overtimeStart)) {
                $rawMinutes = WorkTime::minutesBetween($overtimeStart, $overtimeEnd);
                $overtimeMinutes = min($rawMinutes, $order->planned_minutes);
            }
        }

        $order->update([
            'shift_session_id' => $session->id,
            'actual_minutes' => $rawMinutes,
            'verified_minutes' => $overtimeMinutes,
        ]);

        $record->update([
            'overtime_order_id' => $order->id,
            'verified_overtime_minutes' => $overtimeMinutes,
        ]);
    }

    private function assertNoOvertimeConflict(Employee $employee, CarbonImmutable $startAt, CarbonImmutable $endAt): void
    {
        if (ShiftSession::query()->whereNull('retired_at')
            ->whereHas('assignment', fn ($query) => $query->where('employee_id', $employee->id)->whereIn('status', ['DRAFT', 'PUBLISHED', 'IN_PROGRESS', 'COMPLETED']))
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists()) {
            throw new BusinessRuleException('OVERTIME_SCHEDULE_CONFLICT', 'Rentang lembur bertabrakan dengan jadwal shift karyawan.', 409);
        }

        $activeOrder = OvertimeOrder::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->where('consent_status', '!=', 'DECLINED')
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
        if ($activeOrder) {
            throw new BusinessRuleException('OVERTIME_CONFLICT', 'Rentang lembur bertabrakan dengan pengajuan atau perintah lembur aktif.', 409);
        }
    }
}
