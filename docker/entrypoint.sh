#!/bin/bash
set -e

# ==============================================================================
# Container Initialization
# ==============================================================================

APP_ROLE=${APP_ROLE:-api}

echo "Starting container in role: $APP_ROLE"

# ==============================================================================
# Local Development Environment Setup (only if BUILD_TARGET=local)
# ==============================================================================
if [ "$APP_ROLE" = "api" ] && [ "$BUILD_TARGET" = "local" ]; then
    echo ""
    echo "🔧 Local development environment setup..."

    # Setup .env file
    if [ ! -f /app/.env ]; then
        echo "  → Creating .env from .env.example..."
        cp /app/.env.example /app/.env
        echo "  ✓ .env file created"
    else
        echo "  ✓ .env file already exists"
    fi

    # Install/update Composer dependencies
    echo ""
    echo "📦 Installing/verifying Composer dependencies..."
    composer install --optimize-autoloader --no-interaction
    echo "  ✓ Composer dependencies up to date"

    # Install/update NPM dependencies
    echo ""
    echo "📦 Installing/verifying NPM dependencies..."
    npm install
    echo "  ✓ NPM dependencies up to date"

    echo ""
    echo "🔑 Checking application key..."
    if grep -qE '^APP_KEY=.+' /app/.env; then
        echo "  ✓ APP_KEY already set"
    else
        echo "  → Generating application key..."
        php artisan key:generate --force
        echo "  ✓ APP_KEY generated"
    fi

    echo ""
    echo "🎨 Checking front-end assets..."
    if [ ! -d /app/public/build ]; then
        echo "  → Building assets..."
        npm run build
        echo "  ✓ Assets built"
    else
        echo "  ✓ Assets already built"
    fi

    # Create storage link if it doesn't exist
    echo ""
    echo "🔗 Checking storage link..."
    if [ ! -L /app/public/storage ]; then
        echo "  → Creating storage link..."
        php artisan storage:link
        echo "  ✓ Storage link created"
    else
        echo "  ✓ Storage link already exists"
    fi

    echo ""
    echo "✅ Local development environment setup complete!"

elif [ "$BUILD_TARGET" = "local" ]; then
    # Other roles wait for the api container to finish provisioning. The app key
    # is the last thing it writes that these roles need.
    echo ""
    echo "⏳ Waiting for dependencies to be installed by the app container..."
    while [ ! -f /app/vendor/autoload.php ] || ! grep -qE '^APP_KEY=.+' /app/.env 2>/dev/null; do
        echo "  Waiting for dependencies..."
        sleep 2
    done
    echo "  ✓ Dependencies ready!"
    echo ""
fi

# ==============================================================================
# Process nginx templates (api role only)
# ==============================================================================
if [ "$APP_ROLE" = "api" ]; then
    echo ""
    echo "📝 Processing nginx templates..."

    # Set default domain values if not provided
    export DOMAIN_APP=${DOMAIN_APP:-starter.local}

    mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

    for template in /etc/nginx/templates/*.template; do
        if [ -f "$template" ]; then
            filename=$(basename "$template" .template)
            output="/etc/nginx/sites-available/$filename"

            echo "  → $(basename "$template") → $filename"

            envsubst '${DOMAIN_APP}' < "$template" > "$output"
            ln -sf "$output" "/etc/nginx/sites-enabled/$filename"
        fi
    done

    echo ""
    echo "🔍 Validating nginx configuration..."
    if nginx -t 2>&1; then
        echo "  ✓ nginx configuration is valid."
    else
        echo "  ✗ nginx configuration validation failed!"
        exit 1
    fi
    echo ""
fi

# ==============================================================================
# Laravel caches (release only). Safe here as long as the codebase reads env()
# only inside config/; local skips it so edits take effect without a rebuild.
# ==============================================================================
if [ "$BUILD_TARGET" != "local" ] && [ "$APP_ROLE" != "migrate" ]; then
    echo "⚡ Building Laravel caches..."
    php artisan optimize
    echo "  ✓ Caches built"
    echo ""
fi

# ==============================================================================
# Setup Laravel Scheduler (installed on ALL roles; only the scheduler role runs cron)
# ==============================================================================
echo "⏰ Setting up Laravel Scheduler cron..."
crontab /etc/cron.d/scheduler
echo "  ✓ Laravel Scheduler cron configured"

# ==============================================================================
# Setup the container role
# ==============================================================================
case "$APP_ROLE" in
    api)
        echo "🚀 Starting API (nginx + Octane + Supervisor)..."
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/api.conf
        ;;

    scheduler)
        echo "⏰ Starting Scheduler (Laravel Schedule)..."
        chown -R www-data:www-data /app/storage /app/bootstrap/cache
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/scheduler.conf
        ;;

    worker)
        echo "⚙️ Starting Worker (Laravel Horizon)..."
        chown -R www-data:www-data /app/storage /app/bootstrap/cache
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/worker.conf
        ;;

    migrate)
        echo "🗄️ Running migrations..."
        exec php artisan migrate --force
        ;;

    *)
        echo "❌ Error: Unknown role '$APP_ROLE'"
        echo "Available roles: api, scheduler, worker, migrate"
        exit 1
        ;;
esac
