#!/bin/bash

# Deploy Google Login file to production
# This script helps deploy the google-login.php file to production

echo "🚀 Deploying Google Login to Production..."

# Check if the file exists locally
if [ ! -f "api/auth/google-login.php" ]; then
    echo "❌ Error: google-login.php file not found locally"
    exit 1
fi

echo "✅ Local file found: api/auth/google-login.php"

# Instructions for manual deployment
echo ""
echo "📋 MANUAL DEPLOYMENT INSTRUCTIONS:"
echo "=================================="
echo ""
echo "1. Access your production server (cPanel, FTP, or SSH)"
echo "2. Navigate to: /public_html/api/auth/ (or your backend directory)"
echo "3. Upload the file: api/auth/google-login.php"
echo "4. Set proper permissions: chmod 644 google-login.php"
echo ""
echo "🔗 Production URL should be:"
echo "https://bugbackend.bugricer.com/api/auth/google-login.php"
echo ""
echo "🧪 Test the endpoint after deployment:"
echo "curl -I https://bugbackend.bugricer.com/api/auth/google-login.php"
echo ""

# Create a compressed file for easy upload
echo "📦 Creating deployment package..."
tar -czf google-login-deployment.tar.gz api/auth/google-login.php
echo "✅ Created: google-login-deployment.tar.gz"
echo ""
echo "📤 Upload this file to your production server and extract it:"
echo "tar -xzf google-login-deployment.tar.gz"
echo ""

echo "🎯 After deployment, test the endpoint:"
echo "curl -X OPTIONS https://bugbackend.bugricer.com/api/auth/google-login.php"
echo "curl -X POST https://bugbackend.bugricer.com/api/auth/google-login.php"
echo ""

echo "✨ Deployment instructions complete!"
