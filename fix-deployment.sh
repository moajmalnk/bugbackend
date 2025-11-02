#!/bin/bash

echo "🔧 Fixing Deployment Issues..."
echo "==============================="

# Check if we're in the right directory
if [ ! -f "composer.json" ]; then
    echo "❌ Error: composer.json not found. Please run this script from the backend directory."
    exit 1
fi

echo "✅ Found composer.json"

# Check Git status
echo ""
echo "📋 Git Status:"
git status --short

# Check if there are diverged commits
echo ""
echo "🔍 Checking for diverged commits..."
git fetch origin
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" != "$REMOTE" ]; then
    echo "⚠️  Local and remote have diverged"
    echo "🔄 Resetting to match remote repository..."
    git reset --hard origin/main
    git clean -fd
    echo "✅ Repository reset complete"
else
    echo "✅ Repository is up to date"
fi

# Check Composer
echo ""
echo "📦 Checking Composer..."
if command -v composer &> /dev/null; then
    echo "✅ Composer found: $(composer --version)"
else
    echo "❌ Composer not found. Please install Composer first."
    exit 1
fi

# Install dependencies with different strategies
echo ""
echo "📦 Installing dependencies..."

# Try with increased memory limit
echo "🔄 Trying with increased memory limit..."
php -d memory_limit=512M composer install --no-dev --optimize-autoloader

if [ $? -eq 0 ]; then
    echo "✅ Dependencies installed successfully"
else
    echo "⚠️  First attempt failed, trying alternative method..."
    composer install --no-dev --no-scripts --optimize-autoloader
    if [ $? -eq 0 ]; then
        echo "✅ Dependencies installed successfully (alternative method)"
    else
        echo "❌ Failed to install dependencies. Please check your PHP version and Composer configuration."
        exit 1
    fi
fi

# Set proper permissions
echo ""
echo "🔐 Setting proper permissions..."
chmod -R 755 .
chmod -R 777 uploads/ 2>/dev/null || echo "⚠️  uploads/ directory not found"

echo ""
echo "🎉 Deployment fix complete!"
echo ""
echo "📋 Next steps:"
echo "1. Test your endpoints:"
echo "   curl -I https://bugbackend.bugricer.com/api/auth/google-login.php"
echo ""
echo "2. Check if Google Sign-In works on your frontend"
echo ""
echo "3. If issues persist, check server logs for more details"