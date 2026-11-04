FROM php:8.3-cli-alpine

# Instalar dependencias del sistema y librerías para extensiones de PHP
RUN apk add --no-cache \
    postgresql-dev \
    postgresql-client \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    linux-headers \
    bash

# Instalar extensiones necesarias de PHP para Laravel y PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath pcntl

# Instalar Composer desde la imagen oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Copiar script de arranque
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Exponer el puerto del backend
EXPOSE 8005

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8005"]

