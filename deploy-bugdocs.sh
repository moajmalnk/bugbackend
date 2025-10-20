#!/bin/bash

# BugDocs Production Deployment Script
# This script deploys the BugDocs feature to production

echo "🚀 Starting BugDocs deployment to production..."

# Check if we're in the backend directory
if [ ! -f "config/bugdocs_full_schema.sql" ]; then
    echo "❌ Error: Please run this script from the backend directory"
    exit 1
fi

# 1. Apply database schema (if not already applied)
echo ""
echo "📊 Step 1: Checking database schema..."
mysql -u root -p u262074081_bugfixer_db -e "SELECT COUNT(*) FROM doc_templates;" 2>/dev/null
if [ $? -ne 0 ]; then
    echo "Creating BugDocs tables..."
    mysql -u root -p u262074081_bugfixer_db < config/bugdocs_full_schema.sql
    echo "✅ Database schema applied"
else
    echo "✅ Database tables already exist"
fi

# 2. Check if .env file exists
echo ""
echo "🔧 Step 2: Checking environment configuration..."
if [ ! -f ".env" ]; then
    echo "⚠️  .env file not found!"
    echo "Please create .env file manually with your Google OAuth credentials."
    echo "You can copy .env.example and fill in your credentials:"
    echo ""
    echo "  cp .env.example .env"
    echo "  nano .env  # or use your preferred editor"
    echo ""
    echo "Required variables:"
    echo "  - GOOGLE_CLIENT_ID"
    echo "  - GOOGLE_CLIENT_SECRET"
    echo "  - GOOGLE_REDIRECT_URI (optional, auto-detected)"
    echo ""
    read -p "Press Enter after creating .env file, or Ctrl+C to exit..."
else
    echo "✅ .env file already exists"
fi

# 3. Check vendor dependencies
echo ""
echo "📦 Step 3: Checking PHP dependencies..."
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    if command -v composer &> /dev/null; then
        composer install --no-dev --optimize-autoloader
        echo "✅ Composer dependencies installed"
    else
        echo "⚠️  Composer not found. Please install dependencies manually."
    fi
else
    echo "✅ Composer dependencies already installed"
fi

# 4. Check file permissions
echo ""
echo "🔐 Step 4: Setting file permissions..."
chmod 644 .env 2>/dev/null
chmod 755 api/docs/*.php 2>/dev/null
chmod 755 api/oauth/*.php 2>/dev/null
echo "✅ File permissions set"

# 5. Test endpoint availability
echo ""
echo "🧪 Step 5: Testing endpoints..."
echo "Testing: /api/docs/list-templates.php"
curl -s -o /dev/null -w "Status: %{http_code}\n" "https://bugbackend.bugricer.com/api/docs/list-templates.php" -H "Authorization: Bearer test" || echo "⚠️  Endpoint test skipped (no curl)"

echo ""
echo "✅ BugDocs deployment complete!"
echo ""
echo "📋 Next steps:"
echo "1. Make sure Google OAuth is configured in Google Cloud Console"
echo "2. Add https://bugbackend.bugricer.com/api/oauth/callback to authorized redirect URIs"
echo "3. Test the feature at https://bugs.bugricer.com/admin/bugdocs"
echo ""
echo "🎉 Happy documenting!"

