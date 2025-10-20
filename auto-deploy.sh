#!/bin/bash

# Auto-deployment script for Hostinger
# This script pulls code and automatically extracts vendor.zip

echo "🚀 Auto-deploying to production..."

# Navigate to backend directory
cd /path/to/bugricer/backend

# Pull latest code
echo "📥 Pulling latest code..."
git pull origin main

# Extract vendor.zip if it exists
if [ -f "vendor.zip" ]; then
    echo "📦 Extracting vendor.zip..."
    unzip -o vendor.zip  # -o overwrites existing files
    echo "✅ Vendor folder extracted successfully"
else
    echo "⚠️  vendor.zip not found!"
fi

# Set proper permissions
chmod -R 755 .
chmod -R 777 uploads/

echo "✅ Auto-deployment complete!"
echo "🎯 BugDocs should now work with all dependencies"
