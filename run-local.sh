#!/usr/bin/env bash
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_DIR="$DIR/server"

echo "=== Memulai Absensi Deresort (Lokal) ==="

cd "$SERVER_DIR"

if [ ! -f .env ]; then
    echo "Membuat file .env dari .env.example..."
    cp .env.example .env
    php artisan key:generate
fi

echo "Memeriksa database & migrasi..."
php artisan migrate --force

echo "Memastikan aset frontend ter-build..."
npm run build

echo "Server web berjalan di http://0.0.0.0:8000 (dapat diakses via http://localhost:8000 atau IP LAN laptop)"
echo "Login admin:"
echo "  Email   : admin@deresort.test"
echo "  Password: (sesuai ADMIN_PASSWORD di server/.env)"
echo ""
echo "Tekan Ctrl+C untuk menghentikan server."
exec php -d upload_max_filesize=20M -d post_max_size=25M artisan serve --host=0.0.0.0 --port=8000
