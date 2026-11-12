# Daftar Bug dan Rekomendasi Perbaikan — De Resort Attendance

**Tanggal laporan:** Kamis, 12 November 2026, pukul ±19.08 WIB, diperbarui ±19.15 WIB setelah cek admin (read-only)  
**Penguji:** John Tester  
**Untuk:** Yoga (developer)  
**Production:** https://deresort.kitaweb.id (`X-App-Version: v0.1.8`, tidak berubah sejak audit siang)  
**Source:** [kkstech/kpsm-deresort-absensi](https://github.com/kkstech/kpsm-deresort-absensi) commit `a3b71fd` (`main`, 11 Nov 23.02 WIB) — sama dengan yang diaudit  
**APK terakhir yang dianalisis:** `id.deresort.attendance` v0.1.9 (versionCode 13), SHA256 `1413f55f46a8e8e5227ca8ca9679a94ae5dc8d5bd6a8ff185e9bd35ce5737682`  
**Akun tes:** karyawan `110997` (employee_id 52, user id 53). Write hanya pada akun ini, minimal, dan dibersihkan.

Dokumen ini menggabungkan audit keamanan 12 Nov (Fase 1+2 + retest malam), 19 temuan QA web/APK 11 Nov, retest APK v0.1.9 (B-19-01..03), dan keputusan checkout opsi pertama. Duplikat digabung di catatan silang, ID asli dipertahankan.

---

## 1. Analisa

De Resort Absensi punya dua permukaan: **API karyawan** (`/api/v1`, dipakai APK) dengan token Sanctum, dan **admin web** (`/admin`, `/admin/api`) dengan sesi cookie + CSRF. Peran web: `admin` dan `super_admin`.

Retest 12 Nov 19.07–19.08 WIB mengonfirmasi bahwa **tidak ada temuan keamanan AUD-01..AUD-08 yang sudah tertutup.** Server masih `v0.1.8`, source masih commit yang sama. Beberapa isu operasional (checkout) sudah **sebagian** mengikuti keputusan opsi pertama yang kamu pilih: pada sesi tes 110997 11 Nov, checkout pukul 23.38 WIB diterima sebagai `CHECKOUT_LATE` dengan `check_out_at` resmi (bukan auto-close).

**Cek admin 12 Nov 19.15 WIB** (Super Admin “Administrator”, **read-only**: tidak ada write, backup tidak dijalankan/diunduh) menutup item yang sebelumnya menunggu panel:

- Form **Jendela presensi** sudah memuat grace 60 menit dan cutoff 23:59 WIB — **W-09 Fixed.**
- Jarak minimal cuti di UI admin **juga 0**, selaras dengan API; akar W-06/A-04 adalah **konfigurasi**, bukan ketidaksesuaian kode. **Tetap Open** sampai kamu memastikan nilai yang dimaksud (disarankan 14 jika itu kebijakannya).
- Alur verifikasi checkout terlambat sudah ada di Monitoring Absensi (label “Perlu verifikasi” / “Checkout terlambat / perlu verifikasi”, tombol Verifikasi dan Koreksi). Label lama `AUTO_CHECKOUT` **tidak lagi** tampil di UI. W-01/W-02 tetap **Open (sebagian)** karena 13 sesi auto-checkout lama masih perlu tindakan admin.
- 7 akun admin aktif, 2 Super Admin (Administrator, DIAR) + 5 Admin. Administrator memakai `a•••@deresort.com` (bentuk `admin@` di domain perusahaan), tidak terhubung ke karyawan; sesi menyimpulkan ini **bukan** sisa akun default Artisan. Sandi default **tidak dicoba.** Tidak ada pengaturan kebijakan sandi (mendukung AUD-03).
- Backup hidup di SPA `#settings/backup` (halaman mandiri `/database-backup`, `/status`, `/safety` = 404). Ada unduh dump MySQL penuh (tanpa foto) dan **Restore** `.sql.gz` sampai 100 MB (konfirmasi ketik RESTORE). Ini menjadi temuan baru **AUD-09**. Kuota Cuti Reguler = 2 dan Deposit Off = 3 (default 12 dan 4) menjadi **INFO-01**, perlu konfirmasimu.
- IDOR antar peran Admin vs Super Admin **masih belum diverifikasi live** (menu tiap peran tidak dicek). W-03, W-04, W-05, W-07, W-08, W-10: **belum dites ulang (perlu sesi admin terpisah)**, status terakhir 11 Nov.

**Batas tes malam ini:** baca saja, kecuali unggah avatar SVG pada 110997 yang lalu dikembalikan. Sesi admin juga read-only. Tidak ada check-in palsu, tidak ada ganti password, tidak ada write ke karyawan lain, tidak ada brute force (tiga login gagal + satu login sukses pada 110997). Bug yang hanya bisa dibuktikan di emulator APK memakai status terakhir 11 Nov, lengkap dengan tanggalnya.

---

## 2. Review & uji coba

### 2.1 Retest keamanan (12 Nov 19.07–19.08 WIB)

| ID | Hasil singkat | Status |
|---|---|---|
| AUD-01 | Source masih mengunci throttle ke field `email`. Live: `remaining` selalu 4 (cache file flaky), sama seperti Fase 1 yang sudah membuktikan bypass. | **Open** |
| AUD-02 | Foto absensi dan avatar 110997 tetap 200 tanpa login, `Cache-Control: max-age=14400`. Listing folder 403. | **Open** |
| AUD-03 | Source: ganti password sendiri dan set admin masih tanpa `min:` ; default `password123` / `Admin123!` masih di kode. Live: `PATCH /api/v1/me` dengan sandi baru `1` dan current salah → 422 `INVALID_PASSWORD` (“tidak cocok”) — password **tidak** diubah. Default password tidak dicoba. Cek admin 19.15: 7 akun aktif, 2 Super Admin; tidak ada kebijakan sandi; akun Administrator `a•••@deresort.com` (bukan disimpulkan sebagai sisa Artisan). | **Open** (source) |
| AUD-04 | 54 rekan, 9 departemen, kode login ikut. Jadwal terbit emp 41 (11 sesi) dan emp 35 (12 sesi) terbaca dari 13–26 Nov. | **Open** |
| AUD-05 | Source masih percaya `is_mocked` dan koordinat klien. Jadwal 12 Nov 110997 kosong; tidak ada record absensi yang dibuat (hanya 422 validasi `latitude=999`). APK hanya menahan Fake GPS di klien. | **Open** (source) |
| AUD-06 | `sanctum.expiration` masih `null`. Token retest bekerja sampai logout (lalu 401). TTL tak terbatas tidak bisa dibuktikan tanpa menunggu. | **Open** (source) |
| AUD-07 | GET `/login`: tidak ada CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy. `X-Powered-By: PHP/8.4.27`, `X-App-Version: v0.1.8`. Cookie sesi tetap Secure, HttpOnly, SameSite=lax. | **Open** |
| AUD-08 | Unggah `image/svg+xml` lagi diterima, disimpan `.jpg` publik, GET tanpa login 200 `image/jpeg`, badan SVG mentah ada `<script>`, tanpa nosniff. HTML ditolak 422. Avatar asli dikembalikan (SHA-256 cocok). | **Open** |
| AUD-NV-02 | `X-Forwarded-For: 203.0.113.99` pada login gagal 110997: `remaining` tetap 4. Tidak meyakinkan. | **Belum dipastikan** (needs_validation) |

**SVG cache Cloudflare:** `/storage/avatars/avatar_53_1794480812.jpg` (Fase 2, ±17.53) masih **HIT**, `age=4424` (~73 menit), origin sudah 404 (query `?cb=`). Perkiraan kedaluwarsa **±21.53 WIB**. Retest malam menambah file serupa `/storage/avatars/avatar_53_1794485276.jpg` (origin 404, CF HIT, perkiraan ±23.08 WIB). **Perlu di-purge.** Path avatar lama `avatar_53_1793323443.jpg` juga masih HIT di CDN, origin 404.

**Backup tanpa login:** `GET /admin/api/database-backup`, `/status`, `/safety` tetap **401**. Bukan bug. Cek admin 19.15: backup hidup di SPA `#settings/backup` (halaman mandiri 404); unduh + restore tanpa 2FA dicatat di **AUD-09**.

### 2.2 Retest QA lewat API (tanpa emulator)

- **Jadwal 110997 (A-01), 11–15 Nov:** API karyawan benar — 11 Nov `COMPLETED` (QA TEST 20.00–22.00), 13 Nov `PUBLISHED` Shift khusus 08.00–12.00 & 17.00–21.00, **tidak ada** 12 Nov. Tab Jadwal APK belum di-retest hari ini; pada 11 Nov 22.12 picker izin “terlihat diperbaiki”.
- **Checkout opsi pertama (W-01, W-02):** Source `AttendanceWindow::checkoutDeadline` = `min(awal shift berikutnya, cutoff 23:59 hari akhir sesi)`. Sesi 110997 11 Nov: `checkout_grace_minutes=60`, `checkout_deadline_at=23.59 WIB`, checkout **23.38.29 WIB** diterima, `check_out_at` resmi, `provisional_out_at` kosong, `needs_review=true`, `review_reason=CHECKOUT_LATE`. Cek admin 19.15: form Jendela presensi menampilkan grace 60 dan cutoff 23:59 (selaras opsi pertama); Monitoring Absensi 11 Nov menampilkan 46 sesi (24 lengkap, 3 terlambat, 11 “Perlu Review”). Halaman 1 punya 9 baris “Perlu verifikasi” / “Checkout terlambat / perlu verifikasi” (6 Karaoke Shift II, 2 HK & Kitchen Shift II, plus baris QA TEST 2JAM milik Yoga Dev Test), masing-masing dengan tombol **Verifikasi** dan **Koreksi**. Label lama `AUTO_CHECKOUT` **tidak lagi** tampil di UI. Alur verifikasi checkout terlambat sudah ada; **13 sesi auto-checkout lama masih perlu tindakan admin.** Perilaku setelah cutoff tidak diuji (read-only, tidak menyentuh karyawan lain).
- **Cuti (W-06, A-04):** `GET /api/v1/me/leave-balance` → `minimum_notice_days=0`. Cek admin 19.15: form “Konfigurasi Saldo Tahunan” juga **0 hari**, dengan teks “isi 0 agar cuti dapat diajukan mulai hari ini.” UI dan API **selaras**; akar masalah adalah **konfigurasi**, bukan kode. Screenshot 11 Nov yang menampilkan 14 hari sudah usang. Form APK belum di-retest di emulator. Disarankan set ke 14 jika itu kebijakan yang dimaksud, lalu pastikan label UI tetap mengikuti.
- **Notifikasi (A-08):** Empat item masih ada, termasuk `leave_decision` dan `leave_cancelled` 4 Nov (cuti 1–3 Des: disetujui 14.59, dibatalkan 25 detik kemudian). Data cuti: id 15 `CANCELLED` setelah `approved_at`. **Masih terbuka.**
- **W-09 (form grace/cutoff):** **Fixed** — sudah ada di Pengaturan → Jendela presensi, bisa disimpan. Lihat [screenshots-admin-fase3/settings.png](screenshots-admin-fase3/settings.png).
- **W-03, W-04, W-05, W-07, W-08, W-10:** data cek admin malam ini **tidak mencakup** form/halaman itu. Status: **belum dites ulang (perlu sesi admin terpisah)**, memakai observasi terakhir **11 Nov**. W-04 sempat 200 untuk `q=keluar` pada 11 Nov 22.03 — belum dikonfirmasi ulang di UI hari ini.
- **Daftar user, backup, peran:** sudah dilihat; rincian di [bagian 5](#5-pembaruan-cek-admin-12-nov-1915-wib). IDOR Admin vs Super Admin masih belum diverifikasi live.

### 2.3 Bug hanya di emulator — status terakhir, dengan tanggal

A-02, A-03, A-05, A-06, A-07, A-09, B-19-01, B-19-02, B-19-03: tidak diulang 12 Nov (tidak ada emulator). Status = observasi terakhir **11 Nov 2026** (v0.1.8 siang / retest v0.1.9 malam, pukul 21.43–22.13 WIB). Source commit `a3b71fd` berjudul “Improve attendance actions and time picker feedback” — mungkin menyentuh B-19-01, belum terbukti di perangkat.

### 2.4 Perubahan data 110997 malam ini

- Perangkat `sec-audit-retest-20261112`: login lalu **logout (token 401)**.
- Tiga login gagal (throttle / XFF), password uji yang jelas salah, bukan tebakan.
- Avatar: unggah SVG uji (script hanya komentar) ke `avatar_53_1794485276.jpg`, lalu dikembalikan ke JPEG asli sebagai `avatar_53_1794485282.jpg` (SHA-256 cocok). Origin menghapus SVG; CF masih men-cache.
- **Tidak ada** record absensi, pengajuan, atau ganti password. Tidak ada write ke karyawan lain.
- Sesi browser admin 19.15 WIB **read-only**: tidak ada write, backup tidak dijalankan dan tidak diunduh, URL safety tidak dibuka.

---

## 3. Bukti / cuplikan

Retest 12 Nov: [evidence-retest-2026-11-12/](evidence-retest-2026-11-12/) — terutama `SUMMARY.json`, `aud07-headers.json`, `aud02-public-photos.json`, `aud01-nv02-throttle.json`, `aud04-colleagues-schedules.json`, `aud08-svg-retest.json`, `a01-me-schedule-11-15.json`, `attendance-history-110997.json`, `leave-balance.json`, `notifications-redacted.json`, `svg-leftover-cache.json`.

Audit siang/Fase 2: [evidence-security/](evidence-security/) (`fase2/`, `unauth/`, `employee/idor-summary.json`).

Screenshot web & APK 11 Nov: [screenshots/](screenshots/). Retest APK v0.1.9: [screenshots-apk-retest/](screenshots-apk-retest/) (`38-time-picker.png`, `43-time-applied.png`, `20-after-app-anr-wait.png`, `35-izin-sesi-full.png`).

Cek admin 12 Nov 19.15 WIB: [screenshots-admin-fase3/](screenshots-admin-fase3/) — [users.png](screenshots-admin-fase3/users.png) (daftar 7 akun), [backup.png](screenshots-admin-fase3/backup.png) (unduh + restore), [settings.png](screenshots-admin-fase3/settings.png) (jendela presensi), [cuti-config.png](screenshots-admin-fase3/cuti-config.png) (jarak minimal 0, kuota). Alamat email tampak di cuplikan asli; di narasi dilindungi.

---

## 4. Daftar bug (berdasarkan severity)

### Critical

#### W-01 — Sesi ditutup otomatis setelah grace sehingga karyawan tidak bisa absen pulang
- **Area:** Web/Admin / API · **Severity:** Critical · **Status hari ini:** **Open (sebagian diperbaiki)**
- **Langkah / bukti:** Pada 11 Nov ±18.11, 13 sesi `AUTO_CHECKOUT` / `NEEDS_REVIEW` setelah grace 60 menit (rules v3). Keputusanmu (opsi pertama): terima checkout sampai shift berikutnya atau cutoff akhir hari, simpan jam asli, tandai “checkout terlambat / perlu verifikasi”, jangan kunci tombol pulang. **Retest API 12 Nov:** source sudah `checkoutDeadline = min(awal shift berikutnya, cutoff 23:59 hari akhir sesi)`. Sesi 110997 11 Nov: deadline 23.59, checkout 23.38.29 diterima, `check_out_at` resmi, `review_reason=CHECKOUT_LATE`, `needs_review=true`, `provisional_out_at` kosong. Bukti: [evidence-retest-2026-11-12/a01-me-schedule-11-15.json](evidence-retest-2026-11-12/a01-me-schedule-11-15.json), [attendance-history-110997.json](evidence-retest-2026-11-12/attendance-history-110997.json). `AutoCheckoutAttendance` masih di source. **Cek admin 19.15 WIB:** form Jendela presensi menampilkan grace 60 menit dan “Batas akhir hari (WIB)” 11:59 PM, dengan teks “Check-out tersedia sampai shift berikutnya atau batas akhir hari, mana yang lebih dulu.” Monitoring Absensi 11 Nov: 46 sesi (24 lengkap, 3 terlambat, 11 “Perlu Review”). Halaman 1: 9 baris berlabel “Perlu verifikasi” / “Checkout terlambat / perlu verifikasi” (6 Karaoke Shift II, 2 HK & Kitchen Shift II, plus QA TEST 2JAM milik Yoga Dev Test), masing-masing dengan tombol **Verifikasi** dan **Koreksi**. Label lama `AUTO_CHECKOUT` **tidak lagi** tampil di UI. **Alur verifikasi checkout terlambat sudah ada di admin.** 13 sesi auto-checkout lama masih perlu tindakan admin. Perilaku setelah cutoff tidak diuji malam ini.
- **Dampak:** Jika auto-close setelah deadline baru masih mengunci pulang, karyawan tetap tidak bisa menutup sesi sendiri. Sebagian keluhan 11 Nov sudah tertangani di jalur `CHECKOUT_LATE` + antrian verifikasi admin.
- **Rekomendasi:** Rampungkan opsi pertama: (1) pastikan auto-close **hanya** setelah deadline baru, tidak setelah grace; (2) setelah auto-close, event checkout yang datang tetap bisa dipulihkan ke `check_out_at` resmi (lihat `recoverAuto` di `RecordAttendance`); (3) tinjau dan selesaikan 13 sesi 11 Nov yang masih menggantung (verifikasi atau koreksi di admin); (4) form grace/cutoff sudah ada (W-09 Fixed) — pertahankan. Cuplikan yang sudah ada dan perlu dipertahankan:

```php
// AttendanceWindow::checkoutDeadline — sudah sesuai opsi pertama
$cutoff = WorkTime::at(WorkTime::local($session->end_at)->toDateString(), $setting->checkout_cutoff_time ?? '23:59');
$nextStart = /* shift berikutnya karyawan yang sama, bukan DRAFT/CANCELLED */;
return $nextStart ? $cutoff->min(CarbonImmutable::parse($nextStart, 'UTC')) : $cutoff;
```

Jangan isi `provisional_out_at` sebagai pengganti jam pulang, dan jangan set `review_reason=AUTO_CHECKOUT` selama `now < deadline`.

---

### High

#### AUD-01 — Rate limit login bisa dihindari lewat field `email`
- **Area:** Security / API · **Severity:** High · **Status hari ini:** **Open**
- **Langkah / bukti:** Source `AppServiceProvider.php:22-23` masih `Limit::perMinute(5)->by(Str::lower($request->input('email')).'|'.$request->ip())`. Fase 1 (12 Nov 15.29–15.35): `remaining` 3 → 4 setelah `email` dummy. Retest malam: tiga gagal pada 110997, `remaining` selalu 4 (cache file flaky) — tidak meniadakan bukti Fase 1. [evidence-retest-2026-11-12/aud01-nv02-throttle.json](evidence-retest-2026-11-12/aud01-nv02-throttle.json), [evidence-security/](evidence-security/).
- **Dampak:** Kontrol anti-tebak password tidak terikat ke username/kode yang benar-benar dipakai. Gabungan dengan direktori kode (AUD-04) memudahkan stuffing.
- **Rekomendasi:**

```php
RateLimiter::for('login', function (Request $request) {
    $id = Str::lower((string) ($request->input('username') ?: $request->input('email') ?: ''));
    return [
        Limit::perMinute(5)->by($id.'|'.$request->ip()),
        Limit::perMinute(20)->by($request->ip()), // batas kasar per IP
    ];
});
```

Pindahkan counter ke Redis (`CACHE_STORE`), bukan `file`.

#### AUD-02 — Foto absensi dan avatar bisa diunduh tanpa login
- **Area:** Security / API · **Severity:** High · **Status hari ini:** **Open**
- **Langkah / bukti:** Retest: `GET` tanpa auth `att_52_1794403881_1kV7kT.jpg` (88387 bita) dan `att_52_1794415109_yNWMQj.jpg` (101105 bita) 200 `image/jpeg`; avatar terkini juga 200, 1.123.030 bita, `max-age=14400`. Listing 403. [aud02-public-photos.json](evidence-retest-2026-11-12/aud02-public-photos.json). CDN masih menyajikan file yang sudah dihapus di origin.
- **Dampak:** Foto wajah (biometrik + PII tempat kerja) tanpa kontrol akses, tertahan di CDN 4 jam setelah dihapus.
- **Rekomendasi:** Pindahkan ke disk privat, sajikan lewat controller berotorisasi (pola `AttendanceAttachmentController`). Cache pendek atau `private, no-store`. Purge Cloudflare untuk path yang sudah tidak ada.

#### AUD-03 — Password tanpa batas minimum, plus dua default di source
- **Area:** Security / API · **Severity:** High · **Status hari ini:** **Open** (source; default tidak dicoba di production)
- **Langkah / bukti:** `AuthController` ganti password: `'password' => ['nullable', 'string', 'confirmed']` tanpa `min:`. Impor `password123`, `admin:create` default `admin@deresort.com` / `Admin123!`. Retest API: `PATCH /api/v1/me` sandi baru pendek + current salah → 422, password tidak berubah. [aud03-password-min.json](evidence-retest-2026-11-12/aud03-password-min.json). **Cek admin 19.15:** 7 akun, semua Aktif — 2 Super Admin (Administrator, DIAR) dan 5 Admin (DAVID, ENDANG, febriya, FINCE, REZA; semua Gmail, terhubung ke karyawan). Administrator: Super Admin, email `a•••@deresort.com` (bentuk `admin@` di domain perusahaan), **Belum terhubung** ke karyawan. Sesi admin menyimpulkan ini **bukan** sisa akun default Artisan; nama dan pola email **cocok** dengan `admin:create`. **Sandi default tidak dicoba.** Di pengaturan **tidak ada** kebijakan sandi sama sekali (mendukung temuan source). [screenshots-admin-fase3/users.png](screenshots-admin-fase3/users.png).
- **Dampak:** Sandi pendek atau default yang diketahui bisa membuka akun karyawan atau `super_admin`.
- **Rekomendasi:** Satu aturan `min:8` (lebih baik plus kompleksitas) di semua path. Impor wajib isi password (gagal jika kosong). `admin:create` wajib argumen, tanpa default. Pastikan sandi Administrator sudah dirotasi (default source masih `Admin123!` — tidak dicoba malam ini). Aktifkan **2FA untuk kedua Super Admin**. Tambah pengaturan kebijakan sandi di admin.

```php
'password' => ['required', 'string', 'confirmed', 'min:8', 'max:255'],
```

#### W-02 — Event checkout terlambat tidak dijadikan `check_out_at` resmi
- **Area:** Web/Admin / API · **Severity:** High · **Status hari ini:** **Open (sebagian diperbaiki)**
- **Langkah / bukti:** 11 Nov, beberapa event setelah AUTO_CHECKOUT tercatat di jejak tetapi `check_out_at` null. Retest 110997 11 Nov: checkout 23.38 **memang** jadi `check_out_at` resmi + `CHECKOUT_LATE`. **Cek admin 19.15:** alur “Checkout terlambat / perlu verifikasi” sudah ada di Monitoring (tombol Verifikasi dan Koreksi); label `AUTO_CHECKOUT` tidak lagi tampil. 13 sesi auto-checkout lama masih perlu tindakan admin (verifikasi/koreksi atau migrasi `check_out_at` dari jejak). Kasus “setelah auto-close / setelah cutoff” tidak diuji malam ini. Silang W-01.
- **Dampak:** Jam pulang nyata hilang dari record resmi, payroll/kepatuhan salah, karyawan terlihat belum pulang.
- **Rekomendasi:** Jika event checkout valid datang setelah auto-close, pulihkan ke `check_out_at` (kode `recoverAuto` sudah ada — pastikan mencakup semua alasan, dan jalankan migrasi sekali untuk jejak 11 Nov yang `check_out_server_received_at` tanpa `check_out_at`).

#### W-03 — Izin Keluar Sementara / Pulang Lebih Awal dilabeli “Izin Terlambat” di Riwayat
- **Area:** Web/Admin · **Severity:** High · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **Open, 11 Nov 2026**. Cek admin 19.15 tidak membuka Riwayat Persetujuan / filter izin.
- **Langkah / bukti:** 11 Nov, antrian pending benar, Riwayat salah label + filter kacau. Screenshot: [screenshots/izin-riwayat-citra-label-izin-terlambat.png](screenshots/izin-riwayat-citra-label-izin-terlambat.png), [izin-pending-keluar-sementara.png](screenshots/izin-pending-keluar-sementara.png). Retest 11 Nov 22.03: petunjuk samping FINCE sudah “Izin Keluar Sementara” — belum dikonfirmasi untuk semua baris. Tidak diuji 12 Nov.
- **Dampak:** Admin salah filter/keputusan; riwayat tidak bisa diaudit.
- **Rekomendasi:** Satu enum/label dari antrian, riwayat, API, dan APK (`temporary_exit`, `early_departure`, `late_arrival`). Filter Riwayat pakai jenis tersimpan, bukan substring label.

#### W-04 — Pencarian “keluar” di Riwayat Persetujuan HTTP 500
- **Area:** Web/Admin · **Severity:** High · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **petunjuk mereda (API 200), 11 Nov 2026 22.03**. Cek admin 19.15 tidak membuka Riwayat Persetujuan.
- **Langkah / bukti:** QA awal: 500 saat cari “keluar”. Retest admin 11 Nov 22.03: `GET /admin/api/workflows/history?q=keluar` **200** (3 baris) — petunjuk mereda, belum dikonfirmasi ulang di UI 12 Nov.
- **Dampak:** Jika 500 masih ada, riwayat izin tidak bisa dicari.
- **Rekomendasi:** Periksa query (kemungkinan SQL/JSON exception pada karakter atau join jenis izin). Tambah tes otomatis `q=keluar`, `q=terlambat`. Jangan biarkan 500 ke klien — tangkap dan log.

#### W-05 — Tidak ada log event absensi di admin (jejak gagal di perangkat hilang)
- **Area:** Web/Admin · **Severity:** High · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **Open, 11 Nov 2026**. Terkait A-03 (emulator/perangkat Oky). Cek admin 19.15 melihat Monitoring Absensi (status sesi, Verifikasi/Koreksi) tetapi **tidak** membuka log event mentah per sesi.
- **Langkah / bukti:** Kasus Oky 11 Nov: “2 event belum selesai” di APK, server `ABSENT` kosong. Admin tidak punya log payload (observasi 11 Nov). Silang A-03, [INVESTIGATION checkout](../qa-deresort-checkout/INVESTIGATION-checkout-failures-2026-11-11.md) (read-only 11 Nov).
- **Dampak:** Gagal sinkron tidak bisa ditelusuri atau di-retry dari kantor.
- **Rekomendasi:** Tampilkan event mentah per sesi di Monitoring (sudah ada route `attendance/{session}/events` di source — pastikan UI memakainya, termasuk yang `REJECTED`). Receipt jelas di APK jika ditolak.

#### W-06 — Jarak minimal cuti di-set 0 (UI dan API selaras); kebijakan yang dimaksud kemungkinan 14 hari
- **Area:** Web/Admin / API · **Severity:** High · **Status hari ini:** **Open** (konfigurasi, bukan bug kode) — 12 Nov 19.15
- **Langkah / bukti:** API [leave-balance.json](evidence-retest-2026-11-12/leave-balance.json) `minimum_notice_days=0`. **Cek admin 19.15:** Cuti → Konfigurasi Saldo Tahunan, field “Jarak minimal pengajuan” = **0**, teks bantuan “Berlaku untuk semua tahunan, isi 0 agar cuti dapat diajukan mulai hari ini.” UI dan API **selaras**. Screenshot 11 Nov yang menampilkan 14 hari ([screenshots/cuti-admin-jarak-minimal-14-hari.png](screenshots/cuti-admin-jarak-minimal-14-hari.png)) sudah usang. Cuplikan baru: [screenshots-admin-fase3/cuti-config.png](screenshots-admin-fase3/cuti-config.png). Silang A-04. Jika 0 memang disengaja, item ini bisa ditutup.
- **Dampak:** Jika kebijakan yang dimaksud adalah 14 hari, karyawan bisa mengajukan cuti untuk hari ini. Jika 0 disengaja, tidak ada ketidaksesuaian UI/API.
- **Rekomendasi:** Set `minimum_notice_days` (dan field “Jarak minimal pengajuan”) ke **14** jika itu kebijakan yang dimaksud, lalu pastikan label UI, validasi API, dan form APK tetap mengikuti nilai yang sama (`SiteSetting::leave_minimum_notice_days`). Jangan hardcode 14 di salah satu permukaan.

#### A-01 — Jadwal 12 Nov fiktif (duplikat), 13 Nov terbit hilang di APK
- **Area:** APK / API · **Severity:** High · **Status hari ini:** **Sebagian diperbaiki / tab Jadwal belum dites ulang** (emulator terakhir 11 Nov 22.12)
- **Langkah / bukti:** 11 Nov v0.1.8: APK tampil dua kartu 12 Nov “Terbit” (08–18 & 19–22, pola draft split) dan 13 Nov hilang; admin API hanya 11 Nov tes + 13 Nov khusus terbit. Retest v0.1.9 11 Nov 22.12: picker izin **terlihat diperbaiki** (tidak ada 12 Nov, 13 Nov ada). **API karyawan 12 Nov 19.07:** 11 Nov COMPLETED, 13 Nov PUBLISHED 08–12 & 17–21, tidak ada 12 Nov. [a01-me-schedule-11-15.json](evidence-retest-2026-11-12/a01-me-schedule-11-15.json). Tab Jadwal APK tidak sempat di-retest 11 Nov malam dan tidak diuji 12 Nov.
- **Dampak:** Karyawan bisa ajukan izin / lihat sesi yang tidak ada, atau kehilangan shift yang sudah terbit.
- **Rekomendasi:** Tab Jadwal dan pemilih sesi memakai `GET /api/v1/me/schedule` saja, hanya `PUBLISHED`/`COMPLETED`, jangan cache draft sebagai Terbit. Uji ulang tab Jadwal di APK 0.1.9.

#### A-02 — Tab bar menutupi tombol penting; panel tidak sampai CTA bawah
- **Area:** APK · **Severity:** High · **Status hari ini:** **Belum dites ulang** — terakhir **Open, 11 Nov 2026** (v0.1.9 22.12: “Belum diperbaiki”, form izin masih tertutup tab bar)
- **Langkah / bukti:** [screenshots/apk-46-profil.png](screenshots/apk-46-profil.png), [apk-18-izin-types.png](screenshots/apk-18-izin-types.png), [screenshots-apk-retest/32-pengajuan.png](screenshots-apk-retest/32-pengajuan.png). Logout, simpan sandi, jenis izin, check-in beranda tertutup.
- **Dampak:** Fitur kritikal tidak tertap; logout pun sulit.
- **Rekomendasi:** `contentInset` / padding bawah = tinggi tab bar + safe area di semua tab dan modal. CTA harus di atas tab bar, target sentuh ≥48 dp. Uji di perangkat fisik, bukan hanya emulator.

#### A-03 — Event outbox “belum selesai” tanpa jejak di admin (kasus Oky)
- **Area:** APK / Admin · **Severity:** High · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026** (read-only, perangkat Oky tidak diakses lagi). Silang W-05.
- **Langkah / bukti:** Sesi 08–16 “Belum absen”, Profil “2 event belum selesai”, server `ABSENT`. String APK: outbox + `OFFLINE_ATTENDANCE_DISABLED`.
- **Dampak:** Presensi hilang tanpa alasan yang bisa dilihat admin.
- **Rekomendasi:** Jangan biarkan outbox menggantung tanpa receipt. Jika ditolak, tampilkan `reason_code` di APK dan di admin. Retry tidak boleh mengganti `effective_at` server (sudah benar: server time).

#### A-04 — Jarak minimal cuti 14 hari tidak ditegakkan di form APK
- **Area:** APK / API · **Severity:** High · **Status hari ini:** **Open** (nilai produksi = 0, konfigurasi — silang W-06). Form APK **belum dites ulang** di emulator hari ini (terakhir 11 Nov, cuti 15 Nov hanya dipilih, tidak dikirim).
- **Langkah / bukti:** Silang W-06. `minimum_notice_days=0` di leave-balance dan di form admin 19.15. Akar: konfigurasi, bukan ketidaksesuaian API vs UI admin.
- **Dampak:** Jika kebijakan diubah ke 14 hari, form APK yang hardcode atau tidak membaca API bisa tetap mengizinkan (atau menolak) tanggal yang salah.
- **Rekomendasi:** Form APK baca `minimum_notice_days` dari API dan validasi yang sama. Jangan hardcode 14. Setelah nilai admin di-set ke kebijakan yang dimaksud, uji ulang form APK.

#### B-19-01 — Time picker Izin Keluar Sementara: “Terapkan” tidak mengisi jam keluar
- **Area:** APK · **Severity:** High · **Status hari ini:** **Belum dites ulang** — terakhir **Open, 11 Nov 2026 21.43–22.13 WIB** (v0.1.9)
- **Langkah / bukti:** [screenshots-apk-retest/38-time-picker.png](screenshots-apk-retest/38-time-picker.png), [43-time-applied.png](screenshots-apk-retest/43-time-applied.png). “Terapkan” (ketuk kedua) menutup modal, field “Rencana jam keluar” kosong; pengajuan tidak bisa dikirim. Direpro 21.50 dan 22.00. Source `a3b71fd` menyebut perbaikan time picker — **belum terbukti** di perangkat. Bundle APK Drive 12 Nov berubah di salinan UI izin, juga belum diuji di emulator hari ini.
- **Dampak:** Izin Keluar Sementara tidak bisa diajukan dari aplikasi.
- **Rekomendasi:** Pastikan `onApply` menulis state jam (bukan hanya menutup modal). Uji: pilih jam → Terapkan → field terisi → kirim. Jangan butuh ketuk dua kali. Uji di HP fisik.

---

### Medium

#### AUD-04 — Semua karyawan melihat direktori lengkap dan jadwal rekan
- **Area:** Security / API · **Severity:** Medium · **Status hari ini:** **Open**
- **Langkah / bukti:** Retest: 54 rekan, 9 dept, termasuk kode yang dipakai username. `employee-schedules?employee_id=41` 11 PUBLISHED, emp 35 12 PUBLISHED (13–26 Nov). [aud04-colleagues-schedules.json](evidence-retest-2026-11-12/aud04-colleagues-schedules.json). Draf di source ikut jika ada (tidak ada di jendela ini).
- **Dampak:** 54 kode login + nama + jadwal orang lain — memudahkan stuffing (AUD-01) dan bocor rencana operasional.
- **Rekomendasi:** Scope rekan (satu departemen / atasan-bawahan). Jangan kirim `code`. Jadwal hanya `PUBLISHED`, milik rekan yang relevan untuk tukar shift.

#### AUD-05 — Geofence dan Fake GPS percaya data klien
- **Area:** Security / API / APK · **Severity:** Medium · **Status hari ini:** **Open** (source; tidak ada write absensi 12 Nov)
- **Langkah / bukti:** `RecordAttendance::checkLocation` memakai `is_mocked` dan lat/lng dari request. APK menahan `position.mocked` lalu mengirim flag yang sama. Retest: 12 Nov tanpa sesi, hanya 422 validasi. [aud05-gps.json](evidence-retest-2026-11-12/aud05-gps.json).
- **Dampak:** Karyawan bisa absen “di lokasi” dari mana saja lewat API mentah.
- **Rekomendasi:** Jangan biarkan klien mematikan deteksi mock. Perlakukan lokasi sebagai sinyal yang bisa di-review, atau pakai attestation (Play Integrity) di perangkat. `effective_at = now()` sudah benar — pertahankan.

#### AUD-06 — Token Sanctum tidak pernah kedaluwarsa
- **Area:** Security / API · **Severity:** Medium · **Status hari ini:** **Open** (source)
- **Langkah / bukti:** `config/sanctum.php` `expiration => null`. Token retest hidup sampai logout. [aud06-token.json](evidence-retest-2026-11-12/aud06-token.json). APK menyimpan di SecureStore (sudah baik).
- **Dampak:** Token curian = sesi karyawan tanpa batas waktu.
- **Rekomendasi:** `'expiration' => 60 * 24 * 7` (atau 12 jam). Refresh di aplikasi. Cabut token lama pada ganti password / nonaktif.

#### AUD-08 — Upload avatar menerima `image/*` termasuk SVG (dulu AUD-NV-01)
- **Area:** Security / API · **Severity:** Medium · **Status hari ini:** **Open**
- **Langkah / bukti:** Retest 19.07: `POST /api/v1/me/avatar` `image/svg+xml` → 200, path `/storage/avatars/avatar_53_1794485276.jpg`, GET tanpa login 200 `image/jpeg`, badan SVG + `<script>`, tanpa nosniff, `max-age=14400`. HTML 422. Avatar asli dikembalikan (SHA cocok, path baru `avatar_53_1794485282.jpg`). CF masih HIT pada SVG. [aud08-svg-retest.json](evidence-retest-2026-11-12/aud08-svg-retest.json). XSS tersimpan **tidak** terbukti di browser modern (karena `image/jpeg`).
- **Dampak:** Payload publik di origin aplikasi, tertahan di CDN 4 jam setelah dihapus.
- **Rekomendasi:**

```php
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (! in_array($mime, $allowed, true)) {
    return response()->json(['code' => 'INVALID_IMAGE_TYPE', 'message' => 'Format file harus JPG, PNG, atau WEBP.'], 422);
}
// Re-encode lewat GD seperti foto absensi; jangan simpan bita mentah.
```

Pasang `X-Content-Type-Options: nosniff`. Purge `/storage/avatars/avatar_53_1794480812.jpg` dan `avatar_53_1794485276.jpg`.

#### AUD-09 — Unduh backup dan restore database tanpa re-autentikasi atau 2FA
- **Area:** Security / Admin · **Severity:** Medium · **Status hari ini:** **Open** (ditemukan cek admin 12 Nov 19.15, read-only)
- **Langkah / bukti:** Halaman mandiri `/database-backup`, `/status`, `/safety` mengembalikan **404**. Backup hidup di SPA `#settings/backup`. Super Admin “Administrator” melihat “Unduh backup database” (dump MySQL penuh, foto/lampiran tidak termasuk) dan “Restore dari file” (`.sql.gz` sampai 100 MB, tombol aktif setelah mengetik **RESTORE**). Teks UI: “Semua tabel dan data saat ini akan diganti. Admin dan API karyawan dapat digunakan selama pemulihan.” Halaman safety menyatakan belum ada backup pengaman; URL unduh safety `/admin/api/database-backup/safety` **tidak dibuka**. Backup tidak dijalankan dan tidak diunduh. Tanpa login, API tetap 401 (12 Nov 19.08). Source sudah `Gate::super-admin`; **menu dan API untuk peran Admin biasa tidak dicek** (IDOR antar peran belum diverifikasi live). Tidak ada langkah re-login atau 2FA sebelum unduh/restore. Cuplikan: [screenshots-admin-fase3/backup.png](screenshots-admin-fase3/backup.png).
- **Dampak:** Sesi Super Admin yang tercuri (atau, jika IDOR terbuka, sesi Admin biasa) cukup untuk mengunduh seluruh basis data atau menimpa produksi lewat restore, tanpa konfirmasi identitas ulang.
- **Rekomendasi:** (1) Batasi unduh dan restore **hanya Super Admin** — pastikan Gate berlaku di API dan menu tidak tampil untuk Admin biasa (perlu tes login sebagai Admin). (2) Wajibkan **re-autentikasi atau 2FA** sebelum unduh dan sebelum restore. (3) Catat setiap unduh/restore di **audit log** (siapa, kapan, dari mana, nama file). (4) Pertimbangkan **menghapus restore dari production**; restore hanya di staging, atau lewat prosedur terpisah di luar UI. (5) Aktifkan **2FA untuk kedua Super Admin** (Administrator dan DIAR).

#### W-07 — Teks batas split khusus tidak selaras dengan validasi; skema 13 jam tersimpan draft
- **Area:** Web/Admin · **Severity:** Medium · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **Open, 11 Nov 2026**, [screenshots/split-form-teks-16jam-24jam.png](screenshots/split-form-teks-16jam-24jam.png). Cek admin 19.15 tidak membuka form split/jadwal.
- **Dampak:** Admin bingung; draft “terlarang” tetap ada dan pernah bocor ke APK sebagai Terbit (A-01).
- **Rekomendasi:** Samakan teks, validasi, dan batas simpan (16/24 atau 12/13 — pilih satu). Tolak simpan yang melebihi, jangan simpan draft diam-diam.

#### W-08 — Baris absensi hantu setelah jadwal direvisi
- **Area:** Web/Admin · **Severity:** Medium · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **Open, 11 Nov 2026**. Contoh Iqbal 11 Nov att 1089 + hantu 670, tidak disentuh lagi. Cek admin 19.15 melihat Monitoring 11 Nov (status “Perlu Review” / Verifikasi) tetapi tidak menelusuri baris hantu secara khusus.
- **Dampak:** Monitoring ganda, riskan salah hitung.
- **Rekomendasi:** Saat revisi/terbit ulang, retired-kan sesi lama dan jangan sisakan `AttendanceRecord` tanpa sesi aktif. Job pembersih baris yatim. Jangan migrasi hantu sebagai checkout (catatan W-01).

#### W-09 — Grace dan deadline checkout tidak bisa diatur di pengaturan admin
- **Area:** Web/Admin · **Severity:** Medium · **Status hari ini:** **Fixed** (12 Nov 19.15 WIB)
- **Langkah / bukti:** Source `SiteSettingController::attendance` sudah mengembalikan dan menerima `checkout_grace_minutes` dan `checkout_cutoff_time`. **Cek admin 19.15:** Pengaturan → **Jendela presensi** menampilkan “Check-in lebih awal” = 180 menit, “Grace setelah akhir shift (menit)” = **60**, “Batas akhir hari (WIB)” = **11:59 PM**, tombol **Simpan perubahan**, dan teks “Check-out tersedia sampai shift berikutnya atau batas akhir hari, mana yang lebih dulu” — sesuai opsi pertama. Cuplikan: [screenshots-admin-fase3/settings.png](screenshots-admin-fase3/settings.png). (Nilai tidak diubah; sesi read-only.)
- **Dampak (sebelum perbaikan):** Kebijakan pulang hanya bisa diubah dari backend/DB. **Sudah tidak berlaku.**
- **Rekomendasi:** Tidak ada pekerjaan sisa pada form. Pertahankan field dan teks bantuan. Perubahan nilai tetap perlu audit log jika kebijakan pulang disesuaikan.

#### A-05 — Izin lokasi tidak diminta di awal alur check-in
- **Area:** APK · **Severity:** Medium · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026** (v0.1.9: “tidak ada prompt”, izin sudah di-grant ulang)
- **Rekomendasi:** `requestForegroundPermissionsAsync` sebelum kamera/selfie, dengan penjelasan bahasa Indonesia. Jangan andalkan `pm grant` QA.

#### A-06 — Check-in bergantung Fused Location; error teknis berbahasa Inggris
- **Area:** APK · **Severity:** Medium · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026**
- **Langkah / bukti:** Pesan Play Services timeout bahasa Inggris. [screenshots/apk-41-outside-after-wait.png](screenshots/apk-41-outside-after-wait.png).
- **Rekomendasi:** Fallback LocationManager / last-known dengan batas usia. Pesan Indonesia: “Lokasi belum didapat. Aktifkan GPS dan coba lagi.” Jangan tampilkan kode Google 20 ke pengguna.

#### A-07 — ANR “Process system isn't responding” saat form izin
- **Area:** APK · **Severity:** Medium · **Status hari ini:** **Belum dites ulang** — terakhir **Open, 11 Nov 2026**. Terkait B-19-03 (ANR di login v0.1.9).
- **Rekomendasi:** Profil main-thread (I/O, IME). Uji di HP fisik. Jangan biarkan dialog ANR memutus alur izin.

#### A-08 — Notifikasi cuti 4 Nov: 1 Desember disetujui dan sekaligus dibatalkan
- **Area:** APK / API · **Severity:** Medium · **Status hari ini:** **Open** (data masih ada, 12 Nov 19.07)
- **Langkah / bukti:** Notifikasi `leave_decision` 14.59 dan `leave_cancelled` 14.59:45 untuk 1–3 Des; cuti id 15 `CANCELLED` setelah `approved_at`. [notifications-redacted.json](evidence-retest-2026-11-12/notifications-redacted.json), [leaves-110997-redacted.json](evidence-retest-2026-11-12/leaves-110997-redacted.json).
- **Dampak:** Karyawan melihat status bertentangan.
- **Rekomendasi:** Saat pembatalan setelah setuju, tampilkan satu notifikasi “disetujui lalu dibatalkan”, atau arsipkan/supersede notifikasi setuju. Urutan di UI: status akhir di atas, dengan riwayat.

#### B-19-02 — Setelah `adb install -r`, sesi login tidak dipertahankan
- **Area:** APK · **Severity:** Medium · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026 20.47+ WIB** (v0.1.9). [screenshots-apk-retest/01-after-upgrade.png](screenshots-apk-retest/01-after-upgrade.png)
- **Dampak:** Setiap update APK (tanda tangan sama) memaksa login ulang. Token di SecureStore seharusnya bertahan jika kunci storage tidak berubah.
- **Rekomendasi:** Pastikan `auth-token` SecureStore tidak terhapus saat upgrade. Uji `adb install -r` di HP fisik: sesi tetap atau tampil alasan yang jelas.

#### B-19-03 — ANR aplikasi di layar login saat input (IME)
- **Area:** APK · **Severity:** Medium · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026 21.30–21.33 WIB**. [screenshots-apk-retest/20-after-app-anr-wait.png](screenshots-apk-retest/20-after-app-anr-wait.png)
- **Dampak:** Login terhambat; risiko “Close app” jika pengguna salah ketuk. Diperparah emulator software, tapi dialog muncul di paket aplikasi.
- **Rekomendasi:** Uji IME di HP fisik. Hindari kerja berat di main thread saat `TextInput`. Jika hanya lingkungan QA, catat sebagai batasan emulator — tetap selidiki karena paket aplikasi yang di-ANR.

---

### Low

#### AUD-07 — Header keamanan browser tidak diset, versi PHP diumumkan
- **Area:** Security / Web · **Severity:** Low · **Status hari ini:** **Open**
- **Langkah / bukti:** [aud07-headers.json](evidence-retest-2026-11-12/aud07-headers.json). Cookie flags sudah benar.
- **Dampak:** Memudahkan framing, kebocoran Referrer URL foto (AUD-02), dan enumerasi stack. Sendiri tidak membobol data.
- **Rekomendasi:** Middleware atau config edge:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; ...
X-Content-Type-Options: nosniff
Referrer-Policy: same-origin
```

Matikan `expose_php` / `X-Powered-By`. `nosniff` juga meredam risiko AUD-08.

#### W-10 — Simpan jadwal yang jam mulainya sudah lewat ditolak
- **Area:** Web/Admin · **Severity:** Low · **Status hari ini:** **Belum dites ulang (perlu sesi admin terpisah)** — terakhir **11 Nov 2026**. Cek admin 19.15 read-only dan tidak membuka form simpan jadwal (itu write admin).
- **Dampak:** Admin tidak bisa merekam split/koreksi untuk sesi yang sudah berjalan. Mungkin disengaja.
- **Rekomendasi:** Tegaskan aturan: tolak hanya untuk `PUBLISHED` ke masa lalu, atau izinkan draft + alasan. Tampilkan pesan yang jelas, bukan gagal diam-diam.

#### A-09 — Tidak ada penjelasan jika kamera depan tidak tersedia
- **Area:** APK · **Severity:** Low · **Status hari ini:** **Belum dites ulang** — terakhir **11 Nov 2026** (AVD tanpa kamera depan)
- **Rekomendasi:** Jika kamera depan gagal, beri tahu “Kamera depan tidak tersedia, memakai kamera belakang” dan izinkan pilih manual.

---

### Informasi (perlu konfirmasi, tidak masuk hitung severity)

#### INFO-01 — Kuota Cuti Reguler = 2 dan Deposit Off = 3, berbeda dari default 12 dan 4
- **Area:** Web/Admin · **Severity:** Info · **Status hari ini:** **Perlu konfirmasi Yoga** (dilihat 12 Nov 19.15)
- **Langkah / bukti:** Cuti → Konfigurasi Saldo Tahunan: “Cuti Reguler” = **2** (teks “Default baru: 12 hari/tahun”), “Deposit Off” = **3** (teks “Default baru: 4 hari/tahun”). Tahun saldo 2026 sudah dibuka 29/10/2026; 55 karyawan dengan kuota, 14 cuti disetujui, 0 pending. [screenshots-admin-fase3/cuti-config.png](screenshots-admin-fase3/cuti-config.png).
- **Dampak:** Jika ini tidak disengaja, jatah cuti karyawan jauh di bawah default sistem.
- **Rekomendasi:** Mohon konfirmasi apakah kuota 2 dan 3 memang disengaja untuk 2026. Jika tidak, kembalikan ke nilai yang dimaksud dan dokumentasikan alasan jika berbeda dari default.

---

### Needs validation (tanpa severity tetap)

#### AUD-NV-02 — `TRUSTED_PROXIES=REMOTE_ADDR` bergantung pada topologi
- **Area:** Security / Deploy · **Severity:** — (needs_validation) · **Status hari ini:** **Belum dipastikan**
- **Langkah / bukti:** `bootstrap/app.php:41`, Dockerfile `TRUSTED_PROXIES=REMOTE_ADDR` (string literal; Laravel mengharapkan daftar IP atau `*`). Retest XFF pada 110997: `remaining` selalu 4, termasuk tanpa header. [aud01-nv02-throttle.json](evidence-retest-2026-11-12/aud01-nv02-throttle.json). Jangan flood.
- **Dampak (jika port terekspos langsung):** klien bisa memalsukan IP di kunci rate limit (AUD-01).
- **Rekomendasi:** Isi allow-list IP ingress (Cloudflare / Traefik) secara eksplisit, jangan string `REMOTE_ADDR`. Pastikan ingress menimpa `X-Forwarded-For` dan port kontainer tidak dipublish. Uji di staging yang topologinya kamu kendalikan.

---

### Bukan bug (tidak dihitung)

- **AUD-R-01** — Route `/api/v1/admin/*` di API karyawan: `Gate::authorize('admin')` + `visibleTo` menahan. Fase 1: karyawan hanya baris sendiri, `scope=admin` 403. Menambah middleware tetap bagus sebagai pertahanan berlapis.
- **Backup tamu:** `GET /admin/api/database-backup`, `/status`, `/safety` tetap **401** tanpa login (12 Nov 19.08). Halaman mandiri `/database-backup`, `/status`, `/safety` **404** saat login (hidup di SPA `#settings/backup`). Bukan bug. Risiko restore/unduh oleh admin yang sudah login dicatat di **AUD-09**. IDOR Admin vs Super Admin **masih belum diverifikasi live** (perlu sesi terpisah sebagai Admin biasa).
- CSRF admin (inspeksi Fase 2): `_token` ada, sesi `HttpOnly`+`Secure`+`SameSite=lax`, tidak ada pengecualian. Bukan temuan. Restore memakai konfirmasi ketik RESTORE (bukan pengganti CSRF).

---

## 5. Pembaruan cek admin 12 Nov 19.15 WIB

Sesi browser **read-only** sebagai Super Admin **Administrator**. Tidak ada data yang ditulis, backup tidak dijalankan dan tidak diunduh, URL safety tidak dibuka. Cuplikan: [users.png](screenshots-admin-fase3/users.png), [backup.png](screenshots-admin-fase3/backup.png), [settings.png](screenshots-admin-fase3/settings.png), [cuti-config.png](screenshots-admin-fase3/cuti-config.png). Alamat email di cuplikan asli dilindungi di narasi.

**Akun admin.** Ada **7**, semua Aktif. Hanya dua peran: Super Admin dan Admin.

- **Administrator** — Super Admin, email `a•••@deresort.com` (bentuk `admin@` di domain perusahaan), **Belum terhubung** ke karyawan. Sesi menyimpulkan ini **bukan** sisa akun default Artisan; nama dan pola email cocok dengan `admin:create`. Sandi default **tidak dicoba.**
- **DIAR** — Super Admin, terhubung ke karyawan.
- **DAVID, ENDANG, febriya, FINCE, REZA** — Admin, semua memakai Gmail, semua terhubung ke karyawan.

Menu yang bisa diakses masing-masing peran **tidak dicek**, jadi **IDOR Admin vs Super Admin masih belum diverifikasi live** — perlu sesi terpisah dengan login sebagai Admin biasa.

**Backup, status, safety.** `/database-backup`, `/status`, dan `/safety` sebagai halaman mandiri mengembalikan **404**. Backup hidup di SPA `#settings/backup`: “Unduh backup database” (dump MySQL penuh, tanpa foto) dan “Restore dari file” (`.sql.gz` sampai 100 MB, dikonfirmasi dengan mengetik RESTORE). Halaman safety menyatakan belum ada backup pengaman. URL unduh safety `/admin/api/database-backup/safety` tidak dibuka. Rekomendasi masuk **AUD-09**: batasi ke Super Admin saja, wajibkan re-autentikasi atau 2FA untuk unduh dan restore, tambah audit log, pertimbangkan menghapus restore dari production, dan aktifkan 2FA untuk kedua Super Admin.

**Pengaturan.**

- `minimum_notice_days` = **0**; UI menyatakan 0 memungkinkan pengajuan cuti untuk hari ini. Mengonfirmasi W-06/A-04, akarnya **konfigurasi**, bukan kode. Disarankan set ke 14 jika itu kebijakan yang dimaksud, dan pastikan label UI mengikuti.
- Kuota Cuti Reguler = **2** dan Deposit Off = **3**, terhadap default 12 dan 4 (**INFO-01**, mohon konfirmasi).
- Checkout grace **60 menit**, cutoff akhir hari **23:59 WIB**, selaras opsi pertama. Check-in dibuka **180 menit** sebelum sesi. Form ini menutup **W-09 sebagai Fixed**.
- Aturan kehadiran: satu aturan untuk semua — toleransi terlambat 0, cek lokasi aktif, bukti wajib, berlaku 28 Okt–31 Des 2026.
- **Tidak ada** pengaturan kebijakan sandi (mendukung AUD-03).

**Absensi 11 Nov.** 46 sesi: 24 lengkap, 3 terlambat, 11 “Perlu Review”. Halaman 1: 9 baris “Perlu verifikasi” / “Checkout terlambat / perlu verifikasi” (6 Karaoke Shift II, 2 HK & Kitchen Shift II, plus QA TEST 2JAM milik Yoga Dev Test). Setiap baris punya tombol Verifikasi dan Koreksi. Label lama `AUTO_CHECKOUT` **tidak lagi** tampil di UI. Alur verifikasi checkout terlambat sudah ada di admin; **13 sesi auto-checkout lama masih perlu tindakan admin** (W-01/W-02 tetap Open sebagian).

**Item yang sebelumnya “menunggu cek admin”:**

| Item | Status baru (19.15 WIB) |
|---|---|
| Daftar user / akun Artisan default | Dilihat. Bukan disimpulkan sebagai sisa Artisan; identitas mirip. Sandi tidak dicoba. |
| Halaman backup | Dilihat (SPA). Risiko unduh/restore → **AUD-09 Open**. |
| IDOR Admin vs Super Admin | **Belum diverifikasi live** (perlu sesi Admin biasa). |
| Kebijakan sandi | Tidak ada di pengaturan (AUD-03 Open). |
| W-01 / W-02 | Open (sebagian): alur verifikasi ada; 13 sesi lama masih perlu tindakan. |
| W-06 / A-04 | Open (konfigurasi = 0, UI dan API selaras). |
| W-09 | **Fixed** (form Jendela presensi lengkap). |
| W-03, W-04, W-05, W-07, W-08, W-10 | **Belum dites ulang (perlu sesi admin terpisah)**, status terakhir 11 Nov. |

---

## 6. Rencana perbaikan berprioritas

### Sprint 1 — segera (keamanan tinggi + checkout operasional)

1. **AUD-02** — Disk privat + route berotorisasi untuk foto/avatar; **purge CDN** (`avatar_53_1794480812.jpg` perkiraan kedaluwarsa ±21.53 WIB, `avatar_53_1794485276.jpg` ±23.08 WIB, dan `avatar_53_1793323443.jpg` jika sudah tidak dipakai). Jangan andalkan TTL saja.
2. **AUD-01** — Kunci throttle ke username/kode + batas per IP; Redis.
3. **AUD-03** — `min:8` semua path, hapus default impor/Artisan, pastikan sandi Administrator sudah dirotasi (tidak dicoba), **2FA untuk kedua Super Admin**, tambah kebijakan sandi di pengaturan.
4. **W-01 / W-02** — Rampungkan opsi pertama: auto-close hanya setelah deadline baru; pulihkan event terlambat; **tinjau/selesaikan 13 sesi auto-checkout 11 Nov** yang masih menggantung (alur Verifikasi/Koreksi sudah ada di admin).
5. **W-03 / W-04** — Label + filter izin konsisten; pastikan cari “keluar” tidak 500 (petunjuk 11 Nov 22.03 sudah 200). Perlu sesi admin terpisah untuk konfirmasi UI.
6. **AUD-09** — Batasi backup/restore ke Super Admin; re-auth atau 2FA sebelum unduh dan restore; audit log; pertimbangkan hapus restore dari production.

### Sprint 2 — minggu ini

7. **AUD-08** — Allow-list raster + re-encode GD + nosniff + cache pendek.
8. **AUD-04** — Scope direktori tukar shift, tanpa kode login, hanya jadwal terbit.
9. **AUD-05** — Jangan percaya `is_mocked` klien.
10. **W-06 / A-04** — Jika kebijakan yang dimaksud 14 hari, **set konfigurasi** ke 14 (UI admin dan API sudah selaras di 0). Form APK baca nilai dari API, lalu uji ulang.
11. **A-01** — Uji ulang tab Jadwal APK 0.1.9 (API sudah benar).
12. **A-02 / B-19-01** — Tab bar + time picker izin (blocker pengajuan).
13. **W-05 / A-03** — Event log admin + receipt outbox (perlu sesi admin terpisah untuk W-05).
14. **INFO-01** — Konfirmasi kuota Cuti Reguler 2 dan Deposit Off 3.

### Sprint 3 — berikutnya

15. **AUD-06** — TTL Sanctum. **AUD-07** — Header keamanan. **AUD-NV-02** — allow-list proxy.
16. **W-07, W-10** — Split (perlu sesi admin terpisah) dan aturan simpan masa lalu. **W-09 sudah Fixed.**
17. **A-05, A-06, A-07, A-08, A-09, B-19-02, B-19-03** — Izin lokasi, pesan GPS Indonesia, ANR di HP fisik, notifikasi cuti, kamera, sesi setelah upgrade.

---

## 7. Tabel ringkasan

| ID | Judul singkat | Area | Severity | Status 12 Nov |
|---|---|---|---|---|
| W-01 | Auto-close checkout mengunci pulang | Web/API | Critical | Open (sebagian diperbaiki) |
| AUD-01 | Throttle login kunci `email` | Security | High | Open |
| AUD-02 | Foto/avatar publik tanpa auth | Security | High | Open |
| AUD-03 | Password lemah + default source | Security | High | Open (source; 7 akun dilihat, sandi default tidak dicoba; tidak ada kebijakan sandi) |
| W-02 | Checkout terlambat bukan `check_out_at` | Web/API | High | Open (sebagian: alur verifikasi admin ada; 13 sesi lama masih perlu tindakan) |
| W-03 | Label izin salah di Riwayat | Web/Admin | High | Belum dites ulang (perlu sesi admin terpisah, 11 Nov) |
| W-04 | Cari “keluar” HTTP 500 | Web/Admin | High | Belum dites ulang (perlu sesi admin terpisah; petunjuk 200 pada 11 Nov 22.03) |
| W-05 | Tidak ada log event di admin | Web/Admin | High | Belum dites ulang (perlu sesi admin terpisah, 11 Nov) |
| W-06 | Jarak cuti di-set 0 (UI=API); kebijakan mungkin 14 | Web/API | High | Open (konfigurasi) |
| A-01 | Jadwal 12 Nov fiktif / 13 Nov hilang | APK | High | API benar; tab APK belum dites ulang (picker OK 11 Nov 22.12) |
| A-02 | Tab bar menutupi CTA | APK | High | Belum dites ulang (Open 11 Nov) |
| A-03 | Outbox tanpa jejak admin | APK | High | Belum dites ulang (11 Nov) |
| A-04 | Jarak cuti tidak ditegakkan di APK | APK | High | Open (konfigurasi=0, silang W-06); form APK belum dites ulang |
| B-19-01 | Time picker izin tidak mengisi jam | APK | High | Belum dites ulang (Open 11 Nov 22.12) |
| AUD-04 | Direktori + jadwal rekan | Security | Medium | Open |
| AUD-05 | GPS / mock percaya klien | Security | Medium | Open (source) |
| AUD-06 | Token Sanctum tanpa TTL | Security | Medium | Open (source) |
| AUD-08 | Avatar SVG MIME bypass | Security | Medium | Open |
| AUD-09 | Backup/restore tanpa re-auth atau 2FA | Security/Admin | Medium | Open (cek admin 19.15) |
| W-07 | Teks vs validasi split khusus | Web/Admin | Medium | Belum dites ulang (perlu sesi admin terpisah, 11 Nov) |
| W-08 | Absensi hantu setelah revisi | Web/Admin | Medium | Belum dites ulang (perlu sesi admin terpisah, 11 Nov) |
| W-09 | Grace/cutoff tidak di form admin | Web/Admin | Medium | **Fixed** (12 Nov 19.15, form Jendela presensi) |
| A-05 | Izin lokasi tidak di awal alur | APK | Medium | Belum dites ulang (11 Nov) |
| A-06 | Error lokasi bahasa Inggris | APK | Medium | Belum dites ulang (11 Nov) |
| A-07 | ANR form izin | APK | Medium | Belum dites ulang (11 Nov) |
| A-08 | Notifikasi cuti bertentangan | APK/API | Medium | Open |
| B-19-02 | Sesi hilang setelah `install -r` | APK | Medium | Belum dites ulang (11 Nov) |
| B-19-03 | ANR di layar login | APK | Medium | Belum dites ulang (11 Nov) |
| AUD-07 | Header keamanan + X-Powered-By | Security | Low | Open |
| W-10 | Simpan jadwal masa lalu ditolak | Web/Admin | Low | Belum dites ulang (perlu sesi admin terpisah, 11 Nov) |
| A-09 | Tidak ada pesan jika kamera depan absen | APK | Low | Belum dites ulang (11 Nov) |
| AUD-NV-02 | TRUSTED_PROXIES / XFF | Security | — | Belum dipastikan |
| INFO-01 | Kuota Cuti Reguler 2 / Deposit Off 3 vs default 12 / 4 | Web/Admin | Info | Perlu konfirmasi Yoga (tidak masuk hitung severity) |

**Hitung (32 item yang masuk severity, ID tidak digabung; plus INFO-01 di luar hitung):**

| | Critical | High | Medium | Low | Needs validation | Jumlah |
|---|---:|---:|---:|---:|---:|---:|
| **Open** (termasuk sebagian diperbaiki) | 1 | 6 | 6 | 1 | 0 | **14** |
| **Belum dites ulang** (emulator / perlu sesi admin terpisah, status lama bertanggal) | 0 | 7 | 7 | 2 | 0 | **16** |
| **Needs validation** | 0 | 0 | 0 | 0 | 1 | **1** |
| **Fixed penuh** | 0 | 0 | 1 | 0 | 0 | **1** |
| **Jumlah** | **1** | **13** | **14** | **3** | **1** | **32** |

*Catatan hitung:*
- *High Open 6: AUD-01, AUD-02, AUD-03, W-02 (sebagian), W-06 (konfigurasi), A-04. W-01 Critical Open (sebagian).*
- *Medium Open 6: AUD-04, AUD-05, AUD-06, AUD-08, AUD-09 (baru, cek admin), A-08.*
- *Fixed: W-09 (form Jendela presensi, 12 Nov 19.15).*
- *Belum dites ulang High 7: W-03, W-04, W-05, A-01 (sisa tab APK), A-02, A-03, B-19-01. Medium 7: W-07, W-08, A-05, A-06, A-07, B-19-02, B-19-03. Low 2: W-10, A-09.*
- *INFO-01 (kuota cuti) tidak masuk 32, menunggu konfirmasimu.*
- *Tidak ada lagi item “menunggu cek admin.” IDOR Admin vs Super Admin tetap belum diverifikasi live (bukan baris ID terpisah).*

Tidak ada temuan keamanan AUD-01..AUD-08 yang **Fixed**. Temuan keamanan baru: **AUD-09** (Medium, Open). Satu temuan QA **Fixed:** W-09. Production masih `v0.1.8`. Keputusan checkout opsi pertama sudah tercermin di source, di sesi tes 110997 11 Nov, dan di alur Verifikasi/Koreksi admin; 13 sesi auto-checkout lama masih perlu tindakan. **Purge CDN** masih diperlukan untuk sisa avatar uji SVG (`avatar_53_1794480812.jpg` perkiraan ±21.53 WIB, `avatar_53_1794485276.jpg` ±23.08 WIB, plus `avatar_53_1793323443.jpg` jika sudah tidak dipakai).

---

*Sumber: Security-Audit-DeResort-2026-11-12 (Fase 1+2), retest production 12 Nov 19.07–19.08 WIB, cek admin Super Admin 12 Nov 19.15 WIB (read-only), Daftar-Temuan-DeResort-Web-APK-2026-11-11 (19 bug), Retest-APK-baru-Izin-Keluar-2026-11-11 (B-19), keputusan checkout opsi pertama. Data uji malam ini hanya pada 110997, sudah dibersihkan kecuali cache CDN yang perlu di-purge. Sesi admin tidak menulis apa pun. Password dan token tidak ditulis di dokumen ini. PII (email) di cuplikan admin dilindungi di narasi.*
