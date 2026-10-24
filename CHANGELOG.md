# Changelog

Baca file ini sebelum lanjut mengerjakan backend. `README.md` masih menggambarkan frontend demo dan belum diperbarui. Jangan anggap README sebagai status kode.

Rencana kerja ada di plan "Backend + API mobile". Jangan ubah file plan itu. Lanjut dari bagian yang belum selesai di bawah.

Tanggal: 24 Oktober 2026.

## Perubahan terbaru — Docker, GitHub Actions, GHCR, dan Dokploy

- Menambahkan Dockerfile multi-stage PHP 8.4/Apache untuk Laravel, aset Vite, MySQL, health check, persistent storage support, dan scheduler dalam satu container aplikasi.
- GitHub Actions menjalankan test suite, memeriksa migration MySQL 8.4, membangun frontend, lalu menerbitkan image ke GHCR untuk branch `main` dan tag `v*`; deploy Dokploy dapat diaktifkan dengan repository secret/variables.
- README mencatat konfigurasi Dokploy Application non-Compose, MySQL eksternal, environment production, migration, akun admin, dan persistent volume. SQLite database lokal dan file runtime dikecualikan dari Git.
- Tes akses admin disesuaikan untuk memverifikasi penolakan role karyawan; role Admin memang memiliki akses web admin.
- Docker tidak tersedia pada workspace ini; validasi image dilakukan oleh job GitHub Actions setelah push.

## Perubahan terbaru — penghapusan autentikasi dua langkah

- Atas permintaan, autentikasi web sekarang memakai email dan password tanpa TOTP atau kode cadangan. Batas akses peran, status aktif, CSRF, rate limit login, dan opsi “Ingat saya” tetap berlaku.
- Halaman challenge dan kode cadangan, endpoint pengaturan/reset 2FA, pemeriksaan middleware, serta dependensi `pragmarx/google2fa` sudah dihapus.
- Migration `2026_10_24_060000_remove_two_factor_authentication` sudah diterapkan pada database SQLite lokal. Secret TOTP, kode pemulihan, dan kolom konfirmasi yang tersimpan telah dihapus.
- Verifikasi suite tidak dijalankan setelah perubahan ini. Hasil tes di bawah adalah catatan baseline sebelum TOTP dihapus.
- Plan teknis tidak diubah sesuai instruksi file tersebut; butir verifikasi TOTP pada tahap 8 perlu dianggap tidak berlaku untuk implementasi ini.

## Perubahan terbaru — modal dan pengaturan website

- Dialog form, jadwal, workflow, dan konfirmasi memakai modal aplikasi yang dipusatkan. Isi dialog panjang dapat di-scroll tanpa menghilangkan judul dan tombol aksi; toast tetap di kanan bawah.
- Tab Umum menyediakan nama website, logo login/sidebar, favicon, dan kontak opsional di halaman login. Perubahan dipratinjau sebelum disimpan dan dapat diedit Super Admin, Admin, maupun Supervisor.
- Logo/favikon memakai unggahan publik PNG/JPG/WebP dan PNG/ICO, maksimal 2 MB. Migration `2026_10_24_070000_create_site_settings_table` diterapkan lokal; `php artisan storage:link` juga sudah dijalankan.
- Warna tetap memakai palet yang ada. Logo dan favicon tidak tampil sampai diunggah; ikon globe login dipertahankan.
- `DemoDataSeeder` kini membuat contoh karyawan, jadwal, absensi lengkap, absensi review, dan sesi tanpa kehadiran. Seeder dijalankan pada SQLite lokal; tetap menolak environment production.

## Selesai

`php artisan test` di `server/`: 55 tes, 338 assertion, lulus. Tes memakai SQLite `:memory:` (`phpunit.xml`), bukan file `database/database.sqlite`.

### Fondasi

- Sanctum. `User` memakai `HasApiTokens`.
- Login web di `/login` memakai email dan password; semua peran web yang diizinkan dapat langsung masuk tanpa TOTP. Karyawan tidak bisa masuk web.
- API mobile: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`. Login mendaftarkan perangkat dan menamai token `device:{id}`. Login ulang di perangkat yang sama mengganti token lama.
- Rate limit login: 5 per menit per email dan IP.
- Error JSON `{code, message, details?, request_id}` untuk `api/*` dan `admin/api/*`. `BusinessRuleException` menjadi 409. Pelanggaran foreign key menjadi `IN_USE`. Pelanggaran unique menjadi `DUPLICATE`.
- `App\Support\Audit` menulis `audit_logs` plus `request_id`.
- `config/attendance.php` dan `App\Support\WorkTime`. Database dan API tetap UTC. Tampilan memakai `Asia/Jakarta`.
- Akun super admin dari `ADMIN_EMAIL` dan `ADMIN_PASSWORD` lewat `AdminUserSeeder`.
- Kolom tanggal (`started_on`, `ended_on`, `effective_from`, `effective_to`) tidak di-cast Eloquent. Cast `date` menyimpan `Y-m-d H:i:s` dan merusak query rentang di SQLite.

### Master

JSON di `/admin/api`, session plus CSRF.

- Departemen dan jabatan lewat `CatalogController`.
- Lokasi kerja (`none` atau `radius`), template shift (1 sampai 4 sesi), kebijakan kehadiran.
- Karyawan: buat, ubah, akun app, nonaktifkan, cabut perangkat. Pindah departemen menambah `employee_placements` pada tanggal hari ini.
- Akun web (super admin, admin, supervisor) hanya super admin. Super admin tidak bisa menurunkan atau menonaktifkan diri sendiri.
- Prioritas kebijakan: perorangan, lalu departemen, lalu global. `AttendancePolicy::resolveFor()`.
- Layar admin: Karyawan dan Pengaturan. Modul JS di `server/resources/js/admin/`. HTML dari data server lewat `esc()` di `ui.js`.

### Jadwal

- Tabel `shift_assignments` (unik `employee_id` + `work_date`) dan `shift_sessions` (UTC, plus `policy_snapshot`).
- `App\Actions\PublishSchedule`: buat atau ganti, batal, terbitkan rentang, salin minggu. `lockForUpdate` pada karyawan. Cek bentrok lintas tanggal, termasuk shift malam. Jadwal yang sudah mulai ditolak (`SESSION_STARTED`, `SCHEDULE_LOCKED`). Periode `FINALIZED` ditolak (`PERIOD_LOCKED`).
- Shift malam 22.00–06.00 tetap satu `work_date`. Akhir sesi hari berikutnya.
- Menit terjadwal: Reguler 08.00–17.00 dengan istirahat 60 menit = 480. Khusus 2 sesi = 360.
- Endpoint: `GET/POST /admin/api/schedule`, `DELETE /admin/api/schedule/{id}`, `POST /admin/api/schedule/publish`, `POST /admin/api/schedule/copy-week`.
- Kalender mingguan dan bulanan di `resources/js/admin/schedule.js` memakai API. Banner "Data contoh" di layar jadwal sudah dihapus.
- `DemoDataSeeder` opsional, jangan jalan di production: `php artisan db:seed --class=DemoDataSeeder`.

### Absensi dan API mobile (tahap 1–2)

- `App\Actions\RecordAttendance` diuji untuk retry UUID, konflik payload, waktu server online, event offline, periode final, dan koordinat GPS.
- Event checkout yang tiba sebelum check-in tetap disimpan sebagai `REVIEW_REQUIRED`; check-in yang datang kemudian memasangkannya ke proyeksi review bila waktu event masih wajar, dengan menit valid tetap 0.
- Event pada periode `FINALIZED` tetap menjadi bukti audit, tetapi tidak mengubah `attendance_records`.
- Lokasi dan aturan GPS diambil dari snapshot sesi. Koordinat dibuang saat pemeriksaan lokasi tidak aktif.
- Pencatatan event menulis `audit_logs`. Request memakai token hanya jika perangkatnya masih terdaftar dan aktif.
- API mobile: `GET /api/v1/me/schedule`, `POST /api/v1/attendance/events`, `POST /api/v1/attendance/sync`, `GET /api/v1/attendance/me`.
- Sync menyimpan `sync_batches`, memberi hasil per event, membatasi 100 event per batch, dan mengurutkan berdasarkan `local_sequence` bila tersedia untuk semua event; jika tidak, memakai `device_at` bila tersedia untuk semua.
- Event UUID sama dan payload sama memberi `DUPLICATE`; payload berbeda memberi `UUID_PAYLOAD_CONFLICT`. Offline selalu perlu review dan tidak menambah menit valid.
- Kontrak endpoint mobile dan autentikasi ada di `server/docs/api-contract.yaml`.
- Tes fitur baru tercakup dalam hasil suite di atas; SQLite `:memory:` belum memverifikasi locking MySQL secara bersamaan.

### Auto checkout dan Monitoring (tahap 3)

- `attendance:auto-checkout` berjalan setiap menit lewat scheduler dan aman diulang. Kandidat dikunci dengan urutan employee → sesi → record seperti pencatatan event.
- Auto checkout mengisi `provisional_out_at` pada akhir sesi, mempertahankan `check_out_at` kosong, menandai review `AUTO_CHECKOUT`, dan menjaga `verified_minutes` = 0. Periode final dilewati.
- Checkout offline yang tiba setelah auto checkout tetap ditandai review; event mentah tidak diubah dan menit valid tetap 0.
- API admin: `GET /admin/api/dashboard/attendance`, `GET /admin/api/attendance`, dan `GET /admin/api/attendance/filters`. Filter tanggal/departemen/karyawan diterapkan pada query; opsi serta hasil supervisor mengikuti scope departemen.
- Dashboard dan Monitoring memakai data API, menampilkan status sesi/event dan menit valid, menyediakan pagination/filter, dan tidak lagi menampilkan angka contoh. Laporan tetap belum tersedia.
- Database lokal `server/database/database.sqlite` sudah menjalankan migration jadwal dan absensi. `npm run build` berhasil; browser interaktif tidak tersedia pada sesi ini.
- Tes mencakup idempotensi command, checkout yang datang sebelum maupun setelah provisional, periode final, keterlihatan hasil provisional pada Monitoring, agregat Dashboard, filter scope supervisor, dan render layar admin. Pengujian row lock MySQL tetap menunggu tahap 8.

### Izin, koreksi, dan verifikasi (tahap 4)

- Migration `2026_10_24_050000_create_attendance_workflow_tables` menambah permintaan izin terlambat, koreksi, bukti privat, dan relasi izin pada proyeksi absensi. Field bukti wajib mengikuti snapshot policy sesi.
- API mobile: `GET/POST /api/v1/late-permissions`, `POST /api/v1/late-permissions/{id}/cancel`, `GET/POST /api/v1/attendance-corrections`, `POST /api/v1/attendance-corrections/{id}/cancel`, dan `GET /api/v1/attendance/attachments/{id}`. Multipart evidence menerima JPG/PNG/PDF sampai 10 MiB.
- Admin API: `GET /admin/api/workflows`, keputusan izin/koreksi, izin langsung Super Admin, koreksi langsung Admin, verifikasi checkout, dan unduhan lampiran. Supervisor hanya dapat memutuskan izin dalam scope-nya; pengaju tidak dapat menyetujui sendiri.
- Izin yang disetujui hanya mengaitkan izin ke proyeksi dan menampilkan status terlambat berizin; timestamp mentah tetap. Koreksi mengubah proyeksi dengan snapshot before/after dan audit, tidak mengubah event mentah. Checkout provisional/offline diverifikasi memakai waktu dan dasar verifikasi yang dimasukkan Admin; menit dibayar dihitung ulang.
- Permintaan, keputusan, koreksi, dan verifikasi ditolak untuk periode `FINALIZED`. Bukti disimpan di disk `local` privat; unduhan dibatasi ke karyawan pemilik atau akun staf yang memang melihat karyawan itu.
- Layar Persetujuan berisi antrean izin/koreksi dan formulir izin langsung Super Admin. Monitoring menyediakan aksi verifikasi dan koreksi langsung untuk Admin. Dashboard menghapus hitungan review ketika record sudah dituntaskan.
- Tes tahap 4: 7 tes fitur, termasuk bukti wajib dan penyimpanan policy, scope/otorisasi, self-approval, akses file, koreksi Admin, keputusan dan proyeksi idempoten, event mentah, verifikasi, dan periode final. `php artisan test`: 55 tes / 338 assertion lulus. `npm run build` lulus; browser visual manual belum dijalankan.
- Migration workflow sudah diterapkan ke SQLite lokal. Tes MySQL row lock dan balapan concurrent tetap menunggu tahap 8.

## Setengah jadi

- Lembur, tunjangan, laporan, ekspor, dan finalisasi/reopen periode belum tersedia.

`attendance_periods` ikut migration jadwal `2026_10_24_030000`. Dipakai untuk kunci periode. Finalisasi dan buka ulang belum ada.

## Belum

Jangan anggap ini sudah ada.

- Lembur: perintah, consent sebelum mulai, event terpisah, metode `ACTUAL_CAPPED` saja.
- Tunjangan, laporan JSON, export XLSX (`openspout`) dan PDF (`dompdf`). Paket sudah terpasang, belum dipakai.
- Finalisasi dan buka ulang periode. Buka ulang butuh alasan dan revisi baru.
- App Expo di `mobile/` masih demo. Jangan sambungkan di ronde ini kecuali diminta.

## Database lokal

`database/database.sqlite` (24 Oktober 2026, `php artisan migrate:status`):

- Semua migration sudah jalan, termasuk jadwal `030000`, absensi `040000`, dan workflow `050000` (batch 6).

Tes tetap memakai SQLite `:memory:` dan tidak mengubah file database lokal.

## Keputusan yang jangan diubah

- Admin web: session dan CSRF di `/admin/api`. Mobile: Bearer Sanctum di `/api/v1`.
- Role cukup kolom `users.role`. Tanpa tabel RBAC.
- Supervisor hanya melihat departemennya, kecuali `can_view_all`. Admin melihat semua. Cek di query, bukan hanya di tombol.
- Satu jadwal per karyawan per tanggal kerja.
- Event absensi tidak diubah dan tidak dihapus. Koreksi menimpa proyeksi, bukan event mentah.
- Jam bisnis `dateTime` UTC. `work_date` string `Y-m-d` tanpa cast.
- Jangan pasang Laravel Boost. `server/AGENTS.md` memintanya. Abaikan.
- Jangan tulis token, password, atau secret ke file, remote, atau rule.
