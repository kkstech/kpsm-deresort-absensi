<?php

use App\Actions\AutoCheckoutAttendance;
use App\Actions\BatchVerifyAutoCheckout;
use App\Actions\ClearLateCheckoutReviews;
use App\Actions\SendOvertimeRecommendations;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create {email} {password}', function () {
    $data = Validator::make([
        'email' => $this->argument('email'),
        'password' => $this->argument('password'),
    ], [
        'email' => ['required', 'email', 'max:255'],
        'password' => ['required', 'string', 'min:8', 'max:255'],
    ])->validate();
    $email = Str::lower($data['email']);
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->fill([
        'name' => 'Administrator',
        'username' => $email,
        'password' => $data['password'],
        'role' => 'super_admin',
        'is_active' => true,
    ]);
    $user->save();
    $user->tokens()->delete();
    $this->info("Super Admin berhasil dibuat/diperbarui: {$user->email}");
})->purpose('Buat atau perbarui akun Super Admin');

Artisan::command('attendance:auto-checkout', function (AutoCheckoutAttendance $autoCheckout) {
    $this->info("{$autoCheckout->handle()} sesi ditutup provisional.");
})->purpose('Tutup sesi absensi yang belum checkout secara provisional');

Artisan::command('attendance:clear-late-checkout-reviews {--from= : Tanggal kerja awal (Y-m-d)} {--to= : Tanggal kerja akhir (Y-m-d)} {--apply : Tulis perubahan; tanpa opsi ini hanya dry-run}', function (ClearLateCheckoutReviews $cleanup) {
    $dates = Validator::make(['from' => $this->option('from'), 'to' => $this->option('to')], [
        'from' => ['nullable', 'date_format:Y-m-d'],
        'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
    ]);
    if ($dates->fails()) {
        $this->error(implode(' ', $dates->errors()->all()));

        return 1;
    }
    $apply = (bool) $this->option('apply');
    $this->info($apply ? 'MODE: APPLY (perubahan ditulis)' : 'MODE: DRY-RUN (tidak ada perubahan). Tambahkan --apply untuk menulis.');

    $result = $cleanup->handle($this->option('from'), $this->option('to'), $apply);
    $columns = ['record_id', 'work_date', 'employee', 'rules_version', 'session', 'check_in', 'check_out', 'deadline', 'verified_minutes'];
    $pick = fn (array $rows, array $keys) => array_map(fn (array $row) => array_map(fn ($key) => $row[$key], $keys), $rows);

    $this->line('');
    $this->info(($apply ? 'Dibersihkan' : 'Akan dibersihkan').': '.count($result['eligible']).' record');
    if ($result['eligible'] !== []) {
        $this->table($columns, $pick($result['eligible'], $columns));
        $perDate = collect($result['eligible'])->countBy('work_date')->sortKeys();
        $this->table(['work_date', 'jumlah'], $perDate->map(fn ($count, $date) => [$date, $count])->values()->all());
    }

    $this->info('Dilewati: '.count($result['skipped']).' record');
    if ($result['skipped'] !== []) {
        $skipColumns = ['record_id', 'work_date', 'employee', 'session', 'check_out', 'deadline', 'skip_reason'];
        $this->table($skipColumns, $pick($result['skipped'], $skipColumns));
        $this->table(['skip_reason', 'jumlah'], collect($result['skipped'])->countBy('skip_reason')->map(fn ($count, $reason) => [$reason, $count])->values()->all());
    }
    if ($apply) {
        $this->info("Selesai: {$result['applied']} record diperbarui.");
    }

    return 0;
})->purpose('Hapus tanda verifikasi CHECKOUT_LATE lama yang masih dalam 13 jam dari jam mulai terjadwal (dry-run default)');

Artisan::command('attendance:batch-verify-auto-checkout {--from= : Tanggal kerja awal (Y-m-d)} {--to= : Tanggal kerja akhir (Y-m-d)} {--checkout-time=scheduled : Waktu checkout: "scheduled" (jam selesai shift/lembur) atau "auto" (jam auto checkout)} {--by= : Email admin yang memverifikasi} {--apply : Tulis perubahan; tanpa opsi ini hanya dry-run}', function (BatchVerifyAutoCheckout $batchVerify) {
    $validator = Validator::make([
        'from' => $this->option('from'),
        'to' => $this->option('to'),
        'checkout_time' => $this->option('checkout-time'),
        'by' => $this->option('by'),
    ], [
        'from' => ['nullable', 'date_format:Y-m-d'],
        'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        'checkout_time' => ['nullable', 'in:scheduled,auto'],
        'by' => ['nullable', 'email'],
    ]);
    if ($validator->fails()) {
        $this->error(implode(' ', $validator->errors()->all()));

        return 1;
    }

    $checkoutTime = $this->option('checkout-time') ?: 'scheduled';
    $apply = (bool) $this->option('apply');

    $actorId = null;
    if ($byEmail = $this->option('by')) {
        $actor = User::query()->where('email', Str::lower($byEmail))->first();
        if (! $actor) {
            $this->error("User dengan email {$byEmail} tidak ditemukan.");

            return 1;
        }
        $actorId = $actor->id;
    } else {
        $actorId = User::query()->where('role', 'super_admin')->where('is_active', true)->value('id')
            ?? User::query()->where('role', 'admin')->where('is_active', true)->value('id');
    }

    $this->info($apply ? 'MODE: APPLY (perubahan ditulis)' : 'MODE: DRY-RUN (tidak ada perubahan). Tambahkan --apply untuk menulis.');
    $this->info('Waktu checkout yang digunakan: ['.strtoupper($checkoutTime).'] '.($checkoutTime === 'scheduled' ? '(jam selesai jadwal shift/lembur)' : '(jam auto-checkout sistem)'));

    $result = $batchVerify->handle($this->option('from'), $this->option('to'), $checkoutTime, $apply, $actorId);
    $columns = ['record_id', 'work_date', 'employee', 'session', 'check_in', 'confirmed_out', 'verified_minutes'];
    $pick = fn (array $rows, array $keys) => array_map(fn (array $row) => array_map(fn ($key) => $row[$key], $keys), $rows);

    $this->line('');
    $this->info(($apply ? 'Diverifikasi' : 'Akan diverifikasi').': '.count($result['eligible']).' record');
    if ($result['eligible'] !== []) {
        $this->table($columns, $pick($result['eligible'], $columns));
        $perDate = collect($result['eligible'])->countBy('work_date')->sortKeys();
        $this->table(['work_date', 'jumlah'], $perDate->map(fn ($count, $date) => [$date, $count])->values()->all());
    }

    $this->info('Dilewati: '.count($result['skipped']).' record');
    if ($result['skipped'] !== []) {
        $skipColumns = ['record_id', 'work_date', 'employee', 'session', 'check_in', 'skip_reason'];
        $this->table($skipColumns, $pick($result['skipped'], $skipColumns));
        $this->table(['skip_reason', 'jumlah'], collect($result['skipped'])->countBy('skip_reason')->map(fn ($count, $reason) => [$reason, $count])->values()->all());
    }
    if ($apply) {
        $this->info("Selesai: {$result['applied']} record berhasil diverifikasi.");
    }

    return 0;
})->purpose('Verifikasi massal absensi checkout otomatis (dry-run default)');

Artisan::command('overtime:recommendations', function (SendOvertimeRecommendations $sendRecommendations) {
    $this->info("{$sendRecommendations->handle()} rekomendasi lembur diperiksa.");
})->purpose('Kirim notifikasi rekomendasi lembur ke Admin dan Super Admin');

Schedule::command('attendance:auto-checkout')->everyMinute()->withoutOverlapping();
Schedule::command('overtime:recommendations')->everyMinute()->withoutOverlapping();
