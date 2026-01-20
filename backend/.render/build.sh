#!/usr/bin/env bash

# Exit on error
set -o errexit

# Install dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader

# Generate app key
php artisan key:generate

# Clear and cache config
php artisan config:clear
php artisan cache:clear

# Run database migrations (only if needed)
# php artisan migrate --force
