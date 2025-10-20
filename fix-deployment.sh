#!/bin/bash

# Fix deployment divergent branches issue
# Run this on production to configure Git for automatic deployments

echo "🔧 Fixing deployment configuration..."

# Navigate to the project directory
cd /path/to/bugricer/backend

# Configure Git to use merge strategy for pulls
echo "Setting Git pull strategy to merge..."
git config pull.rebase false

# Set up automatic deployment configuration
echo "Creating deployment configuration..."
cat > .git/config << 'EOF'
[core]
	repositoryformatversion = 0
	filemode = true
	bare = false
	logallrefupdates = true
[remote "origin"]
	url = https://github.com/moajmalnk/bugbackend.git
	fetch = +refs/heads/*:refs/remotes/origin/*
[branch "main"]
	remote = origin
	merge = refs/heads/main
[pull]
	rebase = false
EOF

echo "✅ Git configuration updated"
echo "✅ Deployment should now work with automatic pulls"

# Test the configuration
echo ""
echo "🧪 Testing Git configuration..."
git config --get pull.rebase
if [ $? -eq 0 ]; then
    echo "✅ Pull strategy configured correctly"
else
    echo "❌ Configuration failed"
    exit 1
fi

echo ""
echo "🎯 Next deployment should work automatically!"
echo "The hosting provider can now do 'git pull origin main' without conflicts"
