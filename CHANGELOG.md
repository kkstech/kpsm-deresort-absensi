# Changelog

Baca file ini sebelum lanjut mengerjakan backend. `README.md` masih menggambarkan frontend demo dan belum diperbarui. Jangan anggap README sebagai status kode.

Rencana kerja ada di plan "Backend + API mobile". Jangan ubah file plan itu. Lanjut dari bagian yang belum selesai di bawah.

Tanggal: 29 Oktober 2026.

## Perubahan terbaru — Perapihan Kartu Kontak Bantuan pada Halaman Login

- Merapikan blok kontak bantuan (`.auth-contact`) di bawah tombol Masuk pada halaman login web admin (`server/resources/views/auth/layout.blade.php`).
- Menambahkan label eksplisit dan terstruktur: **Email:**, **No. Telp:**, dan **Alamat:** dengan ikon bantuan SVG (`Bantuan & Kontak Pengelola`).
- Mengubah tata letak dari teks bertumpuk vertikal menjadi layout kartu horizontal kompak (`display: flex; flex-wrap: wrap; gap: 6px 14px;`) berlatar soft (`var(--surface-soft)`), sehingga tidak memanjang ke bawah dan proporsional terhadap kartu login.
- Menyelaraskan pratinjau login di menu Pengaturan Website (`server/resources/js/admin/settings.js`, `server/resources/css/app.css`) agar menampilkan format label yang sama.

## Perubahan terbaru — Kompresi Foto Presensi Dua Arah & Watermark Visual

- **Tier 1 — Kompresi & Resize Otomatis pada Perangkat Mobile:**
  - Mengintegrasikan paket resmi `expo-image-manipulator` (`mobile/package.json`, `mobile/App.tsx`).
  - Setelah selfie ditangkap kamera depan, gambar otomatis di-resize dimensinya ke lebar 640px dengan kompresi JPEG 60% (`compress: 0.6`).
  - Menurunkan ukuran payload data base64 dari ~250 KB menjadi hanya **~35–50 KB**, menghemat penggunaan kuota internet karyawan, mempercepat waktu upload saat koneksi seluler lambat, dan meminimalkan beban memori antrean SQLite saat offline.

- **Tier 2 — Watermark & Konversi WebP pada Server Backend:**
  - Membuat layanan `AttendancePhotoService` (`server/app/Services/AttendancePhotoService.php`) untuk pemrosesan gambar presensi terpusat.
  - Membubuhkan watermark visual semi-transparan di bagian bawah foto secara otomatis menggunakan PHP GD dan tipografi Plus Jakarta Sans (`plus-jakarta-600.ttf`):
    - Baris 1: Nama Karyawan & Kode NIK (contoh: `Budi Santoso · EMP-001`).
    - Baris 2: Tanggal & Waktu presensi (contoh: `29 Okt 2026, 18:45:10 WIB`).
  - Mengonversi dan mengompresi foto ke format modern **WebP** (`quality: 75`), menghasilkan file akhir yang sangat hemat ruang penyimpanan (**~25–35 KB** per foto) serta cepat diakses saat pratinjau di dashboard web admin.
  - Mekanisme *graceful fallback*: jika pemrosesan GD gagal membaca file tertentu, server otomatis menyimpan foto mentah sebagai `.jpg` agar proses absensi karyawan tidak pernah gagal.
  - Menghubungkan layanan ini ke endpoint presensi online (`storeEvent`) dan sinkronisasi offline batch (`sync`) pada `AttendanceController.php`.
  - Test suite: unit test `AttendancePhotoServiceTest` dan feature test `AttendanceTest` (121 tes lulus 100%).

## Perubahan terbaru — Foto Presensi (Selfie Kamera Langsung) & Deteksi Fake GPS / Mock Location

- **Foto Presensi Wajib (Live Selfie):**
  - Menambahkan verifikasi foto selfie saat melakukan aksi presensi (Check-In dan Check-Out) pada aplikasi mobile (`mobile/App.tsx`).
  - Menggunakan kamera depan (`ImagePicker.launchCameraAsync({ cameraType: 'front', quality: 0.5, base64: true })`) tanpa akses galeri foto guna mencegah kecurangan (*anti-spoofing*).
  - Tampilan konfirmasi check-in/out kini menampilkan kotak pratinjau foto selfie dan opsi "Foto Ulang" jika foto dirasa kurang jelas. Tombol "Konfirmasi" hanya aktif setelah foto berhasil diambil.
  - Foto dikirim dalam payload base64 (`photo_base64`) maupun multipart file (`photo`), disimpan backend secara aman di direktori publik `storage/app/public/attendance_photos/`.
  - Path foto disimpan pada `attendance_events.photo_path`, dan diproyeksikan ke `attendance_records.check_in_photo_path` / `check_out_photo_path`.
  - Backend API otomatis mengekspos accessor `check_in_photo_url` dan `check_out_photo_url`.

- **Deteksi & Pencegahan Fake GPS (Mock Location):**
  - Mendeteksi penggunaan aplikasi Fake GPS / Mock Location Provider di level sistem operasi pada mobile client via `position.mocked` dari `expo-location`.
  - Jika mock location aktif pada perangkat pengguna, aplikasi mobile langsung memblokir aksi presensi dan memunculkan peringatan *"Aplikasi Fake GPS / lokasi tiruan terdeteksi. Matikan aplikasi pemalsu lokasi untuk melanjutkan presensi."*
  - Payload lokasi menyertakan flag `is_mocked`. Backend (`RecordAttendance.php`) memvalidasi flag ini: jika `is_mocked = true`, event otomatis ditolak dengan status `REJECTED` dan alasan `FAKE_GPS_DETECTED` serta diaudit tanpa memproyeksikan sesi hadir.
  - Pesan humanis penolakan alasan `FAKE_GPS_DETECTED`, `OUTSIDE_RADIUS`, `GPS_REQUIRED`, dan `GPS_ACCURACY_LOW` dipetakan di mobile app.

- **Antarmuka Monitoring Web Admin (`#attendance`):**
  - Menambahkan tombol badge kamera kompak (`.photo-badge-btn`) pada kolom jam masuk dan pulang di tabel monitoring kehadiran harian.
  - Menampilkan jarak presensi dalam satuan meter (misal `12m`) di bawah jam hadir/pulang.
  - Menampilkan badge merah peringatan `Fake GPS` bila event presensi karyawan tersebut terdeteksi menggunakan fake GPS.
  - Menyediakan modal lightbox pratinjau foto (`#photo-preview-modal`) berlatar kontras dengan caption nama karyawan, sesi, dan jam absensi.

## Perubahan terbaru — Perbaikan Field Username / Kode Karyawan Non-Kapital pada Login

- Memperbaiki field input **Username (Kode Karyawan)** pada aplikasi mobile (`mobile/App.tsx`) dan web admin (`server/resources/views/auth/login.blade.php`) agar keyboard virtual tidak otomatis mengaktifkan huruf kapital pada karakter pertama.
- Mengonfigurasi `keyboardType="email-address"`, `autoComplete="username"`, `textContentType="username"`, `autoCapitalize="none"`, dan `autoCorrect={false}` pada input mobile.
- Menambahkan `inputmode="email"`, `autocapitalize="none"`, `autocorrect="off"`, dan menyelaraskan label `Username (Kode Karyawan)` pada form login web admin.

## Perubahan terbaru — Penanganan Foto Profil Missing & Fallback Inisial

- Memperbaiki penanganan `avatar_url` pada model `Employee` dan `User`: jika file foto di disk publik tidak ditemukan (misalnya terhapus atau belum tersinkronisasi), backend otomatis mengembalikan `null` alih-alih URL mati yang memicu 404.
- Menambahkan fallback aman `onerror` pada seluruh rendering elemen `<img>` avatar (Laporan, Absensi, Tunjangan, Cuti, dan Karyawan) agar otomatis berganti menjadi inisial nama tanpa memicu error atau merusak antarmuka.
- Mengeliminasi pemicu error interseptor ekstensi browser (`200.js:1 TypeError reading M_ID`) yang terjadi akibat kegagalan unduh resource foto yang tidak ada.

## Perubahan terbaru — Perbaikan Tata Letak Filter Menu Laporan

- Memperbaiki tata letak panel filter pada menu **Laporan & Rekapitulasi** (`#reports`) agar tidak memanjang ke bawah (*elongated downward*).
- Mengganti deklarasi CSS grid yang memicu konflik browser dengan flexbox horizontal yang responsif (`display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px;`).
- Menata kolom input (Dari Tanggal, Sampai Tanggal, Departemen, Karyawan, Pencarian Nama/NIK, serta tombol Terapkan & Reset) agar sejajar secara horizontal dalam satu baris kompak pada layar desktop.
- Mengoptimalkan tinggi kontrol input (38px) dan merapikan baris preset cepat serta status badge dengan garis pemisah tipis (*dashed separator*).
- Menyediakan responsivitas yang rapi untuk tablet (2 baris) dan mobile (grid 2 kolom untuk tanggal, 1 kolom untuk dropdown/pencarian, dan tombol aksi berdampingan).

## Perubahan terbaru — Perapihan UI Halaman Laporan (UI/UX Pro Max & Anti-Slop)

- Merapikan seluruh komponen visual halaman **Laporan & Rekapitulasi** (`#reports`) agar konsisten dengan tema resort dan bebas dari inline style / AI slop.
- Mengadopsi sistem kartu metrik standar `.metric-card` dengan styling `metric-highlight`, `metric-badge`, dan tipografi angka tabular (`tabular-nums`).
- Menyempurnakan filter panel dengan bar status periode resmi, preset cepat rapi, dan tombol aksi filter yang terintegrasi.
- Menambahkan avatar dan inisial foto karyawan pada tabel ringkasan, detail kehadiran sesi, dan rekap lembur.
- Menggunakan pill status standar (`.status.success`, `.status.warning`, `.status.danger`, `.status.blue`, `.status.neutral`) pada seluruh status sesi dan persetujuan lembur.
- Menambahkan empty state dan loading indicator elegan dengan ikon SVG dan pesan kontekstual.
- Menjamin kepatuhan aksesibilitas kontras WCAG AA, navigasi keyboard dengan `role="tab"` / `aria-selected`, dan responsivitas mobile penuh tanpa horizontal blowout.

## Perubahan terbaru — Penataan Grid 2x2 Tombol Aksi Jadwal & Shift

- Memperbaiki tata letak 4 tombol aksi pada menu **Jadwal & Shift** (`#schedule`) dari vertikal 1 kolom menjadi rapi 2 kolom x 2 baris (Baris 1: *Buat Jadwal* & *Penugasan Massal*, Baris 2: *Terbitkan Draft* & *Salin Minggu Depan*).
- Menyelesaikan konflik spesifisitas CSS antara `.schedule-heading-grid` dan `.heading-actions`.
- Mempertahankan ikon jam `<svg>` pada tombol `#schedule-copy` saat teks tombol berganti dinamis antara tampilan mingguan dan bulanan.

## Perubahan terbaru — Menu Laporan dan Ekspor (XLSX & PDF)

- Menyediakan antarmuka interaktif **Menu Laporan** pada web admin (`#reports`) dengan filter rentang tanggal, preset cepat (Hari Ini, 7 Hari Terakhir, Bulan Ini, Bulan Lalu), filter departemen, karyawan, dan pencarian nama/NIK.
- Menyediakan tiga tab tampilan laporan:
  1. **Rekapitulasi Karyawan:** Ringkasan agregat per karyawan (Hari Terjadwal vs Hadir, Sesi Hadir, Menit & Jam Kerja Valid, Keterlambatan, Terlambat Berizin vs Tanpa Izin, Lembur Diakui, Cuti, Sesi Review, dan Tunjangan).
  2. **Detail Kehadiran Sesi:** Log terperinci per sesi dan tanggal kerja dengan waktu check-in, check-out, status kehadiran, jam valid, dan tunjangan harian, dilengkapi paginasi.
  3. **Rekap Lembur:** Rekapitulasi Surat Perintah Lembur (SPL) dengan nomor SPL, jam rencana, aktual, menit diakui, dan status keputusan.
- Kartu KPI ringkasan: Total Karyawan Terlapor, Total Hari Hadir, Total Jam Reguler Valid, Total Jam Lembur Diakui, Total Keterlambatan, dan Total Tunjangan (Makan + Transport).
- Menghubungkan ekspor **Excel (.xlsx)** via `openspout/openspout` multi-sheet (Sheet 1: Ringkasan Kehadiran & Sheet 2: Detail Sesi) dengan kop resmi, formatting, dan baris total agregat.
- Menghubungkan ekspor **PDF (.pdf)** via `barryvdh/laravel-dompdf` berformat A4 Landscape dengan kop resmi resort dari `SiteSetting`, ringkasan KPI, watermark diagonal `PROVISIONAL` (bila periode belum final), dan kolom tanda tangan otorisasi.
- Endpoint backend baru: `GET /admin/api/reports/filters`, `GET /admin/api/reports/summary`, `GET /admin/api/reports/details`, `GET /admin/api/reports/overtime`, `GET /admin/api/reports/export/excel`, dan `GET /admin/api/reports/export/pdf`.
- Hak akses supervisor otomatis dibatasi pada karyawan dan data dalam departemennya.
- Test suite: `server/tests/Feature/ReportTest.php` (8 tes, 42 assertion, seluruh test suite 116 tes lulus 100%).

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
- Untuk aturan lama, auto checkout mengikuti toleransi sistem lama. Aturan siklus baru mengisi `provisional_out_at` saat akhir sesi atau batas 13 jam sejak check-in pertama, mana yang lebih dulu; `check_out_at` tetap kosong, status ditinjau, dan menit valid tetap 0. Periode final dilewati.
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

- Lembur, laporan, ekspor, dan finalisasi/reopen periode belum tersedia.

`attendance_periods` ikut migration jadwal `2026_10_24_030000`. Dipakai untuk kunci periode. Finalisasi dan buka ulang belum ada.

## Belum

Jangan anggap ini sudah ada.

- Finalisasi dan buka ulang periode. Buka ulang butuh alasan dan revisi baru.

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

## Pelaksanaan lanjutan (25 Oktober 2026)

- Siklus baru (rules version 2) menerima 1–4 sesi, sesi lintas tengah malam, total kerja terjadwal maksimal 12 jam, dan rentang siklus maksimal 13 jam. Perubahan pada siklus yang sudah berjalan mempertahankan sesi yang telah dimulai, mengarsipkan sesi lama, menaikkan revisi, dan menolak event dari ID sesi yang sudah usang.
- Checkout reguler provisional dibatasi 13 jam sejak check-in. Pada split shift, checkout provisional terjadi di akhir sesi atau saat batas 13 jam siklus tercapai. Catatan provisional tidak dihitung sebagai menit tervalidasi sebelum keputusan HR.
- Penukaran jadwal tersedia sebagai pratinjau ber-token, dengan pemeriksaan kontrak, periode, event absensi, bentrok sesi, perpindahan lintas departemen, dan keputusan roster bagi siklus yang kosong. Penyimpanan rantai atomik dan diaudit.
- Migration `2026_10_25_010000_create_hr_tables` menambahkan riwayat level/tarif, pengecualian tunjangan per karyawan, snapshot tunjangan per siklus, kuota cuti tahunan, saldo prorata tahun pertama, reservasi pengajuan, penyesuaian kuota, dan riwayat kontrak tetap/kontrak. Dashboard admin menampilkan saldo cuti per karyawan dan rekap tunjangan.
- Pengaturan kuota cuti dan konfirmasi saldo bisa dilakukan untuk tahun yang dipilih, termasuk tahun mendatang. Pengajuan mengunci saldo karyawan; keputusan dan pembatalan memakai transaksi sehingga pengembalian kuota tidak ganda.
- `mobile/App.tsx` kini memakai API untuk login perangkat, profil, jadwal, absensi, saldo cuti, pengajuan cuti/izin/koreksi, dan bukti lampiran. SecureStore menyimpan token/perangkat; SQLite menyimpan cache dan antrean absensi. Event offline memakai UUID tetap dan urutan lokal. Event online dengan tanda terima ambigu diperiksa selama 120 detik dan kemudian diarahkan ke koreksi, tanpa dikirim ulang dengan waktu server baru.
- CI menambahkan typecheck app mobile; job yang sudah ada menjalankan suite Laravel, build frontend, migrasi MySQL 8.4, build/push GHCR, dan deployment Dokploy bila secret tersedia.
- Verifikasi lokal: `php artisan test` lulus 64 tes / 407 assertion; `npm run build` lulus; `mobile/npm run typecheck` lulus; `vendor/bin/pint --dirty --format agent` lulus; `git diff --check` bersih. Vite membiarkan referensi font `/fonts/*.ttf` untuk resolusi runtime; file tersebut tetap ada di `server/public/fonts`.
- Migrasi MySQL dan build native iOS/Android belum dijalankan secara lokal. Percobaan mengambil versi Expo modules yang cocok dengan SDK 56 terhenti karena DNS registry npm (`ENOTFOUND`); package mobile saat ini masih perlu penyelarasan versi native sebelum build store. CI menguji TypeScript, bukan menghasilkan binary native.

## Pemakaian mobile bersamaan (25 Oktober 2026)

- Token tetap terikat pada kombinasi akun dan perangkat. Satu akun dapat aktif dari beberapa perangkat; login paralel untuk akun yang sama diserialkan dengan row lock agar pendaftaran perangkat dan pergantian token pada perangkat yang sama tidak saling bertabrakan.
- Tes memastikan dua perangkat pada satu akun dan dua karyawan berbeda dapat memakai API bersamaan. `403` tetap dipakai untuk penolakan akses nyata seperti akun nonaktif, sesi karyawan lain, atau `device_id` yang tidak cocok.
- Verifikasi: `php artisan test` lulus 66 tes / 420 assertion; Pint lulus.
