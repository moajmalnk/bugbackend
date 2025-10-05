#!/bin/bash

# BugMeet Signaling Server Production Deployment Script
# This script sets up the WebSocket signaling server for production

echo "🚀 Starting BugMeet Signaling Server Production Deployment..."

# Configuration
SERVICE_NAME="bugmeet-signaling"
SERVICE_USER="www-data"
SERVICE_GROUP="www-data"
INSTALL_DIR="/opt/bugmeet-signaling"
LOG_DIR="/var/log/bugmeet-signaling"
PORT=8089

# Create installation directory
echo "📁 Creating installation directory..."
sudo mkdir -p $INSTALL_DIR
sudo mkdir -p $LOG_DIR

# Copy signaling server files
echo "📋 Copying signaling server files..."
sudo cp api/meetings/signaling-server.php $INSTALL_DIR/
sudo cp -r vendor $INSTALL_DIR/
sudo cp composer.json $INSTALL_DIR/
sudo cp composer.lock $INSTALL_DIR/

# Set proper permissions
echo "🔐 Setting permissions..."
sudo chown -R $SERVICE_USER:$SERVICE_GROUP $INSTALL_DIR
sudo chown -R $SERVICE_USER:$SERVICE_GROUP $LOG_DIR
sudo chmod +x $INSTALL_DIR/signaling-server.php

# Create systemd service file
echo "⚙️ Creating systemd service..."
sudo tee /etc/systemd/system/$SERVICE_NAME.service > /dev/null <<EOF
[Unit]
Description=BugMeet WebSocket Signaling Server
After=network.target
Wants=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/signaling-server.php
Restart=always
RestartSec=5
StandardOutput=append:$LOG_DIR/signaling-server.log
StandardError=append:$LOG_DIR/signaling-server-error.log
Environment=BUGMEET_SIGNAL_PORT=$PORT

# Security settings
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$INSTALL_DIR $LOG_DIR

[Install]
WantedBy=multi-user.target
EOF

# Reload systemd and enable service
echo "🔄 Reloading systemd and enabling service..."
sudo systemctl daemon-reload
sudo systemctl enable $SERVICE_NAME

# Start the service
echo "▶️ Starting signaling server..."
sudo systemctl start $SERVICE_NAME

# Check service status
echo "📊 Checking service status..."
sleep 2
if sudo systemctl is-active --quiet $SERVICE_NAME; then
    echo "✅ BugMeet Signaling Server is running successfully!"
    echo "🌐 WebSocket server listening on port $PORT"
    echo "📝 Logs available at: $LOG_DIR/signaling-server.log"
    
    # Show service status
    sudo systemctl status $SERVICE_NAME --no-pager -l
    
    echo ""
    echo "🎉 Deployment completed successfully!"
    echo ""
    echo "Useful commands:"
    echo "  Check status: sudo systemctl status $SERVICE_NAME"
    echo "  View logs:    sudo journalctl -u $SERVICE_NAME -f"
    echo "  Stop service: sudo systemctl stop $SERVICE_NAME"
    echo "  Restart:      sudo systemctl restart $SERVICE_NAME"
else
    echo "❌ Failed to start BugMeet Signaling Server!"
    echo "📝 Check logs: sudo journalctl -u $SERVICE_NAME -f"
    exit 1
fi

echo ""
echo "🔧 Next steps:"
echo "1. Configure firewall to allow port $PORT (if needed)"
echo "2. Update DNS/load balancer to route WebSocket traffic"
echo "3. Test WebSocket connection from your application"
echo "4. Monitor logs for any issues"
