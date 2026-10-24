#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage/framework storage/logs bootstrap/cache
chown www-data:www-data storage/app/public storage/app/private
php artisan storage:link --force

su -s /bin/sh -c 'php artisan schedule:work' www-data &
exec "$@"
