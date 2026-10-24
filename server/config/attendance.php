<?php

return [
    // Zona kerja untuk tanggal kerja dan tampilan. Database dan API tetap UTC.
    'timezone' => env('WORK_TIMEZONE', 'Asia/Jakarta'),

    // Jendela check-in/out relatif terhadap jadwal sesi.
    'checkin_early_minutes' => (int) env('ATTENDANCE_CHECKIN_EARLY_MINUTES', 60),
    'checkout_late_minutes' => (int) env('ATTENDANCE_CHECKOUT_LATE_MINUTES', 240),

    // Sesi yang belum checkout ditutup provisional setelah jam akhir + nilai ini.
    'auto_checkout_after_minutes' => (int) env('ATTENDANCE_AUTO_CHECKOUT_AFTER_MINUTES', 30),

    // Event offline dianggap mencurigakan bila jam perangkat di luar batas ini.
    'offline_max_age_hours' => (int) env('ATTENDANCE_OFFLINE_MAX_AGE_HOURS', 72),
    'offline_future_tolerance_minutes' => (int) env('ATTENDANCE_OFFLINE_FUTURE_TOLERANCE_MINUTES', 2),

    // Batas pengajuan izin terlambat setelah jam mulai sesi. [KONFIGURASI/TBD di PRD]
    'late_request_deadline_hours' => (int) env('LATE_REQUEST_DEADLINE_HOURS', 24),

    'overtime_checkin_early_minutes' => (int) env('OVERTIME_CHECKIN_EARLY_MINUTES', 30),

    'admin' => [
        'name' => env('ADMIN_NAME', 'Super Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
