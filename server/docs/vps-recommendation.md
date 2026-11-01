# Rekomendasi VPS Deresort Attendance

## Ukuran awal

Untuk satu resort dengan beban kecil hingga menengah, jalankan aplikasi dan database pada VPS Linux dengan:

- **4 vCPU**
- **8 GB RAM**
- **80–120 GB NVMe**
- Ubuntu LTS, Nginx, PHP-FPM, dan MySQL 8
- HTTPS dengan sertifikat otomatis dan backup database harian ke lokasi terpisah

Konfigurasi 2 vCPU dan 4 GB RAM cukup untuk staging atau peluncuran awal dengan beban ringan. Pisahkan database ke layanan tersendiri bila jumlah pengguna serentak meningkat atau pemantauan menunjukkan CPU, RAM, maupun I/O disk konsisten penuh.

## Pengaturan layanan

- Aktifkan OPcache PHP dan gunakan konfigurasi PHP-FPM `dynamic`. Tentukan `pm.max_children` dari RAM yang tersedia untuk PHP dibagi pemakaian memori rata-rata satu worker; sisakan ruang untuk MySQL, sistem operasi, dan lonjakan proses.
- Pada VPS 8 GB yang menjalankan MySQL bersama PHP-FPM, mulai dengan buffer pool InnoDB sekitar **2–3 GB**, lalu sesuaikan dari pemakaian RAM dan metrik disk. Dokumentasi MySQL menyebut 50–75% RAM sebagai kisaran umum, tetapi proses aplikasi juga berbagi memori pada konfigurasi satu VPS.
- Jalankan satu atau dua worker queue dengan pemantau proses (systemd atau Supervisor). Proyek memakai queue database secara default.
- Gunakan Redis untuk cache dan queue jika beban meningkat; pertahankan MySQL sebagai penyimpanan data utama.
- Kompresi Gzip/Brotli dan cache aset statis Vite di Nginx; kirim foto absensi ke object storage bila volume media membesar.

## Saat deploy

Build aset produksi, jalankan migrasi setelah backup, lalu jalankan `php artisan optimize` agar konfigurasi, route, event, dan view Laravel tersimpan dalam cache. Pantau waktu respons endpoint roster, penggunaan RAM PHP-FPM, slow query MySQL, antrean jobs, dan ruang disk selama beberapa minggu pertama sebelum menaikkan ukuran VPS.

## Asumsi

Ukuran ini adalah titik awal untuk aplikasi satu lokasi, bukan hasil load test. Kapasitas aktual dipengaruhi jumlah karyawan aktif, jumlah jadwal yang dimuat per periode, foto presensi, dan puncak akses saat pergantian shift.
