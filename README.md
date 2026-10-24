# KPSM Deresort Absensi

Sistem absensi berbasis Laravel untuk admin web dan API mobile. Aplikasi web berada di `server/`; `mobile/` masih berupa prototype.

## Menjalankan lokal

```sh
cd server
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan serve
```

Login admin dibuat oleh `AdminUserSeeder` jika `ADMIN_EMAIL` dan `ADMIN_PASSWORD` diisi. Data contoh karyawan/jadwal/absensi untuk pengujian lokal dapat ditambahkan dengan `php artisan db:seed --class=DemoDataSeeder`. Jangan jalankan seeder demo di production.

## CI, GHCR, dan Dokploy

Workflow `.github/workflows/ci-publish.yml` menjalankan suite Laravel, memeriksa migrasi pada MySQL 8.4, membangun aset web, lalu menerbitkan image `ghcr.io/kkstech/kpsm-deresort-absensi` pada push ke `main` dan tag `v*`. Pull request hanya menjalankan verifikasi.

Untuk menjalankan image sebagai **Dokploy Application** (source Docker, bukan Docker Compose):

1. Hubungkan GHCR di Dokploy. Gunakan username GitHub dan token yang memiliki `read:packages` jika package tetap private.
2. Buat Application dengan image `ghcr.io/kkstech/kpsm-deresort-absensi:latest`, port `80`, lalu tambahkan domain.
3. Isi environment production di bawah. Database MySQL harus sudah tersedia dan dapat dijangkau dari jaringan aplikasi Dokploy; image tidak menyertakan database.
4. Tambahkan persistent volume pada `/var/www/html/storage/app` agar bukti absensi, logo, dan favicon tetap ada setelah deploy.
5. Deploy, lalu jalankan di **Advanced → Run Command**:

   ```sh
   php artisan migrate --force
   php artisan db:seed --class=DatabaseSeeder --force
   ```

   Ulangi `php artisan migrate --force` setelah ada migration baru. Seeder `DatabaseSeeder` membuat data master dan akun admin dari environment.

Environment production yang wajib:

```dotenv
APP_NAME="KPSM Deresort Attendance"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<kunci-yang-dibuat-secara-aman>
APP_URL=https://domain-anda
DB_CONNECTION=mysql
DB_HOST=<host-mysql>
DB_PORT=3306
DB_DATABASE=<database>
DB_USERNAME=<user>
DB_PASSWORD=<password>
ADMIN_NAME="Super Admin"
ADMIN_EMAIL=<email-admin>
ADMIN_PASSWORD=<password-admin>
TRUSTED_PROXIES=REMOTE_ADDR
```

Image menjalankan Apache pada port 80 dan scheduler Laravel dalam container yang sama. `TRUSTED_PROXIES=REMOTE_ADDR` mempercayai header proxy dari koneksi langsung Dokploy/Traefik; jangan publikasikan port container langsung ke internet.

Untuk memicu deploy Dokploy otomatis setelah image `latest` terbit, tambahkan repository variables `DOKPLOY_URL` dan `DOKPLOY_APPLICATION_ID`, serta repository secret `DOKPLOY_API_KEY`. Tanpa nilai tersebut workflow tetap menerbitkan image dan melewati langkah deploy.
