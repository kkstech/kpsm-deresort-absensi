<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class WorkTime
{
    public static function tz(): string
    {
        return config('attendance.timezone');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function today(): string
    {
        return self::now()->setTimezone(self::tz())->toDateString();
    }

    /** Jam lokal pada tanggal kerja, dikembalikan dalam UTC. */
    public static function at(string $date, string $time, int $dayOffset = 0): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", self::tz())->addDays($dayOffset)->utc();
    }

    public static function local(?CarbonInterface $moment): ?CarbonImmutable
    {
        return $moment ? CarbonImmutable::instance($moment)->setTimezone(self::tz()) : null;
    }

    public static function iso(?CarbonInterface $moment): ?string
    {
        return $moment ? CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** "08.15", atau "06.00 (+1)" bila jatuh setelah tanggal kerja. */
    public static function clock(?CarbonInterface $moment, ?string $workDate = null): ?string
    {
        $local = self::local($moment);
        if (! $local) {
            return null;
        }
        $suffix = $workDate && $local->toDateString() > $workDate ? ' (+1)' : '';

        return $local->format('H.i').$suffix;
    }

    public static function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }
}
