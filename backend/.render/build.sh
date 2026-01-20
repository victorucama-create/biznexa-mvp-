#!/usr/bin/env bash

# Exit on error
set -o errexit

echo "🚀 Starting build process..."

# Install dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Generate app key if not exists
if [ -z "$(grep '^APP_KEY=' .env 2>/dev/null)" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force
fi

# Generate JWT secret if not exists
if [ -z "$(grep '^JWT_SECRET=' .env 2>/dev/null)" ]; then
    echo "🔐 Generating JWT secret..."
    php artisan jwt:secret --force
fi

# Set up storage
echo "📁 Setting up storage..."
php artisan storage:link --force || true

# Clear and cache
echo "🧹 Clearing cache..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Run database migrations (only if database exists)
echo "🗄️ Checking database..."
if php artisan db:show --quiet 2>/dev/null; then
    echo "✅ Database connection OK"
    echo "🔄 Running migrations..."
    php artisan migrate --force --no-interaction
else
    echo "⚠️ Database not available, skipping migrations"
fi

echo "🎉 Build completed successfully!"
