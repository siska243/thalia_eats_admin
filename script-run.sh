composer install --optimize-autoloader --no-dev
php artisan cache:clear
php artisan optimize:clear
php artisan optimize
php artisan config:cache
php artisan optimize:clear && php artisan cache:clear
php artisan view:clear
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force
php artisan optimize

