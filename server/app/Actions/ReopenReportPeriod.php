<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReopenReportPeriod
{
    public function execute(User $actor, string $from, string $to, string $reason): array
    {
        $reason = trim($reason);
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $currentMonthStart = CarbonImmutable::parse(WorkTime::today())->startOfMonth();

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new BusinessRuleException('INVALID_REOPEN_REASON', 'Alasan buka ulang harus berisi 3 sampai 500 karakter.', 422);
        }
        if ($start->day !== 1 || ! $end->isLastOfMonth() || $end->lt($start)) {
            throw new BusinessRuleException('INVALID_REOPEN_PERIOD', 'Buka ulang hanya tersedia untuk rentang satu atau beberapa bulan kalender penuh.', 422);
        }
        if ($end->gte($currentMonthStart)) {
            throw new BusinessRuleException('PERIOD_NOT_ENDED', 'Buka ulang hanya tersedia untuk bulan yang sudah berakhir.', 422);
        }
        if ($start->diffInDays($end) > 62) {
            throw new BusinessRuleException('PERIOD_TOO_LONG', 'Rentang buka ulang maksimal 62 hari.', 422);
        }

        return DB::transaction(function () use ($actor, $start, $end, $reason) {
            $revisions = [];

            for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonth()) {
                $monthKey = $month->format('Y-m');
                $period = AttendancePeriod::query()->where('month', $monthKey)->lockForUpdate()->first();

                if (! $period || (! $period->finalized_at && $period->status !== 'FINALIZED')) {
                    throw new BusinessRuleException('PERIOD_NOT_FINALIZED', "Rekap {$monthKey} belum final.", 409);
                }

                $before = $period->attributesToArray();
                $period->status = 'OPEN';
                $period->revision++;
                $period->finalized_at = null;
                $period->finalized_by = null;
                $period->reopened_at = WorkTime::now();
                $period->reopened_by = $actor->id;
                $period->reopen_reason = $reason;
                $period->save();

                Audit::log('report.period_reopened', $period, $before, [
                    ...$period->attributesToArray(),
                    'reason' => $reason,
                ]);
                $revisions[$monthKey] = $period->revision;
            }

            return ['period_status' => 'PROVISIONAL', 'revisions' => $revisions];
        });
    }
}
