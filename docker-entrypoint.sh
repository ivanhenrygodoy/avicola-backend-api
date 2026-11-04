#!/bin/sh
set -e

# Crear directorios críticos si no existen
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# Permisos para storage y cache
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# Instalar dependencias si vendor no existe (por ejemplo en volumen montado vacío)
if [ ! -d "/var/www/html/vendor" ]; then
    echo "Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist
fi

# Copiar .env si no existe
if [ ! -f "/var/www/html/.env" ]; then
    echo "Creando .env a partir de .env.example..."
    cp /var/www/html/.env.example /var/www/html/.env
    php artisan key:generate --force
fi

# Esperar a que la base de datos esté lista si DB_HOST está configurado
if [ -n "$DB_HOST" ]; then
    echo "Esperando conexión a PostgreSQL en $DB_HOST:${DB_PORT:-5432}..."
    until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" > /dev/null 2>&1; do
        sleep 1
    done
    echo "PostgreSQL está listo y aceptando conexiones."
fi

echo "Iniciando Laravel Backend API..."
exec "$@"
