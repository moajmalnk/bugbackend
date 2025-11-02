module.exports = {
  apps: [
    {
      name: 'bugmeet-signaling',
      script: 'api/meetings/signaling-server.php',
      interpreter: 'php',
      instances: 1,
      exec_mode: 'fork',
      env: {
        BUGMEET_SIGNAL_PORT: 8089,
        NODE_ENV: 'production'
      },
      env_production: {
        BUGMEET_SIGNAL_PORT: 8089,
        NODE_ENV: 'production'
      },
      log_file: '/var/log/bugmeet-signaling/combined.log',
      out_file: '/var/log/bugmeet-signaling/out.log',
      error_file: '/var/log/bugmeet-signaling/error.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      max_memory_restart: '1G',
      restart_delay: 4000,
      max_restarts: 10,
      min_uptime: '10s',
      watch: false,
      ignore_watch: ['node_modules', 'logs'],
      autorestart: true,
      watch_options: {
        followSymlinks: false
      }
    }
  ],

  deploy: {
    production: {
      user: 'www-data',
      host: 'your-server.com',
      ref: 'origin/main',
      repo: 'git@github.com:your-username/bugricer.git',
      path: '/var/www/bugricer',
      'pre-deploy-local': '',
      'post-deploy': 'cd backend && npm install && pm2 reload ecosystem.config.js --env production',
      'pre-setup': ''
    }
  }
};
