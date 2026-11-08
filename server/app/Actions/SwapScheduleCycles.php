<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentTerm;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\ShiftAssignment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SwapScheduleCycles
{
    public function preview(array $moves, array $decisions, bool $confirmCrossUnit): array
    {
        $moves = $this->normalizeMoves($moves);
        $decisions = $this->normalizeDecisions($decisions);
        $plan = $this->evaluate($moves, $decisions, $confirmCrossUnit);
        $token = (string) Str::uuid();
        Cache::put("schedule-swap:{$token}", $this->signature($moves, $decisions, $confirmCrossUnit), now()->addMinutes(15));

        return [...$plan, 'preview_token' => $token, 'can_commit' => $plan['blockers'] === []];
    }

    public function commit(array $moves, array $decisions, bool $confirmCrossUnit, string $previewToken, string $reason, User $actor): array
    {
        $moves = $this->normalizeMoves($moves);
        $decisions = $this->normalizeDecisions($decisions);
        $signature = $this->signature($moves, $decisions, $confirmCrossUnit);
        $cached = Cache::get("schedule-swap:{$previewToken}");
        if (! $cached || ! hash_equals($cached, $signature)) {
            throw new BusinessRuleException('SWAP_PREVIEW_EXPIRED', 'Pratinjau tukar jadwal kedaluwarsa atau isinya berubah. Pratinjau ulang sebelum menyimpan.', 409);
        }

        return DB::transaction(function () use ($moves, $decisions, $confirmCrossUnit, $reason, $actor, $previewToken) {
            $employeeIds = collect($moves)->flatMap(fn ($move) => [$move['to_employee_id']])->unique()->sort()->values();
            $sourceIds = ShiftAssignment::query()->whereIn('id', collect($moves)->pluck('assignment_id'))->pluck('employee_id');
            $employeeIds = $employeeIds->merge($sourceIds)->unique()->sort()->values();
            Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
            ShiftAssignment::query()->whereIn('id', collect($moves)->pluck('assignment_id'))->orderBy('id')->lockForUpdate()->get();

            $plan = $this->evaluate($moves, $decisions, $confirmCrossUnit);
            if ($plan['blockers'] !== []) {
                throw new BusinessRuleException('SWAP_BLOCKED', 'Tukar jadwal belum dapat disimpan. Periksa konflik dan pilihan roster yang ditampilkan.', 409, ['blockers' => $plan['blockers'], 'vacancies' => $plan['vacancies']]);
            }

            $parked = [];
            $occupiedDates = ShiftAssignment::query()->whereIn('employee_id', $employeeIds)->pluck('work_date')->map(fn ($date) => (string) $date)->flip();
            $candidate = CarbonImmutable::parse('9999-12-31');
            foreach ($moves as $move) {
                do {
                    $temporaryDate = $candidate->toDateString();
                    $candidate = $candidate->subDay();
                } while (isset($occupiedDates[$temporaryDate]));
                $occupiedDates[$temporaryDate] = true;
                $assignment = ShiftAssignment::query()->findOrFail($move['assignment_id']);
                $parked[$assignment->id] = ['work_date' => CarbonImmutable::parse($assignment->work_date)->toDateString(), 'before' => $assignment->load('sessions')->toArray()];
                DB::table('shift_assignments')->where('id', $assignment->id)->update(['work_date' => $temporaryDate]);
            }

            $swapId = (string) Str::uuid();
            $updated = [];
            foreach ($moves as $move) {
                $assignment = ShiftAssignment::query()->with('sessions')->lockForUpdate()->findOrFail($move['assignment_id']);
                $target = Employee::query()->with('workLocation')->findOrFail($move['to_employee_id']);
                $before = $parked[$assignment->id]['before'];
                $workDate = $parked[$assignment->id]['work_date'];
                $oldSessions = $assignment->sessions->values();
                $revision = $assignment->revision + 1;
                $assignment->sessions()->update(['retired_at' => WorkTime::now()]);
                $assignment->update(['employee_id' => $target->id, 'work_date' => $workDate, 'revision' => $revision]);
                $policy = AttendancePolicy::resolveFor($target, $workDate);
                foreach ($oldSessions as $old) {
                    $location = $old->policy_snapshot['location'] ?? null;
                    $hasRadius = $target->workLocation?->mode === 'radius'
                        && $target->workLocation->latitude !== null
                        && $target->workLocation->longitude !== null
                        && $target->workLocation->radius_meters;
                    $useLocation = (bool) (($policy['use_location_check'] ?? false) || $hasRadius || $location);
                    if (! $location && $useLocation && $target->workLocation?->mode === 'radius') {
                        $location = [
                            'mode' => 'radius', 'latitude' => (float) $target->workLocation->latitude,
                            'longitude' => (float) $target->workLocation->longitude,
                            'radius_meters' => $target->workLocation->radius_meters,
                            'max_accuracy_meters' => $target->workLocation->max_accuracy_meters,
                        ];
                    }
                    $snapshot = [...$policy, 'use_location_check' => $useLocation, 'location' => $location];
                    $assignment->sessions()->create([
                        'seq' => $old->seq, 'schedule_revision' => $revision,
                        'start_at' => $old->start_at, 'end_at' => $old->end_at,
                        'unpaid_break_minutes' => $old->unpaid_break_minutes,
                        'rules_version' => 3,
                        'policy_snapshot' => $snapshot,
                    ]);
                }
                $assignment->refresh()->load('sessions');
                Audit::log('schedule.cycle_swapped', $assignment, $before, [
                    'swap_id' => $swapId, 'reason' => $reason,
                    'to_employee_id' => $target->id, 'assignment' => $assignment->toArray(),
                ]);
                $updated[] = $assignment->toApi();
            }

            foreach ($decisions as $decision) {
                $employee = Employee::query()->findOrFail($decision['employee_id']);
                if ($decision['action'] === 'roster_off') {
                    Audit::log('schedule.roster_off_recorded', $employee, null, [
                        'swap_id' => $swapId, 'work_date' => $decision['work_date'], 'reason' => $decision['reason'],
                    ]);

                    continue;
                }
                $leave = LeaveRequest::query()->create([
                    'employee_id' => $employee->id, 'work_date' => $decision['work_date'],
                    'type' => $decision['action'], 'status' => 'APPROVED',
                    'reason' => $decision['reason'], 'decision_reason' => $decision['reason'],
                    'requested_by' => $actor->id, 'direct_by' => $actor->id, 'decided_by' => $actor->id,
                    'approved_at' => WorkTime::now(),
                ]);
                Audit::log('leave.direct_recorded_for_schedule_swap', $leave, null, [
                    ...$leave->attributesToArray(), 'swap_id' => $swapId, 'reason' => $decision['reason'],
                ]);
            }

            Audit::log('schedule.swap_chain_completed', $actor, null, [
                'swap_id' => $swapId, 'reason' => $reason, 'moves' => $moves,
                'vacancy_decisions' => $decisions, 'assignments' => $updated,
            ]);
            Cache::forget("schedule-swap:{$previewToken}");

            return ['swap_id' => $swapId, 'data' => $updated];
        });
    }

    private function evaluate(array $moves, array $decisions, bool $confirmCrossUnit): array
    {
        $blockers = [];
        $ids = collect($moves)->pluck('assignment_id');
        $assignments = ShiftAssignment::query()->with('employee', 'sessions.attendanceRecord')
            ->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
        if ($assignments->count() !== $ids->unique()->count()) {
            $blockers[] = ['code' => 'SCHEDULE_NOT_FOUND', 'message' => 'Ada jadwal yang sudah tidak tersedia.'];
        }

        $targetSessions = [];
        $targetAssignments = [];
        $targetEmployees = [];
        $sources = [];
        $crossUnit = false;
        $summary = [];
        $decisionKeys = [];
        foreach ($decisions as $decision) {
            $decisionKey = $decision['employee_id'].':'.$decision['work_date'];
            if (isset($decisionKeys[$decisionKey])) {
                $blockers[] = ['code' => 'ROSTER_DECISION_DUPLICATE', 'employee_id' => $decision['employee_id'], 'work_date' => $decision['work_date'], 'message' => 'Pilihan roster ganda untuk karyawan dan tanggal yang sama.'];
            }
            $decisionKeys[$decisionKey] = true;
        }
        foreach ($moves as $move) {
            $assignment = $assignments->get($move['assignment_id']);
            if (! $assignment) {
                continue;
            }
            if ($assignment->revision !== $move['expected_revision']) {
                $blockers[] = ['code' => 'SCHEDULE_REVISION_CONFLICT', 'assignment_id' => $assignment->id, 'message' => 'Jadwal berubah sejak pratinjau dibuka. Muat ulang jadwal.'];
            }
            $employee = Employee::query()->with('workLocation')->find($move['to_employee_id']);
            if (! $employee || $employee->status !== 'active' || $assignment->work_date < $employee->started_on || ($employee->ended_on && $assignment->work_date > $employee->ended_on)) {
                $blockers[] = ['code' => 'EMPLOYEE_INACTIVE', 'assignment_id' => $assignment->id, 'message' => 'Karyawan pengganti tidak aktif pada tanggal shift.'];

                continue;
            }
            if ((int) $assignment->employee_id === (int) $employee->id) {
                $blockers[] = ['code' => 'SWAP_NO_CHANGE', 'assignment_id' => $assignment->id, 'message' => 'Pilih karyawan lain agar jadwal benar-benar berpindah.'];
            }
            $this->assertContract($employee, $assignment->work_date, $blockers, $assignment->id);
            $key = $employee->id.':'.$assignment->work_date;
            $targetAssignments[$key][] = $assignment;
            $targetEmployees[$key] = $employee;
            foreach ($assignment->sessions as $session) {
                foreach ($targetSessions[$employee->id] ?? [] as $prior) {
                    if ($session->start_at->lt($prior['end']) && $prior['start']->lt($session->end_at)) {
                        $blockers[] = ['code' => 'SWAP_CHAIN_OVERLAP', 'assignment_id' => $assignment->id, 'message' => 'Dua siklus dalam rantai tukar saling bertabrakan.'];
                        break 2;
                    }
                }
                $targetSessions[$employee->id][] = ['start' => $session->start_at, 'end' => $session->end_at];
            }
            $sources[$assignment->employee_id.':'.$assignment->work_date] = ['employee_id' => $assignment->employee_id, 'work_date' => $assignment->work_date];
            if ($assignment->employee?->department_id !== $employee->department_id) {
                $crossUnit = true;
            }

            try {
                AttendancePeriod::assertOpen($assignment->work_date);
            } catch (BusinessRuleException) {
                $blockers[] = ['code' => 'PERIOD_LOCKED', 'assignment_id' => $assignment->id, 'message' => "Periode {$assignment->work_date} sudah difinalisasi."];
            }
            $first = $assignment->sessions->first();
            if (! in_array($assignment->status, ['DRAFT', 'PUBLISHED'], true) || ! $first || $first->start_at->lte(WorkTime::now())) {
                $blockers[] = ['code' => 'SCHEDULE_LOCKED', 'assignment_id' => $assignment->id, 'message' => 'Hanya siklus yang belum dimulai yang dapat ditukar.'];
            }
            $sessionIds = $assignment->sessions->pluck('id');
            if (AttendanceRecord::query()->whereIn('shift_session_id', $sessionIds)->exists()
                || DB::table('attendance_events')->whereIn('shift_session_id', $sessionIds)->exists()) {
                $blockers[] = ['code' => 'ATTENDANCE_EXISTS', 'assignment_id' => $assignment->id, 'message' => 'Siklus sudah memiliki catatan absensi.'];
            }
            $leave = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where('work_date', '<=', $assignment->work_date)
                ->whereRaw('COALESCE(end_date, work_date) >= ?', [$assignment->work_date])
                ->exists();
            if ($leave) {
                $blockers[] = ['code' => 'LEAVE_CONFLICT', 'assignment_id' => $assignment->id, 'message' => 'Karyawan pengganti memiliki pengajuan cuti aktif pada tanggal ini.'];
            }
            $this->assertTargetConflicts($employee, $assignment, $ids->all(), $blockers);
            $summary[] = [
                'assignment_id' => $assignment->id, 'work_date' => $assignment->work_date,
                'shift' => $assignment->template_name, 'from_employee' => $assignment->employee?->name,
                'to_employee' => $employee->name, 'cross_unit' => $assignment->employee?->department_id !== $employee->department_id,
            ];
        }

        foreach ($targetAssignments as $key => $group) {
            [, $workDate] = explode(':', $key, 2);
            $this->assertTargetDailyLimits($targetEmployees[$key], $workDate, $group, $ids->all(), $blockers);
        }

        if ($crossUnit && ! $confirmCrossUnit) {
            $blockers[] = ['code' => 'CROSS_UNIT_CONFIRMATION_REQUIRED', 'message' => 'Konfirmasi perpindahan lintas departemen sebelum menyimpan.'];
        }

        $vacancies = [];
        foreach ($sources as $source) {
            $reassignedBack = collect($moves)->contains(function ($move) use ($assignments, $source) {
                $assignment = $assignments->get($move['assignment_id']);

                return $assignment && $assignment->work_date === $source['work_date'] && (int) $move['to_employee_id'] === (int) $source['employee_id'];
            });
            if ($reassignedBack) {
                continue;
            }
            $hasRemainingAssignment = ShiftAssignment::query()
                ->where('employee_id', $source['employee_id'])
                ->where('work_date', $source['work_date'])
                ->whereNotIn('id', $ids)
                ->where('status', '!=', 'CANCELLED')
                ->exists();
            if ($hasRemainingAssignment) {
                continue;
            }
            $existingLeave = LeaveRequest::query()
                ->where('employee_id', $source['employee_id'])
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where('work_date', '<=', $source['work_date'])
                ->whereRaw('COALESCE(end_date, work_date) >= ?', [$source['work_date']])
                ->first();
            if ($existingLeave?->status === 'APPROVED') {
                continue;
            }
            $row = [...$source, 'name' => Employee::query()->whereKey($source['employee_id'])->value('name'), 'existing_leave' => $existingLeave?->status];
            $vacancies[] = $row;
            $decision = collect($decisions)->first(fn ($d) => (int) $d['employee_id'] === (int) $source['employee_id'] && $d['work_date'] === $source['work_date']);
            if ($existingLeave?->status === 'PENDING') {
                $blockers[] = ['code' => 'LEAVE_PENDING', ...$row, 'message' => 'Pengajuan cuti karyawan yang kehilangan shift masih menunggu keputusan HR.'];

                continue;
            }
            if (! $decision) {
                $blockers[] = ['code' => 'ROSTER_DECISION_REQUIRED', ...$row, 'message' => 'Pilih roster off atau jenis cuti untuk karyawan yang tidak mendapat shift pengganti.'];

                continue;
            }
            if (($decision['action'] !== 'roster_off' && ! LeaveType::query()->where('code', $decision['action'])->exists()) || trim($decision['reason']) === '') {
                $blockers[] = ['code' => 'ROSTER_DECISION_INVALID', ...$row, 'message' => 'Pilihan roster dan alasannya wajib diisi.'];

                continue;
            }
            if ($decision['action'] !== 'roster_off') {
                try {
                    AttendancePeriod::assertOpen($source['work_date']);
                    $year = (int) substr($source['work_date'], 0, 4);
                    if (! LeaveYear::query()->where('year', $year)->value('opening_confirmed_at')) {
                        $blockers[] = ['code' => 'LEAVE_BALANCE_NOT_OPEN', ...$row, 'message' => 'Saldo tahun terkait belum dikonfirmasi HR.'];
                    } else {
                        $balance = app(CalculateLeaveBalance::class)->for(Employee::query()->findOrFail($source['employee_id']), $year);
                        $typeBalance = collect($balance['types'])->firstWhere('code', $decision['action']);
                        if (($typeBalance['available'] ?? 0) < 1) {
                            $blockers[] = ['code' => 'LEAVE_BALANCE_INSUFFICIENT', ...$row, 'message' => 'Kuota tidak mencukupi untuk pilihan cuti ini.'];
                        }
                    }
                } catch (BusinessRuleException) {
                    $blockers[] = ['code' => 'PERIOD_LOCKED', ...$row, 'message' => 'Periode roster/cuti sudah difinalisasi.'];
                }
            }
        }

        foreach ($decisions as $decision) {
            if (! collect($vacancies)->contains(fn ($v) => (int) $v['employee_id'] === (int) $decision['employee_id'] && $v['work_date'] === $decision['work_date'])) {
                $blockers[] = ['code' => 'ROSTER_DECISION_NOT_REQUIRED', 'employee_id' => $decision['employee_id'], 'work_date' => $decision['work_date'], 'message' => 'Pilihan roster tidak cocok dengan karyawan yang kehilangan shift.'];
            }
        }

        return ['moves' => $summary, 'cross_unit_confirmation_required' => $crossUnit, 'vacancies' => $vacancies, 'blockers' => $blockers];
    }

    private function assertTargetConflicts(Employee $employee, ShiftAssignment $assignment, array $movingIds, array &$blockers): void
    {
        $date = CarbonImmutable::parse($assignment->work_date);
        $others = ShiftAssignment::query()->with('sessions')->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$date->subDay()->toDateString(), $date->addDay()->toDateString()])
            ->whereNotIn('id', $movingIds)->where('status', '!=', 'CANCELLED')->get();
        foreach ($others as $other) {
            foreach ($other->sessions as $existing) {
                foreach ($assignment->sessions as $new) {
                    if ($new->start_at->lt($existing->end_at) && $existing->start_at->lt($new->end_at)) {
                        $blockers[] = ['code' => 'SCHEDULE_OVERLAP', 'assignment_id' => $assignment->id, 'conflicting_assignment_id' => $other->id,
                            'message' => "Bentrok dengan {$other->template_name} tanggal {$other->work_date}."];
                        break 2;
                    }
                }
            }
        }
    }

    /** @param array<ShiftAssignment> $incoming */
    private function assertTargetDailyLimits(Employee $employee, string $workDate, array $incoming, array $movingIds, array &$blockers): void
    {
        $existing = ShiftAssignment::query()->with('sessions')
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->whereNotIn('id', $movingIds)
            ->where('status', '!=', 'CANCELLED')
            ->get();
        $combined = $existing->concat($incoming);
        if ($combined->count() > 2 || ($combined->count() === 2 && $combined->where('template_type', 'special')->count() > 1)) {
            $blockers[] = ['code' => 'DAILY_SHIFT_LIMIT', 'employee_id' => $employee->id, 'work_date' => $workDate,
                'message' => 'Tanggal tujuan dapat memiliki maksimal dua shift: dua reguler, atau satu reguler dan satu khusus.'];

            return;
        }

        $sessions = $combined->flatMap(fn (ShiftAssignment $assignment) => $assignment->sessions);
        $scheduledMinutes = $sessions->sum(fn (ShiftSession $session) => $session->scheduledMinutes());
        if ($scheduledMinutes > 960) {
            $blockers[] = ['code' => 'DAILY_WORK_LIMIT', 'employee_id' => $employee->id, 'work_date' => $workDate,
                'message' => 'Total kerja terjadwal pada tanggal tujuan melebihi 16 jam.'];
        }

        if ($sessions->isNotEmpty()) {
            $ordered = $sessions->sortBy(fn (ShiftSession $session) => $session->start_at->getTimestamp());
            $first = $ordered->first();
            $last = $sessions->sortBy(fn (ShiftSession $session) => $session->end_at->getTimestamp())->last();
            if ($last->end_at->getTimestamp() - $first->start_at->getTimestamp() > 24 * 60 * 60) {
                $blockers[] = ['code' => 'DAILY_SPAN_LIMIT', 'employee_id' => $employee->id, 'work_date' => $workDate,
                    'message' => 'Rentang shift pada tanggal tujuan melebihi 24 jam.'];
            }
        }

        foreach ($incoming as $assignment) {
            foreach ($assignment->sessions as $session) {
                $activeOvertime = DB::table('overtime_orders')
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', ['PENDING', 'APPROVED'])
                    ->where('consent_status', '!=', 'DECLINED')
                    ->where('start_at', '<', $session->end_at)
                    ->where('end_at', '>', $session->start_at)
                    ->exists();
                if ($activeOvertime) {
                    $blockers[] = ['code' => 'OVERTIME_CONFLICT', 'assignment_id' => $assignment->id,
                        'message' => 'Shift bertabrakan dengan lembur aktif karyawan tujuan.'];
                }
            }
        }
    }

    private function assertContract(Employee $employee, string $date, array &$blockers, int $assignmentId): void
    {
        $term = EmploymentTerm::query()->where('employee_id', $employee->id)->where('starts_on', '<=', $date)->orderByDesc('starts_on')->first();
        if ($term && ($date < $term->starts_on->format('Y-m-d') || ($term->type === 'contract' && $term->ends_on && $date > $term->ends_on->format('Y-m-d')))) {
            $blockers[] = ['code' => 'EMPLOYEE_CONTRACT_EXPIRED', 'assignment_id' => $assignmentId, 'message' => 'Masa kontrak tidak mencakup tanggal jadwal.'];
        }
    }

    private function normalizeMoves(array $moves): array
    {
        $normalized = array_map(fn ($move) => [
            'assignment_id' => (int) $move['assignment_id'],
            'expected_revision' => (int) $move['expected_revision'],
            'to_employee_id' => (int) $move['to_employee_id'],
        ], $moves);
        usort($normalized, fn ($a, $b) => $a['assignment_id'] <=> $b['assignment_id']);

        return $normalized;
    }

    private function normalizeDecisions(array $decisions): array
    {
        $normalized = array_map(fn ($decision) => [
            'employee_id' => (int) $decision['employee_id'], 'work_date' => $decision['work_date'],
            'action' => $decision['action'], 'reason' => trim($decision['reason'] ?? ''),
        ], $decisions);
        usort($normalized, fn ($a, $b) => [$a['employee_id'], $a['work_date']] <=> [$b['employee_id'], $b['work_date']]);

        return $normalized;
    }

    private function signature(array $moves, array $decisions, bool $confirmCrossUnit): string
    {
        return hash('sha256', json_encode([$moves, $decisions, $confirmCrossUnit], JSON_UNESCAPED_SLASHES));
    }
}
