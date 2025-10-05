# BugMeet Signaling Server - Production Deployment Guide

This guide provides multiple options for deploying the BugMeet WebSocket signaling server to production.

## 🚀 Quick Start Options

### Option 1: Simple Background Process (Recommended for shared hosting)

```bash
# Navigate to backend directory
cd /path/to/your/bugricer/backend

# Make script executable
chmod +x start-signaling-production.sh

# Start the server
./start-signaling-production.sh
```

This will:
- Install composer dependencies if needed
- Start the WebSocket server on port 8089
- Run in background with logging
- Create PID file for easy management

### Option 2: Systemd Service (Recommended for VPS/Dedicated servers)

```bash
# Navigate to backend directory
cd /path/to/your/bugricer/backend

# Make script executable
chmod +x deploy-signaling-server.sh

# Run deployment script
sudo ./deploy-signaling-server.sh
```

This will:
- Install as a systemd service
- Auto-start on boot
- Automatic restart on failure
- Centralized logging

### Option 3: PM2 Process Manager (Recommended for Node.js environments)

```bash
# Install PM2 globally
npm install -g pm2

# Navigate to backend directory
cd /path/to/your/bugricer/backend

# Start with PM2
pm2 start ecosystem.config.js --env production

# Save PM2 configuration
pm2 save

# Setup PM2 to start on boot
pm2 startup
```

## 🔧 Configuration

### Environment Variables

You can customize the WebSocket port by setting:

```bash
export BUGMEET_SIGNAL_PORT=8089
```

### Frontend Configuration

The frontend will automatically detect the environment and use the appropriate WebSocket URL:

- **Local development**: `ws://localhost:8089`
- **Production**: `wss://your-domain.com:8089`

You can override this by setting the `VITE_WS_URL` environment variable:

```bash
export VITE_WS_URL=wss://your-custom-domain.com:8089
```

## 🌐 Web Server Configuration

### Apache Configuration

If you're using Apache, you may need to configure a reverse proxy for WebSocket connections:

```apache
# Add to your Apache virtual host configuration
<VirtualHost *:443>
    ServerName your-domain.com
    
    # WebSocket proxy
    ProxyRequests Off
    ProxyPreserveHost On
    
    # WebSocket upgrade headers
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/ws/(.*)$ ws://127.0.0.1:8089/$1 [P,L]
    
    # Regular HTTP proxy for API
    ProxyPass /api/ http://127.0.0.1:8080/api/
    ProxyPassReverse /api/ http://127.0.0.1:8080/api/
    
    # SSL configuration
    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
</VirtualHost>
```

### Nginx Configuration

For Nginx, add this to your server block:

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;
    
    # WebSocket proxy
    location /ws/ {
        proxy_pass http://127.0.0.1:8089/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # Regular API proxy
    location /api/ {
        proxy_pass http://127.0.0.1:8080/api/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## 🔒 Security Considerations

### Firewall Configuration

Make sure port 8089 is accessible:

```bash
# UFW (Ubuntu)
sudo ufw allow 8089

# iptables
sudo iptables -A INPUT -p tcp --dport 8089 -j ACCEPT

# Firewalld (CentOS/RHEL)
sudo firewall-cmd --permanent --add-port=8089/tcp
sudo firewall-cmd --reload
```

### SSL/TLS for WebSocket

For production, you should use WSS (WebSocket Secure) instead of WS:

1. Ensure your domain has a valid SSL certificate
2. The frontend will automatically use `wss://` for HTTPS sites
3. Consider using a reverse proxy with SSL termination

## 📊 Monitoring and Maintenance

### Check Server Status

```bash
# For systemd
sudo systemctl status bugmeet-signaling

# For PM2
pm2 status

# For simple background process
ps aux | grep signaling-server
```

### View Logs

```bash
# For systemd
sudo journalctl -u bugmeet-signaling -f

# For PM2
pm2 logs bugmeet-signaling

# For simple background process
tail -f /tmp/bugmeet-signaling.log
```

### Restart Server

```bash
# For systemd
sudo systemctl restart bugmeet-signaling

# For PM2
pm2 restart bugmeet-signaling

# For simple background process
kill $(cat /tmp/bugmeet-signaling.pid) && ./start-signaling-production.sh
```

## 🐛 Troubleshooting

### Common Issues

1. **Port already in use**: Check if another process is using port 8089
2. **Permission denied**: Ensure the user has permission to bind to port 8089
3. **Connection refused**: Check firewall and network configuration
4. **SSL certificate issues**: Ensure your SSL certificate is valid and includes the WebSocket domain

### Debug Mode

To enable debug logging, you can modify the signaling server:

```php
// Add to signaling-server.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Test WebSocket Connection

You can test the WebSocket connection using a simple HTML file:

```html
<!DOCTYPE html>
<html>
<head>
    <title>WebSocket Test</title>
</head>
<body>
    <div id="status">Connecting...</div>
    <script>
        const ws = new WebSocket('wss://your-domain.com:8089');
        
        ws.onopen = function() {
            document.getElementById('status').innerHTML = 'Connected!';
        };
        
        ws.onerror = function(error) {
            document.getElementById('status').innerHTML = 'Error: ' + error;
        };
        
        ws.onclose = function() {
            document.getElementById('status').innerHTML = 'Disconnected';
        };
    </script>
</body>
</html>
```

## 📞 Support

If you encounter issues:

1. Check the logs for error messages
2. Verify firewall and network configuration
3. Ensure all dependencies are installed
4. Test WebSocket connection manually

For additional help, check the GitHub issues or contact support.
