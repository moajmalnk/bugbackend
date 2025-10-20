#!/bin/bash

# Deployment script for Hostinger (preserves vendor folder)
# This script pulls code but keeps the vendor folder intact

echo "🚀 Deploying to production while preserving vendor folder..."

# Navigate to backend directory
cd /path/to/bugricer/backend

# Backup vendor folder if it exists
if [ -d "vendor" ]; then
    echo "📦 Backing up vendor folder..."
    cp -r vendor vendor_backup
fi

# Pull latest code
echo "📥 Pulling latest code..."
git pull origin main

# Restore vendor folder if backup exists
if [ -d "vendor_backup" ]; then
    echo "📦 Restoring vendor folder..."
    rm -rf vendor
    mv vendor_backup vendor
    echo "✅ Vendor folder restored"
else
    echo "⚠️  No vendor folder found. You need to upload vendor.zip and extract it."
    echo "   Run: unzip vendor.zip && rm vendor.zip"
fi

# Set proper permissions
chmod -R 755 .
chmod -R 777 uploads/

echo "✅ Deployment complete!"
echo "📋 Next steps:"
echo "   1. If vendor folder is missing, upload vendor.zip and extract it"
echo "   2. Test BugDocs functionality"
