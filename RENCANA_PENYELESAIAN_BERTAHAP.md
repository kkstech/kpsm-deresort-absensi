# Rencana penyelesaian bertahap

Tanggal pemeriksaan: 24 Oktober 2026.

Dasar: `CHANGELOG.md`, PRD, technical implementation plan, dan pemeriksaan kode. Tahap 1–4 sudah diimplementasikan; tahap berikutnya tahap 5. Plan implementasi otoritatif sebelumnya tidak diubah.

## Status yang sudah diverifikasi

- `php artisan test` di `server/` lulus: 55 tes, 338 assertion. Menggunakan SQLite `:memory:`; hasil ini belum membuktikan perilaku locking MySQL.
- Auth, master, jadwal, empat endpoint mobile absensi, auto checkout, Monitoring, dan Dashboard sudah punya controller/action serta tes.
- Tahap 4 selesai: pengajuan izin/koreksi, keputusan, verifikasi checkout, koreksi Admin, bukti privat, dan layar Persetujuan sudah terhubung.
- `php artisan migrate:status`: seluruh migration termasuk jadwal `030000`, absensi `040000`, dan workflow `050000` sudah jalan di database lokal.
- Dashboard/Monitoring dan Persetujuan sudah terhubung. Expo belum terhubung ke proses bisnis. README belum menjadi rujukan status implementasi.
- `npm run build` lulus. Browser interaktif tidak tersedia pada sesi pengerjaan; render Blade dan API diperiksa lewat tes fitur.
- Direktori kerja tidak terdeteksi sebagai repository Git, sehingga perubahan terakhir diperiksa dari changelog dan kode, tanpa perbandingan commit.

## Batas dan keputusan

- Prioritas pengerjaan sekarang: backend, API, dan admin web. Integrasi Expo merupakan tahap lanjutan ketika diminta.
- Pertahankan session + CSRF untuk admin, Sanctum untuk mobile, role di `users.role`, scope supervisor di query, UTC untuk waktu, dan string tanggal kerja tanpa cast.
- Event mentah immutable. Koreksi hanya mengubah proyeksi dengan audit. Auto checkout hanya provisional dan tidak menghasilkan menit valid sebelum verifikasi.
- Gunakan status periode `FINALIZED` sesuai implementasi sekarang; petakan istilah `LOCKED` pada dokumen lama dalam kontrak API.
- Ikuti cakupan changelog untuk lembur: `ACTUAL_CAPPED`. PRD masih menyebut `PLANNED_CAPPED`; catat sebagai selisih scope yang perlu disepakati sebelum UAT akhir, jangan implementasikan diam-diam.
- Tidak memasang Laravel Boost. Tidak menyalin secret ke dokumen. Tidak menjalankan demo seeder di production.

## Tahap 1 — Kontrak dan ketepatan mesin absensi

**Status: selesai.** `RecordAttendance` kini menyimpan audit, mematuhi snapshot lokasi, membuang koordinat saat GPS nonaktif, menahan event yang tidak berurutan sampai bisa direkonsiliasi, dan tidak mengubah proyeksi periode final. Tes mencakup waktu server, shift malam, keterlambatan, empat sesi, retry/konflik UUID, sinkronisasi offline, GPS, dan periode final.

Yang diselesaikan:

- [x] Tulis `docs/api-contract.yaml` untuk auth dan endpoint absensi dengan payload, status, error, autentikasi perangkat, respons per event, dan aturan retry.
- [x] Uji jam server online, shift malam, 1–4 sesi, toleransi terlambat, durasi dalam jendela, dan batas menit terjadwal.
- [x] Uji UUID identik/payload berbeda, retry event, urutan offline, dan checkout tanpa check-in. Uji balapan serentak ditunda ke MySQL pada tahap 8.
- [x] Cegah event offline pada periode final mengubah proyeksi; pertahankan event sebagai bukti review.
- [x] Buang koordinat ketika pemeriksaan lokasi nonaktif dan bekukan lokasi/policy dalam snapshot sesi.
- [x] Kunci employee dan sesi saat merekam; unique `event_uuid` melindungi retry. Integrasi locking dengan auto checkout/finalisasi menunggu tahap terkait.
- [x] Tetapkan bahwa semua event offline perlu review. Pengujian balapan serentak MySQL ditunda ke tahap 8.

Syarat selesai: tes absensi membuktikan event mentah terjaga, retry tidak menggandakan proyeksi, data review tidak menambah menit valid, dan periode final tidak berubah.

## Tahap 2 — API absensi dan sinkronisasi

**Status: selesai.** Empat endpoint sudah dibuat; request tervalidasi, perangkat diturunkan dari token, sync mengembalikan hasil per event, dan kontrak ditulis pada `server/docs/api-contract.yaml`. Tes meliputi API jadwal, event, sync, history, retry, dan perangkat dicabut.

Ketergantungan: tahap 1.

- [x] Implementasikan empat endpoint jadwal, event, sync, dan riwayat.
- [x] Validasi payload, kepemilikan sesi, akun aktif, perangkat, dan token; blokir perangkat yang dicabut.
- [x] Simpan `sync_batches`, beri hasil per event, urutkan batch deterministik, dan rekonsiliasi checkout offline lintas batch dengan status review.
- [x] Uji alur, retry, UUID konflik, validasi parsial, akses lintas karyawan, perangkat dicabut, dan mismatch perangkat.

Syarat selesai: alur jadwal → check-in → check-out → riwayat dapat dijalankan melalui API; pengiriman ulang tiga kali tidak menambah event/proyeksi ganda.

## Tahap 3 — Auto checkout dan monitoring admin

**Status: selesai.** Tes lokal membuktikan provisional checkout, retry tanpa efek ganda, checkout yang diproses sebelum maupun setelah provisional, periode final, hasil provisional di Monitoring, ringkasan Dashboard, filter dan scope supervisor, serta render layar. Pengujian row lock yang sesungguhnya tetap dijadwalkan di MySQL pada tahap 8.

Ketergantungan: tahap 2.

- [x] Buat command `attendance:auto-checkout` dan daftarkan ke scheduler. Command dapat dijalankan ulang tanpa efek ganda.
- [x] Isi `provisional_out_at` sesuai aturan; waktu aktual checkout tetap kosong dan `verified_minutes` tetap 0 sampai verifikasi.
- [x] Uji checkout offline yang tiba setelah penutupan provisional dan checkout yang selesai sebelum command. Pengujian locking bersamaan command dan request ditunda ke MySQL tahap 8.
- [x] Buat API Monitoring dan angka kehadiran Dashboard dengan filter tanggal/departemen/karyawan serta scope supervisor pada query.
- [x] Hubungkan layar ke data server. Modul yang belum dibangun diberi keterangan belum tersedia dan banner data contoh Dashboard dihapus.
- [x] Jalankan migration lokal setelah memeriksa konfigurasi database tujuan. Build asset berhasil; browser interaktif tidak tersedia pada sesi pengerjaan.

Syarat selesai: sesi terlupa checkout muncul sebagai provisional, dapat dipantau admin, dan tidak menambah jam valid.

## Tahap 4 — Izin, koreksi, dan verifikasi

**Status: selesai.** Tujuh tes fitur tahap 4 membuktikan bukti wajib berdasarkan snapshot policy, scope supervisor dan larangan self-approval, koreksi Admin dan idempoten tanpa mengubah event mentah, verifikasi checkout beserta menit valid, dan penolakan mutasi periode final. Suite total lulus 55 tes / 338 assertion.

Ketergantungan: tahap 3.

- [x] Simpan permintaan izin/koreksi, bukti privat yang mengikuti snapshot policy, keputusan, dan audit before/after.
- [x] Terapkan persetujuan izin oleh Admin atau supervisor yang berwenang; izin langsung hanya Super Admin dan tidak mengubah timestamp asli.
- [x] Sediakan koreksi yang diajukan karyawan, koreksi langsung oleh Admin, dan verifikasi checkout dengan waktu serta dasar verifikasi eksplisit.
- [x] Lengkapi API mobile dan layar Persetujuan. Keputusan mengubah proyeksi secara atomik dan pengulangan keputusan yang sama idempoten.
- [x] Tolak permintaan, keputusan, koreksi, dan verifikasi pada periode final; batasi unduhan bukti ke pemilik atau staf ber-scope.
- [x] Jalankan migration `2026_10_24_050000_create_attendance_workflow_tables` di SQLite lokal, seluruh tes backend, dan build asset.

Syarat selesai: permintaan sampai keputusan berjalan penuh; proyeksi diperbarui dengan benar, event asli tetap utuh, dan keputusan tanpa hak ditolak server.

Paket implementasi berikutnya yang disarankan: tahap 5 (lembur `ACTUAL_CAPPED`). Tes MySQL bersamaan tetap menunggu tahap 8.

## Tahap 5 — Lembur ACTUAL_CAPPED

Ketergantungan: tahap 4.

- Bangun perintah lembur oleh Admin, approval akhir Admin, dan consent karyawan yang terikat versi serta hash isi perintah.
- Otorisasi hanya sah bila approval dan consent telah tercatat server sebelum mulai. Persetujuan offline belum dianggap sah.
- Pisahkan event lembur dari event reguler; durasi diakui dibatasi jendela yang disetujui dan bebas penghitungan ganda.
- Perpanjangan wajib disetujui dan di-consent sebelum segmen tambahan mulai.
- Lengkapi API/admin, kontrak, audit, dan tes batas waktu, versi berubah, overlap, serta consent terlambat.

Syarat selesai: lembur tanpa otorisasi tepat waktu tidak diakui; durasi aktual dan durasi diakui tetap dapat ditelusuri.

## Tahap 6 — Tunjangan dan laporan

Ketergantungan: tahap 5.

- Implementasikan jenis, nominal, eligibility, versi efektif, dan pembulatan tunjangan sesuai konfigurasi bisnis.
- Dukung FULL, PRORATED_BY_SESSION, PRORATED_BY_MINUTES, dan NONE; simpan dasar hitung serta alasan hasil.
- Gunakan sesi/jam valid; provisional dan review tidak masuk tunjangan final.
- Buat laporan JSON dan ekspor XLSX/PDF memakai paket yang sudah terpasang. Terapkan filter dan scope yang sama pada layar dan ekspor.
- Bedakan jumlah hari hadir, jumlah sesi, menit reguler, lembur, dan tunjangan. Beri penanda provisional serta waktu generasi pada ekspor belum final.
- Lengkapi seluruh angka Dashboard dengan sumber data nyata.

Syarat selesai: hasil layar, JSON, XLSX, dan PDF cocok untuk filter yang sama; nominal historis tidak berubah akibat perubahan policy baru.

## Tahap 7 — Finalisasi dan revisi periode

Ketergantungan: tahap 6. Penjagaan terhadap periode final harus sudah berlaku sejak tahap 1.

- Buat pemeriksaan review/approval kritikal sebelum finalisasi; override hanya Super Admin dengan alasan dan audit jika diperlukan sesuai PRD.
- Simpan snapshot rekap beserta revisi dan checksum secara atomik.
- Terapkan penguncian yang konsisten pada jadwal, sync, koreksi, verifikasi, lembur, dan tunjangan, termasuk request yang berlomba dengan finalisasi.
- Reopen membutuhkan kewenangan dan alasan; hasil berikutnya menjadi revisi baru dan snapshot lama tetap tersedia.
- Laporan periode final membaca snapshot yang dipilih, termasuk ekspor ulang versi lama.

Syarat selesai: finalisasi tidak dapat ditembus mutasi bersamaan; reopen menghasilkan revisi baru tanpa menghilangkan arsip sebelumnya.

## Tahap 8 — Kesiapan produksi backend

Ketergantungan: tahap 7.

- Jalankan migration dan tes integrasi pada MySQL 8.4 terpisah, terutama row lock, duplikasi serentak, overlap jadwal, sync, dan finalisasi. SQLite tetap digunakan untuk tes cepat.
- Verifikasi build admin, alur browser, hak akses supervisor, TOTP, pencabutan token/perangkat, dan keterlacakan `request_id`.
- Lengkapi kontrak API seluruh modul dan perbarui README dari demo menjadi panduan implementasi nyata.
- Siapkan Nginx/PHP-FPM, TLS, waktu server, queue worker, scheduler, log, dan pemeriksaan operasional.
- Buat SOP backup manual dan restore, lalu lakukan uji pemulihan di lingkungan terpisah. Tidak menambah backup otomatis tanpa kesepakatan.
- Catat parameter UAT yang belum disepakati: toleransi waktu, batas offline, GPS, aturan tunjangan/pembulatan, approval, dan selisih scope lembur.

Syarat selesai: backend siap untuk UAT integrasi; hasil pengujian MySQL dan restore tercatat. Penyelesaian tahap ini belum berarti aplikasi mobile siap digunakan.

## Tahap lanjutan — Expo dan UAT menyeluruh

Dikerjakan ketika integrasi mobile diminta, setelah kontrak absensi stabil. Auth, jadwal, dan antrean absensi dapat mulai diintegrasikan setelah tahap 3; izin/koreksi/lembur mengikuti kesiapan endpoint masing-masing.

- Ganti data demo dengan API; simpan token melalui SecureStore, cache jadwal dan antrean event melalui SQLite.
- Persist UUID/urutan event, ACK per event, retry dengan backoff, dan status tersimpan lokal/tersinkronisasi/butuh review.
- Uji aplikasi ditutup lalu dibuka, jaringan putus, token habis/dicabut, shift malam, jadwal berubah, dan checkout yang datang setelah auto checkout.
- Jalankan acceptance PRD lintas web/mobile, termasuk offline, approval, lembur, tunjangan, finalisasi/reopen, dan operasional LAN.
- Siapkan build Android lokal dan panduan instalasi. Distribusi iOS mengikuti cakupan yang disepakati.

Syarat selesai: skenario UAT disetujui dan paket aplikasi beserta SOP operasional dapat diserahterimakan.

## Cara menutup setiap tahap

- Selesaikan satu alur bisnis sampai API, otorisasi, audit, tes, dan layar terkait berfungsi.
- Jalankan tes yang relevan dan regresi backend; uji browser ketika ada perubahan layar. Tambahkan tes MySQL untuk perilaku transaksi yang tidak dibuktikan SQLite.
- Perbarui `CHANGELOG.md` dengan hasil nyata, hasil tes, migration yang perlu dijalankan, dan sisa pekerjaan. Jangan menandai modul selesai hanya karena file telah dibuat.

Paket implementasi berikutnya yang disarankan: tahap 5 (lembur `ACTUAL_CAPPED`). Migration jadwal, absensi, dan workflow sudah jalan di SQLite lokal; tes fitur memakai SQLite `:memory:`. Perilaku locking serentak perlu diuji di MySQL sebelum UAT.
