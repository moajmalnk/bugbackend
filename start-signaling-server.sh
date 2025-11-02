#!/bin/bash

# Start BugMeet WebSocket Signaling Server
echo "Starting BugMeet WebSocket Signaling Server..."

# Change to the backend directory
cd "$(dirname "$0")"

# Check if composer dependencies are installed
if [ ! -f "vendor/autoload.php" ]; then
    echo "Installing composer dependencies..."
    php composer.phar install
fi

# Set the port (default 8089)
export BUGMEET_SIGNAL_PORT=8089

# Start the WebSocket server
echo "Starting server on port $BUGMEET_SIGNAL_PORT..."
php api/meetings/signaling-server.php
