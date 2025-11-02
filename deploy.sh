#!/bin/bash

# Navigate to the backend directory
cd backend

# Install Composer dependencies
php composer.phar install --no-dev

# Set proper permissions
chmod -R 755 .
chmod -R 777 uploads/

# Create necessary directories if they don't exist
mkdir -p uploads 