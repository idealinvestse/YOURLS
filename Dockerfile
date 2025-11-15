FROM php:8.2-apache

# Install required PHP extensions and enable Apache mod_rewrite
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mysqli pdo pdo_mysql; \
    a2enmod rewrite

# Allow .htaccess overrides for YOURLS
RUN set -eux; \
    { \
      echo '<Directory /var/www/html/>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/allowoverride.conf; \
    a2enconf allowoverride

WORKDIR /var/www/html
