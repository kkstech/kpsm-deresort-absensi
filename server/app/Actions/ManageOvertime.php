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

            $employees = Employee::query()->whereIn('id', $data['employee_ids'])->get();
            if ($employees->isEmpty()) {
                throw new BusinessRuleException('VALIDATION_ERROR', 'Pilih minimal satu karyawan aktif.', 422);
            }

            $startOffset = (int) ($data['start_day_offset'] ?? 0);
            $startAt = WorkTime::at($workDate, $data['start_time'], $startOffset);
            $endOffset = isset($data['end_day_offset'])
                ? (int) $data['end_day_offset']
                : $startOffset + ((! empty($data['is_cross_day']) || $data['end_time'] < $data['start_time']) ? 1 : 0);
            $endAt = WorkTime::at($workDate, $data['end_time'], $endOffset);
            $plannedMinutes = max(1, WorkTime::minutesBetween($startAt, $endAt));

            $created = [];
            foreach ($employees as $employee) {
                // Cari jadwal shift aktif pada tanggal tersebut
                $assignment = ShiftAssignment::query()
                    ->with('sessions')
                    ->where('employee_id', $employee->id)
                    ->where('work_date', $workDate)
                    ->where('status', '!=', 'CANCELLED')
                    ->first();

                $isDayOff = false;
                $session = null;

                if ($assignment && $assignment->sessions->isNotEmpty()) {
                    // Terhubung ke sesi shift terakhir pada tanggal tersebut
                    $session = $assignment->sessions->sortBy('seq')->last();
                } else {
                    // Lembur di hari libur: buat jadwal khusus agar bisa check-in/out
                    $isDayOff = true;
                    $assignment = ShiftAssignment::query()->create([
                        'employee_id' => $employee->id,
                        'work_date' => $workDate,
                        'template_name' => 'Lembur Hari Libur',
                        'template_type' => 'special',
                        'rules_version' => 2,
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
            $workDate = $data['work_date'];
            AttendancePeriod::assertOpen($workDate);

            $assignment = ShiftAssignment::query()
                ->with('sessions.attendanceRecord')
                ->where('employee_id', $employee->id)
                ->where('work_date', $workDate)
                ->where('status', '!=', 'CANCELLED')
                ->first();
            $sessions = $assignment?->sessions ?? collect();
            $shiftFulfilled = $sessions->isNotEmpty() && $sessions->every(function (ShiftSession $session): bool {
                $record = $session->attendanceRecord;

                return $record !== null
                    && $record->check_in_at !== null
                    && $record->check_out_at !== null
                    && $record->provisional_out_at === null
                    && ! $record->needs_review;
            });
            if (! $shiftFulfilled) {
                throw new BusinessRuleException(
                    'SHIFT_NOT_COMPLETED',
                    'Pengajuan lembur dapat dikirim setelah seluruh sesi shift terjadwal memiliki check-in dan check-out final.',
                    422,
                );
            }

            $startAt = WorkTime::at($workDate, $data['start_time']);
            $endOffset = (! empty($data['is_cross_day']) || $data['end_time'] < $data['start_time']) ? 1 : 0;
            $endAt = WorkTime::at($workDate, $data['end_time'], $endOffset);
            $plannedMinutes = max(1, WorkTime::minutesBetween($startAt, $endAt));

            $session = $assignment?->sessions->sortBy('seq')->last();

            $orderNumber = 'REQ-SPL-'.CarbonImmutable::parse($workDate)->format('ymd').'-'.strtoupper(Str::random(5));

            $order = OvertimeOrder::query()->create([
                'order_number' => $orderNumber,
                'employee_id' => $employee->id,
                'work_date' => $workDate,
                'approval_tier' => $employee->approvalTierFor($workDate),
                'shift_session_id' => $session?->id,
                'initiator_type' => 'employee',
                'created_by' => $employee->user_id,
                'title' => $data['title'],
                'start_at' => $startAt,
                'end_at' => $endAt,
                'planned_minutes' => $plannedMinutes,
                'is_day_off' => $session === null,
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

            Audit::log('overtime.consented', $order, $before, $order->toArray());

            return $order;
        });
    }

    /** Admin memutuskan persetujuan / penolakan pengajuan lembur dari karyawan. */
    public function decide(OvertimeOrder $order, string $decision, ?string $notes, User $actor): OvertimeOrder
    {
        return DB::transaction(function () use ($order, $decision, $notes, $actor) {
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

    /** Hitung realisasi jam lembur saat check-out dan simpan ke attendance_records & overtime_orders. */
    public function calculateRealization(ShiftSession $session, AttendanceRecord $record): void
    {
        if (! $record->check_out_at) {
            return;
        }

        $order = OvertimeOrder::query()
            ->where('employee_id', $record->employee_id)
            ->where('work_date', $session->assignment->work_date)
            ->where('status', 'APPROVED')
            ->where('consent_status', 'ACCEPTED')
            ->where(function ($q) use ($session) {
                $q->where('shift_session_id', $session->id)->orWhereNull('shift_session_id');
            })
            ->first();

        if (! $order) {
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
            // Lembur di hari kerja: dihitung setelah jam selesai shift reguler
            if ($record->check_out_at->gt($session->end_at)) {
                $rawMinutes = WorkTime::minutesBetween($session->end_at, $record->check_out_at);
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
}
