<?php

use App\Actions\AutoCheckoutAttendance;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('attendance:auto-checkout', function (AutoCheckoutAttendance $autoCheckout) {
    $this->info("{$autoCheckout->handle()} sesi ditutup provisional.");
})->purpose('Tutup sesi absensi yang belum checkout secara provisional');

Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
