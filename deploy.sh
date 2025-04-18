#!/bin/bash

# Navigate to the backend directory
cd backend

# Install Composer dependencies
php composer.phar install --no-dev

# Set proper permissions
chmod -R 755 .
chmod -R 777 uploads/
chmod -R 777 logs/

# Create necessary directories if they don't exist
mkdir -p uploads
mkdir -p logs

# Touch log files if they don't exist
touch logs/debug.log
touch logs/error.log
touch logs/activity.log

# Set proper permissions for log files
chmod 666 logs/*.log 