<?php

use App\Actions\AutoCheckoutAttendance;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create {email=admin@deresort.com} {password=Admin123!}', function () {
    $email = strtolower($this->argument('email'));
    $password = $this->argument('password');
    $user = \App\Models\User::updateOrCreate(
        ['email' => $email],
        [
            'name' => 'Administrator',
            'password' => $password,
            'role' => 'super_admin',
            'is_active' => true,
        ]
    );
    $this->info("Super Admin berhasil dibuat/diperbarui: {$user->email}");
})->purpose('Buat atau perbarui akun Super Admin');

Artisan::command('attendance:auto-checkout', function (AutoCheckoutAttendance $autoCheckout) {
    $this->info("{$autoCheckout->handle()} sesi ditutup provisional.");
})->purpose('Tutup sesi absensi yang belum checkout secara provisional');

Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
