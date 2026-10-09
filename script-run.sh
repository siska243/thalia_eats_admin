composer install --optimize-autoloader --no-dev
# Republie public/js/filament et public/css/filament depuis vendor/.
# Sans cette ligne, une montee de version de Filament laissait les anciens
# fichiers en place : le HTML les demandait en « ?v=5.8.2.0 », le navigateur
# recevait le build precedent, et l'administration tombait sur
# « filamentDropdown is not defined » et « filamentTable is not defined ».
# Constate le 09/10/2026 : support.js faisait 136 Ko sans filamentDropdown la
# ou vendor en servait 145 Ko avec, et tables.js etait reduit a 12 octets.
php artisan filament:assets
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


