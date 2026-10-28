# Spesifikasi Minimum APK Android
**Deresort Attendance & Shift Management Mobile App**

Dokumen ini memuat standar spesifikasi perangkat keras (*hardware*), sistem operasi (*operating system*), izin sistem (*permissions*), serta sensor yang dibutuhkan agar aplikasi **Deresort Attendance** (`id.deresort.attendance`) dapat berjalan dengan stabil, lancar, dan akurat pada perangkat Android.

---

## 1. Identitas & Informasi Aplikasi

| Parameter | Keterangan |
|---|---|
| **Nama Aplikasi** | Deresort Attendance |
| **Package Name** | `id.deresort.attendance` |
| **Versi Aplikasi** | `0.1.0` |
| **Framework / Core** | React Native `0.88.0` / Expo SDK `58.0.20` |
| **Format Distribusi** | APK (*Standalone Sideload* / Internal MDM) atau AAB (*Google Play Store*) |
| **Arsitektur CPU yang Didukung** | `arm64-v8a` (64-bit), `armeabi-v7a` (32-bit), `x86_64`, `x86` |
| **Ukuran Instalasi (Est.)** | ± 35 MB – 50 MB (APK) |

---

## 2. Tabel Spesifikasi Perangkat

| Komponen | Spesifikasi Minimum (*Minimum*) | Spesifikasi Direkomendasikan (*Recommended*) |
|---|---|---|
| **Versi Android** | **Android 6.0 Marshmallow** (API Level 23) | **Android 10.0 (API Level 29)** s/d **Android 14 / 15** |
| **Prosesor (CPU)** | Quad-Core 1.3 GHz (64-bit / 32-bit) | Octa-Core 2.0 GHz atau lebih tinggi (Snapdragon / Helio / Dimensity) |
| **Memori (RAM)** | **2 GB RAM** | **3 GB – 4 GB RAM atau lebih** |
| **Ruang Penyimpanan (ROM)** | Minimal **150 MB** ruang kosong | **500 MB** ruang kosong atau lebih |
| **Resolusi Layar** | 720 × 1280 piksel (HD, rasio 16:9 / 18:9) | 1080 × 2400 piksel (FHD+, rasio modern) |
| **Kamera** | Kamera belakang/depan **5 MP** dengan fokus tetap | Kamera **8 MP – 13 MP** dengan *Autofocus* |
| **GPS / Lokasi** | GPS / A-GPS dasar (Akurasi < 100m) | GPS, GLONASS, Galileo, BeiDou (*High Accuracy Mode*, akurasi < 15m) |
| **Konektivitas Jaringan** | Jaringan Seluler 3G / Wi-Fi 2.4 GHz | Jaringan Seluler 4G LTE / 5G / Wi-Fi Dual-Band (2.4 & 5 GHz) |

> [!IMPORTANT]
> **Catatan RAM & Performa:**
> Meskipun aplikasi dapat berjalan pada RAM 2 GB, perangkat dengan RAM 3 GB atau lebih sangat disarankan agar aplikasi tidak mudah dimatikan oleh sistem manajemen memori bawaan Android (*Low Memory Killer*) ketika beralih membuka kamera atau aplikasi lain.

---

## 3. Kebutuhan Sensor & Fitur Perangkat Keras

1. **GPS & Layanan Lokasi (*Location Services*)**:
   - **Wajib Aktif**: Fitur pencatatan kehadiran (*Check-in* & *Check-out*) memvalidasi radius koordinat geofencing resort.
   - **Mode Akurasi Tinggi**: Pengaturan lokasi perangkat harus diatur pada mode *"High Accuracy"* atau *"Google Location Accuracy"* aktif.
2. **Kamera & Pemroses Gambar**:
   - Diperlukan untuk pengambilan foto profil pengguna langsung dari kamera serta pengambilan bukti/lampiran foto surat dokter atau keterangan dinas.
   - Mendukung fitur *Cropping* gambar persegi 1:1 dan kompresi otomatis client-side.
3. **Penyimpanan Lokal Terenkripsi & Basis Data SQLite**:
   - Perangkat wajib mendukung modul penyimpanan aman hardware (*Android Keystore* via `expo-secure-store`) untuk menyimpan kredensial token dan UUID perangkat.
   - Modul SQLite lokal (`expo-sqlite`) digunakan untuk menampung antrean outbox offline dan data jadwal shift.
4. **Waktu & Jam Perangkat (*Device Clock*)**:
   - Jam perangkat **wajib disetel otomatis** mengikuti waktu jaringan operator (*Automatic Date & Time / Network-provided time*).
   - Ketidaksesuaian jam lokal ekstrem (> 60 detik) dapat menyebabkan selisih perhitungan presensi dengan jam server backend UTC+7 (WIB).

---

## 4. Daftar Izin Aplikasi (*Android Permissions*)

Aplikasi meminta izin (*runtime permissions*) berikut sesuai kebutuhan fungsi:

| Nama Izin | Kebutuhan & Fungsi |
|---|---|
| `android.permission.ACCESS_FINE_LOCATION` | Menentukan titik koordinat lintang & bujur secara presisi untuk verifikasi radius geofence absensi kerja. |
| `android.permission.ACCESS_COARSE_LOCATION` | Menentukan perkiraan lokasi berbasis jaringan menara seluler / Wi-Fi saat sinyal satelit lemah. |
| `android.permission.CAMERA` | Mengambil foto profil baru dan memotret dokumen surat izin/sakit langsung dari kamera. |
| `android.permission.READ_EXTERNAL_STORAGE` / `READ_MEDIA_IMAGES` | Memilih foto profil yang sudah tersimpan di galeri perangkat. |
| `android.permission.INTERNET` | Mengirim data presensi, pengajuan izin, dan sinkronisasi jadwal ke backend server Laravel. |
| `android.permission.ACCESS_NETWORK_STATE` | Mendeteksi ketersediaan koneksi internet (online/offline) untuk mekanisme penyimpanan antrean outbox otomatis. |

---

## 5. Kebijakan Keamanan & Kompatibilitas Khusus

1. **Aplikasi Mock Location (Fake GPS)**:
   - Sistem backend dilengkapi deteksi anomali koordinat. Perangkat yang menggunakan aplikasi *Fake GPS* atau mengaktifkan fitur *"Allow mock locations"* di opsi pengembang (*Developer Options*) dapat ditolak oleh server absensi.
2. **Perangkat yang di-Root (*Rooted Device*)**:
   - Tidak direkomendasikan demi menjaga integritas data absensi dan keamanan enkripsi token login.
3. **Optimasi Baterai (*Battery Optimization / Doze Mode*)**:
   - Disarankan untuk mengecualikan aplikasi Deresort dari fitur hemat daya ekstrem (*Battery Saver*) agar proses sinkronisasi antrean outbox saat kembali online tidak terhambat.
