composer install --optimize-autoloader --no-dev
php artisan settings:clear-cache
php artisan cache:clear
php artisan optimize:clear
php artisan route:clear && php artisan route:cache
php artisan config:clear && php artisan config:cache
php artisan optimize:clear && php artisan cache:clear
php artisan filament:optimize-clear && php artisan filament:optimize
php artisan view:clear
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force
php artisan optimize


