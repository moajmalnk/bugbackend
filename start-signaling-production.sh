#!/bin/bash

# BugMeet Signaling Server Production Startup Script
# Alternative to systemd for shared hosting or simpler setups

echo "🚀 Starting BugMeet Signaling Server for Production..."

# Configuration
PORT=${BUGMEET_SIGNAL_PORT:-8089}
LOG_FILE="/tmp/bugmeet-signaling.log"
PID_FILE="/tmp/bugmeet-signaling.pid"

# Change to script directory
cd "$(dirname "$0")"

# Check if composer dependencies are installed
if [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Installing composer dependencies..."
    if [ -f "composer.phar" ]; then
        php composer.phar install --no-dev --optimize-autoloader
    else
        composer install --no-dev --optimize-autoloader
    fi
fi

# Check if server is already running
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "⚠️ BugMeet Signaling Server is already running (PID: $PID)"
        echo "🔄 Stopping existing server..."
        kill "$PID"
        sleep 2
        rm -f "$PID_FILE"
    else
        echo "🧹 Cleaning up stale PID file..."
        rm -f "$PID_FILE"
    fi
fi

# Start the server in background
echo "▶️ Starting WebSocket server on port $PORT..."
echo "📝 Logs will be written to: $LOG_FILE"

# Use nohup to run in background and redirect output
nohup php api/meetings/signaling-server.php > "$LOG_FILE" 2>&1 &
SERVER_PID=$!

# Save PID
echo $SERVER_PID > "$PID_FILE"

# Wait a moment and check if server started successfully
sleep 3

if kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "✅ BugMeet Signaling Server started successfully!"
    echo "🆔 Process ID: $SERVER_PID"
    echo "🌐 WebSocket server listening on port $PORT"
    echo "📝 Log file: $LOG_FILE"
    echo "🆔 PID file: $PID_FILE"
    
    # Test if port is listening
    if command -v netstat >/dev/null 2>&1; then
        echo ""
        echo "🔍 Checking if port $PORT is listening..."
        netstat -tlnp 2>/dev/null | grep ":$PORT " || echo "⚠️ Port check failed (netstat not available or permission denied)"
    fi
    
    echo ""
    echo "🎉 Server is ready for connections!"
    echo ""
    echo "Useful commands:"
    echo "  View logs:    tail -f $LOG_FILE"
    echo "  Stop server:  kill $SERVER_PID && rm -f $PID_FILE"
    echo "  Check status: ps aux | grep signaling-server"
    
else
    echo "❌ Failed to start BugMeet Signaling Server!"
    echo "📝 Check logs: cat $LOG_FILE"
    rm -f "$PID_FILE"
    exit 1
fi

echo ""
echo "🔧 Production Notes:"
echo "1. Ensure port $PORT is open in your firewall"
echo "2. Configure your web server to proxy WebSocket connections if needed"
echo "3. Monitor the log file for any errors"
echo "4. Consider using a process manager like PM2 for production"
