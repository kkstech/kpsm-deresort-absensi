<?php

use App\Actions\AutoCheckoutAttendance;
use App\Actions\SendOvertimeRecommendations;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create {email=admin@deresort.com} {password=Admin123!}', function () {
    $email = strtolower($this->argument('email'));
    $password = $this->argument('password');
    $user = User::updateOrCreate(
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

Artisan::command('overtime:recommendations', function (SendOvertimeRecommendations $sendRecommendations) {
    $this->info("{$sendRecommendations->handle()} rekomendasi lembur diperiksa.");
})->purpose('Kirim notifikasi rekomendasi lembur ke Admin dan Super Admin');

Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
Schedule::command('overtime:recommendations')->everyMinute()->withoutOverlapping();
