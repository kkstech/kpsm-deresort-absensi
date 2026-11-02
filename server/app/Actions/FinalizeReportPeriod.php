<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePeriod;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Audit;
use App\Support\WorkTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinalizeReportPeriod
{
    public function execute(User $actor, string $from, string $to, ReportService $reports): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $currentMonthStart = CarbonImmutable::parse(WorkTime::today())->startOfMonth();

        if ($start->day !== 1 || ! $end->isLastOfMonth() || $end->lt($start)) {
            throw new BusinessRuleException('INVALID_FINALIZATION_PERIOD', 'Finalisasi hanya tersedia untuk rentang satu atau beberapa bulan kalender penuh.', 422);
        }
        if ($end->gte($currentMonthStart)) {
            throw new BusinessRuleException('PERIOD_NOT_ENDED', 'Finalisasi hanya tersedia untuk bulan yang sudah berakhir.', 422);
        }
        if ($start->diffInDays($end) > 62) {
            throw new BusinessRuleException('PERIOD_TOO_LONG', 'Rentang finalisasi maksimal 62 hari.', 422);
        }

        return DB::transaction(function () use ($actor, $start, $end, $reports) {
            $pending = $reports->pendingReviewBlockers($start->toDateString(), $end->toDateString());
            if ($pending['count'] > 0) {
                throw new BusinessRuleException(
                    'REPORT_HAS_PENDING_REVIEW',
                    "Masih ada {$pending['count']} sesi absensi yang perlu diverifikasi.",
                    409,
                    $pending,
                );
            }

            for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonth()) {
                $monthKey = $month->format('Y-m');
                $period = AttendancePeriod::query()->where('month', $monthKey)->lockForUpdate()->first();
                $before = $period?->attributesToArray();
                $period ??= new AttendancePeriod(['month' => $monthKey]);
                $period->status = 'OPEN';
                $period->revision = max(1, $period->revision);
                $period->finalized_at = WorkTime::now();
                $period->finalized_by = $actor->id;
                $period->save();
                Audit::log('report.period_finalized', $period, $before, [
                    ...$period->attributesToArray(),
                    'report_only' => true,
                ]);
            }

            return ['period_status' => 'FINALIZED'];
        });
    }
}
