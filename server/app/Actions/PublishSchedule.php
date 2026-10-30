<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\AttendancePolicy;
use App\Models\Employee;
use App\Models\EmploymentTerm;
use App\Models\ShiftAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PublishSchedule
{
    /** Buat atau ganti jadwal satu karyawan di satu tanggal kerja. */
    public function upsert(Employee $employee, string $workDate, ShiftTemplate $template, User $actor): ShiftAssignment
    {
        return DB::transaction(function () use ($employee, $workDate, $template, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            AttendancePeriod::assertOpen($workDate);
            $this->assertEmployed($employee, $workDate);

            $sessions = $this->buildSessions($employee, $workDate, $template);
            if (CarbonImmutable::parse($sessions[0]['start_at'])->lte(WorkTime::now())) {
                throw new BusinessRuleException('SESSION_STARTED', 'Jadwal yang sudah mulai tidak bisa dibuat atau diubah. Pakai koreksi.');
            }

            $assignment = ShiftAssignment::query()->where('employee_id', $employee->id)->where('work_date', $workDate)->first();
            if ($assignment && $assignment->status !== 'CANCELLED') {
                $this->assertEditable($assignment);
            }
            $this->assertNoOverlap($employee, $workDate, $sessions, $assignment?->id);

            $before = $assignment?->load('sessions')->toArray();
            $assignment ??= new ShiftAssignment(['employee_id' => $employee->id, 'work_date' => $workDate, 'created_by' => $actor->id, 'revision' => 0]);
            $wasPublished = $assignment->exists && $assignment->status === 'PUBLISHED';
            $this->archiveCurrentSessions($assignment);
            $assignment->fill([
                'shift_template_id' => $template->id,
                'template_name' => $template->name,
                'template_type' => $template->type,
                'rules_version' => 2,
                'status' => $wasPublished ? 'PUBLISHED' : 'DRAFT',
                'revision' => $assignment->revision + 1,
            ])->save();

            $assignment->sessions()->createMany(array_map(fn (array $session) => [...$session, 'schedule_revision' => $assignment->revision], $sessions));
            Audit::log($before ? 'schedule.updated' : 'schedule.created', $assignment, $before, $assignment->load('sessions')->toArray());

            return $assignment;
        });
    }

    public function upsertCustom(Employee $employee, string $workDate, array $data, User $actor, ?int $expectedRevision = null, ?string $reason = null): ShiftAssignment
    {
        return DB::transaction(function () use ($employee, $workDate, $data, $actor, $expectedRevision, $reason) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            AttendancePeriod::assertOpen($workDate);
            $this->assertEmployed($employee, $workDate);

            $assignment = ShiftAssignment::query()->with('sessions.attendanceRecord')->where('employee_id', $employee->id)->where('work_date', $workDate)->first();
            if ($assignment && $expectedRevision !== null && $assignment->revision !== $expectedRevision) {
                throw new BusinessRuleException('SCHEDULE_REVISION_CONFLICT', 'Jadwal berubah sejak dibuka. Muat ulang sebelum menyimpan.', 409);
            }
            if ($assignment && ! in_array($assignment->status, ['DRAFT', 'PUBLISHED', 'IN_PROGRESS', 'CANCELLED'], true)) {
                throw new BusinessRuleException('SCHEDULE_LOCKED', 'Jadwal yang selesai tidak bisa diubah.');
            }

            $sessions = $this->customSessions($employee, $workDate, $data['sessions']);
            $now = WorkTime::now();
            $current = $assignment?->sessions ?? collect();
            $knownIds = $current->pluck('id')->all();
            foreach ($sessions as $session) {
                if ($session['id'] !== null && ! in_array($session['id'], $knownIds, true)) {
                    throw new BusinessRuleException('SESSION_NOT_OWNED', 'Sesi tersebut bukan bagian dari jadwal ini.', 422);
                }
            }
            $running = $assignment && $assignment->status !== 'CANCELLED'
                && ($assignment->status === 'IN_PROGRESS' || $current->contains(fn ($session) => $session->start_at->lte($now)));
            $kept = [];
            $create = $sessions;
            $updates = [];

            if ($running) {
                [$kept, $create, $updates] = $this->reconcileRunningSessions($current, $sessions, $now);
            } elseif ($sessions[0]['start_at']->lte($now)) {
                throw new BusinessRuleException('SESSION_STARTED', 'Jadwal baru harus dimulai di masa depan.');
            }

            $next = collect([...$kept, ...$create])->sortBy('seq')->values()->all();
            $this->assertCycleLimits($next);
            $this->assertNoOverlap($employee, $workDate, $next, $assignment?->id);

            $revision = ($assignment?->revision ?? 0) + 1;
            $assignment ??= new ShiftAssignment(['employee_id' => $employee->id, 'work_date' => $workDate, 'created_by' => $actor->id]);
            $before = $assignment->exists ? $assignment->load('sessions')->toArray() : null;
            if ($running) {
                $keptIds = collect($kept)->pluck('id')->all();
                $retireIds = $current->whereNotIn('id', $keptIds)->pluck('id');
                if ($retireIds->isNotEmpty()) {
                    $assignment->sessions()->whereIn('id', $retireIds)->update(['retired_at' => $now]);
                }
                foreach ($updates as [$session, $endAt]) {
                    $session->update(['end_at' => $endAt]);
                }
            } else {
                $this->archiveCurrentSessions($assignment);
            }
            $assignment->fill([
                'shift_template_id' => null,
                'template_name' => $data['name'] ?? 'Shift khusus',
                'template_type' => count($next) > 1 ? 'special' : 'regular',
                'rules_version' => 2,
                'status' => $assignment->status === 'CANCELLED' ? 'DRAFT' : ($assignment->status ?: 'DRAFT'),
                'revision' => $revision,
            ])->save();
            if ($create) {
                $assignment->sessions()->createMany(array_map(fn (array $session) => [...$session, 'schedule_revision' => $revision], $create));
            }
            Audit::log('schedule.custom_updated', $assignment, $before, [
                'reason' => $reason,
                'assignment' => $assignment->load('sessions')->toArray(),
            ]);

            return $assignment->load('sessions');
        });
    }

    public function cancel(ShiftAssignment $assignment): ShiftAssignment
    {
        return DB::transaction(function () use ($assignment) {
            Employee::query()->lockForUpdate()->findOrFail($assignment->employee_id);
            $assignment = ShiftAssignment::query()->with('sessions')->findOrFail($assignment->id);
            AttendancePeriod::assertOpen($assignment->work_date);
            $this->assertEditable($assignment);

            $before = $assignment->attributesToArray();
            $assignment->update(['status' => 'CANCELLED', 'revision' => $assignment->revision + 1]);
            Audit::log('schedule.cancelled', $assignment, $before);

            return $assignment;
        });
    }

    /** Draft dalam rentang menjadi PUBLISHED. Mengembalikan jumlah jadwal yang dipublikasi. */
    public function publish(string $from, string $to, array $employeeIds): int
    {
        return DB::transaction(function () use ($from, $to, $employeeIds) {
            $drafts = ShiftAssignment::query()
                ->whereBetween('work_date', [$from, $to])
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'DRAFT')
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $assignment) {
                AttendancePeriod::assertOpen($assignment->work_date);
                $assignment->update(['status' => 'PUBLISHED', 'published_at' => now()]);
                Audit::log('schedule.published', $assignment, ['status' => 'DRAFT']);
            }

            return $drafts->count();
        });
    }

    /** Salin jadwal satu minggu ke minggu tujuan. Tanggal yang sudah terisi atau bentrok dilewati. */
    public function copyWeek(string $fromMonday, string $toMonday, array $employeeIds, User $actor): array
    {
        $shift = CarbonImmutable::parse($fromMonday)->diffInDays(CarbonImmutable::parse($toMonday));
        $source = ShiftAssignment::query()
            ->with('template.sessions', 'employee')
            ->whereBetween('work_date', [$fromMonday, CarbonImmutable::parse($fromMonday)->addDays(6)->toDateString()])
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', 'CANCELLED')
            ->whereNotNull('shift_template_id')
            ->get();

        $created = 0;
        $skipped = [];
        foreach ($source as $assignment) {
            $target = CarbonImmutable::parse($assignment->work_date)->addDays($shift)->toDateString();
            $taken = ShiftAssignment::query()->where('employee_id', $assignment->employee_id)->where('work_date', $target)->where('status', '!=', 'CANCELLED')->exists();
            if ($taken) {
                $skipped[] = ['employee' => $assignment->employee->name, 'work_date' => $target, 'reason' => 'Sudah ada jadwal.'];

                continue;
            }
            try {
                $this->upsert($assignment->employee, $target, $assignment->template, $actor);
                $created++;
            } catch (BusinessRuleException $e) {
                $skipped[] = ['employee' => $assignment->employee->name, 'work_date' => $target, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Penugasan jadwal massal ke banyak karyawan dan rentang tanggal dengan filter hari kerja.
     * Tanggal yang sudah terisi dilewati kecuali $overwriteDrafts = true.
     */
    public function bulkAssign(
        array $employeeIds,
        string $from,
        string $to,
        array $daysOfWeek,
        ShiftTemplate $template,
        bool $overwriteDrafts,
        User $actor
    ): array {
        $startDate = CarbonImmutable::parse($from);
        $endDate = CarbonImmutable::parse($to);
        $employees = Employee::query()->whereIn('id', $employeeIds)->get()->keyBy('id');

        $created = 0;
        $skipped = [];

        for ($cur = $startDate; $cur->lte($endDate); $cur = $cur->addDay()) {
            $dateStr = $cur->toDateString();
            $dayOfWeek = $cur->dayOfWeek; // 0 = Minggu, 1 = Senin, dst.

            if (! in_array($dayOfWeek, $daysOfWeek, true)) {
                continue;
            }

            foreach ($employeeIds as $empId) {
                $employee = $employees->get($empId);
                if (! $employee) {
                    continue;
                }

                $existing = ShiftAssignment::query()
                    ->where('employee_id', $empId)
                    ->where('work_date', $dateStr)
                    ->where('status', '!=', 'CANCELLED')
                    ->first();

                if ($existing) {
                    if (! $overwriteDrafts || $existing->status !== 'DRAFT') {
                        $skipped[] = [
                            'employee' => $employee->name,
                            'work_date' => $dateStr,
                            'reason' => $existing->status === 'DRAFT' ? 'Sudah ada jadwal draft.' : 'Jadwal sudah terbit / berjalan.',
                        ];

                        continue;
                    }
                }

                try {
                    $this->upsert($employee, $dateStr, $template, $actor);
                    $created++;
                } catch (BusinessRuleException $e) {
                    $skipped[] = [
                        'employee' => $employee->name,
                        'work_date' => $dateStr,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Salin jadwal satu bulan ke bulan tujuan (berdasarkan tanggal 1..N). Tanggal yang sudah terisi atau bentrok dilewati. */
    public function copyMonth(string $fromMonthStart, string $toMonthStart, array $employeeIds, User $actor): array
    {
        $fromStart = CarbonImmutable::parse($fromMonthStart)->startOfMonth();
        $fromEnd = $fromStart->endOfMonth();
        $toStart = CarbonImmutable::parse($toMonthStart)->startOfMonth();
        $toEnd = $toStart->endOfMonth();

        $source = ShiftAssignment::query()
            ->with(['template.sessions', 'sessions', 'employee'])
            ->whereBetween('work_date', [$fromStart->toDateString(), $fromEnd->toDateString()])
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('work_date')
            ->get();

        $created = 0;
        $skipped = [];
        foreach ($source as $assignment) {
            $sourceDate = CarbonImmutable::parse($assignment->work_date);
            $day = $sourceDate->day;

            if ($day > $toEnd->day) {
                $skipped[] = [
                    'employee' => $assignment->employee->name,
                    'work_date' => $assignment->work_date,
                    'reason' => "Tanggal {$day} tidak ada di bulan tujuan ({$toStart->format('Y-m')}).",
                ];

                continue;
            }

            $target = $toStart->setDay($day)->toDateString();
            $taken = ShiftAssignment::query()
                ->where('employee_id', $assignment->employee_id)
                ->where('work_date', $target)
                ->where('status', '!=', 'CANCELLED')
                ->exists();

            if ($taken) {
                $skipped[] = [
                    'employee' => $assignment->employee->name,
                    'work_date' => $target,
                    'reason' => 'Sudah ada jadwal.',
                ];

                continue;
            }

            try {
                if ($assignment->shift_template_id && $assignment->template) {
                    $this->upsert($assignment->employee, $target, $assignment->template, $actor);
                    $created++;
                } elseif ($assignment->sessions->isNotEmpty()) {
                    $sourceWorkDate = CarbonImmutable::parse($assignment->work_date);
                    $sessionsData = $assignment->sessions->map(function ($s) use ($sourceWorkDate, $target) {
                        $startOffset = $sourceWorkDate->diffInDays(WorkTime::local($s->start_at)->startOfDay(), false);
                        $endOffset = $sourceWorkDate->diffInDays(WorkTime::local($s->end_at)->startOfDay(), false);

                        return [
                            'seq' => $s->seq,
                            'start_date' => CarbonImmutable::parse($target)->addDays($startOffset)->toDateString(),
                            'start_time' => WorkTime::local($s->start_at)->format('H:i'),
                            'end_date' => CarbonImmutable::parse($target)->addDays($endOffset)->toDateString(),
                            'end_time' => WorkTime::local($s->end_at)->format('H:i'),
                            'unpaid_break_minutes' => $s->unpaid_break_minutes,
                        ];
                    })->all();

                    $this->upsertCustom($assignment->employee, $target, [
                        'name' => $assignment->template_name,
                        'sessions' => $sessionsData,
                    ], $actor);
                    $created++;
                }
            } catch (BusinessRuleException $e) {
                $skipped[] = [
                    'employee' => $assignment->employee->name,
                    'work_date' => $target,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function buildSessions(Employee $employee, string $workDate, ShiftTemplate $template): array
    {
        $policy = AttendancePolicy::snapshotFor($employee, $workDate);

        $sessions = $template->sessions->map(fn ($s) => [
            'seq' => $s->seq,
            'start_at' => WorkTime::at($workDate, substr($s->local_start, 0, 5), $s->start_day_offset ?? 0),
            'end_at' => WorkTime::at($workDate, substr($s->local_end, 0, 5), $s->day_offset),
            'unpaid_break_minutes' => $s->unpaid_break_minutes,
            'policy_snapshot' => $policy,
        ])->all();

        $this->assertCycleLimits($sessions);

        return $sessions;
    }

    private function customSessions(Employee $employee, string $workDate, array $input): array
    {
        $policy = AttendancePolicy::snapshotFor($employee, $workDate);

        $sessions = array_map(function (array $row) use ($workDate, $policy) {
            return [
                'seq' => $row['seq'],
                'id' => $row['id'] ?? null,
                'start_at' => WorkTime::at($row['start_date'] ?? $workDate, $row['start_time']),
                'end_at' => WorkTime::at($row['end_date'] ?? $workDate, $row['end_time']),
                'unpaid_break_minutes' => (int) ($row['unpaid_break_minutes'] ?? 0),
                'policy_snapshot' => $policy,
            ];
        }, $input);

        usort($sessions, fn (array $a, array $b) => $a['seq'] <=> $b['seq']);
        if (count($sessions) > 4) {
            throw new BusinessRuleException('SESSION_INVALID', 'Satu siklus maksimal memiliki 4 sesi.', 422);
        }
        $previousEnd = null;
        foreach ($sessions as $index => $session) {
            $length = WorkTime::minutesBetween($session['start_at'], $session['end_at']);
            if ($session['seq'] !== $index + 1 || $length <= 0 || $length > 720 || $session['unpaid_break_minutes'] >= $length
                || ($previousEnd && $session['start_at']->lt($previousEnd))) {
                throw new BusinessRuleException('SESSION_INVALID', 'Urutan, durasi, atau istirahat sesi tidak valid.');
            }
            $previousEnd = $session['end_at'];
        }
        if ($sessions[0]['start_at']->setTimezone(WorkTime::tz())->toDateString() !== $workDate) {
            throw new BusinessRuleException('WORK_DATE_MISMATCH', 'Tanggal kerja harus tanggal mulai sesi pertama.');
        }

        $this->assertCycleLimits($sessions);

        return $sessions;
    }

    private function assertCycleLimits(array $sessions): void
    {
        if ($sessions === []) {
            throw new BusinessRuleException('SESSION_INVALID', 'Siklus harus memiliki setidaknya satu sesi.', 422);
        }
        $previousEnd = null;
        foreach (array_values($sessions) as $index => $session) {
            if ($session['seq'] !== $index + 1 || ($previousEnd && $session['start_at']->lt($previousEnd))) {
                throw new BusinessRuleException('SESSION_INVALID', 'Urutan sesi tidak valid atau saling bertabrakan.', 422);
            }
            $previousEnd = $session['end_at'];
        }
        $first = $sessions[0]['start_at'];
        $last = $sessions[array_key_last($sessions)]['end_at'];
        if ($last->lte($first)) {
            throw new BusinessRuleException('SESSION_INVALID', 'Sesi terakhir harus berakhir setelah sesi pertama dimulai.');
        }
        $scheduled = array_sum(array_map(fn (array $session) => max(0, intdiv($session['end_at']->getTimestamp() - $session['start_at']->getTimestamp(), 60) - $session['unpaid_break_minutes']), $sessions));
        if ($scheduled > 720) {
            throw new BusinessRuleException('CYCLE_WORK_LIMIT', 'Total kerja satu siklus maksimal 12 jam.');
        }
        if ($last->getTimestamp() - $first->getTimestamp() > 13 * 60 * 60) {
            throw new BusinessRuleException('CYCLE_SPAN_LIMIT', 'Rentang satu siklus maksimal 13 jam.');
        }
    }

    private function reconcileRunningSessions($current, array $input, CarbonImmutable $now): array
    {
        $kept = [];
        $updates = [];
        $keptIds = [];

        foreach ($current as $existing) {
            $record = $existing->attendanceRecord;
            $finished = $existing->end_at->lte($now) || $record?->check_out_at || $record?->provisional_out_at;
            $started = $existing->start_at->lte($now) || $record?->check_in_at;
            if (! $started) {
                continue;
            }

            $row = collect($input)->first(fn (array $candidate) => ($candidate['id'] ?? null) === $existing->id);
            if (! $row || $row['start_at']->ne($existing->start_at) || $row['unpaid_break_minutes'] !== $existing->unpaid_break_minutes) {
                throw new BusinessRuleException('SCHEDULE_HISTORY_LOCKED', 'Sesi yang sudah dimulai harus dipertahankan.');
            }
            if ($row['seq'] !== $existing->seq) {
                throw new BusinessRuleException('SCHEDULE_HISTORY_LOCKED', 'Urutan sesi yang sudah dimulai harus dipertahankan.');
            }
            if ($finished && $row['end_at']->ne($existing->end_at)) {
                throw new BusinessRuleException('SCHEDULE_HISTORY_LOCKED', 'Sesi yang sudah selesai tidak boleh diubah.');
            }
            if (! $finished && $row['end_at']->ne($existing->end_at)) {
                if ($row['end_at']->lte($now) || ($record?->check_in_at && $row['end_at']->lte($record->check_in_at))) {
                    throw new BusinessRuleException('SESSION_END_INVALID', 'Akhir sesi aktif harus setelah waktu sekarang dan setelah check-in.');
                }
                $updates[] = [$existing, $row['end_at']];
            }

            $keptIds[] = $existing->id;
            $kept[] = [
                'id' => $existing->id, 'seq' => $existing->seq, 'start_at' => $existing->start_at,
                'end_at' => $row['end_at'], 'unpaid_break_minutes' => $existing->unpaid_break_minutes,
                'policy_snapshot' => $existing->policy_snapshot,
            ];
        }

        $created = [];
        foreach ($input as $row) {
            if (! $row['id'] || ! in_array($row['id'], $keptIds, true)) {
                if ($row['start_at']->lte($now)) {
                    throw new BusinessRuleException('SESSION_STARTED', 'Sesi baru harus dimulai di masa depan.');
                }
                unset($row['id']);
                $created[] = $row;
            }
        }

        return [$kept, $created, $updates];
    }

    private function archiveCurrentSessions(?ShiftAssignment $assignment): void
    {
        if (! $assignment?->exists) {
            return;
        }
        $assignment->sessions()->update(['retired_at' => WorkTime::now()]);
    }

    private function assertEmployed(Employee $employee, string $workDate): void
    {
        $outside = $workDate < $employee->started_on || ($employee->ended_on && $workDate > $employee->ended_on);
        if ($employee->status !== 'active' || $outside) {
            throw new BusinessRuleException('EMPLOYEE_INACTIVE', "{$employee->name} tidak aktif bekerja pada {$workDate}.");
        }

        $term = EmploymentTerm::query()->where('employee_id', $employee->id)
            ->where('starts_on', '<=', $workDate)->orderByDesc('starts_on')->first();
        if ($term && ($workDate < $term->starts_on->format('Y-m-d') || ($term->type === 'contract' && $term->ends_on && $workDate > $term->ends_on->format('Y-m-d')))) {
            throw new BusinessRuleException('EMPLOYEE_CONTRACT_EXPIRED', "{$employee->name} tidak memiliki kontrak aktif pada {$workDate}.");
        }
    }

    public function assertEditable(ShiftAssignment $assignment): void
    {
        $first = $assignment->sessions->first();
        $locked = in_array($assignment->status, ['IN_PROGRESS', 'COMPLETED'], true)
            || ($first && $first->start_at->lte(WorkTime::now()))
            || DB::table('attendance_events')->whereIn('shift_session_id', $assignment->sessions->pluck('id'))->exists();

        if ($locked) {
            throw new BusinessRuleException('SCHEDULE_LOCKED', 'Jadwal sudah mulai. Perubahan lewat koreksi.');
        }
    }

    private function assertNoOverlap(Employee $employee, string $workDate, array $sessions, ?int $ignoreId): void
    {
        $date = CarbonImmutable::parse($workDate);
        $others = ShiftAssignment::query()
            ->with('sessions')
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$date->subDay()->toDateString(), $date->addDay()->toDateString()])
            ->where('status', '!=', 'CANCELLED')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($others as $other) {
            foreach ($other->sessions as $existing) {
                foreach ($sessions as $new) {
                    if ($new['start_at']->lt($existing->end_at) && $existing->start_at->lt($new['end_at'])) {
                        throw new BusinessRuleException('SCHEDULE_OVERLAP', "Bentrok dengan jadwal {$other->template_name} tanggal {$other->work_date}.", 409, [
                            'assignment_id' => $other->id,
                        ]);
                    }
                }
            }
        }
    }
}
