FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chmod -R 775 storage bootstrap/cache

RUN php artisan config:clear

ENV WEBROOT=/var/www/html/public

EXPOSE 80