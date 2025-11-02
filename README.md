# BugRicer Backend API

A robust, enterprise-grade RESTful API backend built with PHP for the BugRicer bug tracking and project management platform. This API provides comprehensive functionality for bug tracking, user management, real-time messaging, time tracking, and more.

## 📋 Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Architecture](#api-architecture)
- [Authentication & Authorization](#authentication--authorization)
- [API Endpoints](#api-endpoints)
- [Database Schema](#database-schema)
- [Performance Optimizations](#performance-optimizations)
- [Security](#security)
- [Development](#development)
- [Deployment](#deployment)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## ✨ Features

### Core Functionality
- **Bug Tracking System**: Comprehensive bug reporting, assignment, and resolution workflow
- **Project Management**: Multi-project support with member management and role-based access
- **User Management**: Role-based user system (Admin, Developer, Tester) with granular permissions
- **Real-time Messaging**: In-app messaging system with support for text, files, and voice notes
- **Activity Logging**: Comprehensive audit trail for all user activities
- **Time Tracking**: Check-in/check-out system with session management
- **Task Management**: Full task lifecycle management with assignment and tracking
- **Meeting Management**: Video conferencing integration and meeting scheduling
- **Notifications**: Real-time push notifications via Firebase Cloud Messaging (FCM)
- **Announcements**: System-wide and project-specific announcements
- **Feedback System**: User feedback collection and management
- **Documentation**: Google Docs integration for bug documentation

### Advanced Features
- **Multi-factor Authentication**: OTP-based and magic link authentication
- **OAuth Integration**: Google OAuth 2.0 for seamless login
- **Admin Impersonation**: Secure user impersonation for support
- **Voice Notes**: Voice recording and playback functionality
- **File Attachments**: Support for screenshots, documents, and media files
- **Permission System**: Granular permission-based access control
- **Activity Analytics**: Weekly and project-based activity statistics
- **Update Tracking**: Changelog and update notification system

## 🛠 Technology Stack

### Core Technologies
- **PHP 7.4+**: Server-side scripting language
- **MySQL 5.7+**: Relational database management system
- **PDO**: PHP Data Objects for database abstraction
- **Apache/Nginx**: Web server (Apache with XAMPP for local development)

### Dependencies (Composer)
- **firebase/php-jwt** (^6.0): JSON Web Token implementation for authentication
- **phpmailer/phpmailer** (^6.10): Email sending capabilities
- **google/apiclient** (^2.18): Google API integration (Docs, OAuth, Calendar)

### External Services
- **Firebase Cloud Messaging (FCM)**: Push notification service
- **Google OAuth 2.0**: Authentication and authorization
- **Google Docs API**: Document management and templates

## 📦 System Requirements

### Minimum Requirements
- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.2 or higher
- Apache 2.4+ or Nginx 1.18+
- Composer 2.0+
- OpenSSL extension (for JWT)
- PDO MySQL extension
- mbstring extension
- JSON extension
- cURL extension (for OAuth and external APIs)

### Recommended
- PHP 8.0+ for better performance
- MySQL 8.0+ for enhanced features
- 512MB+ PHP memory limit
- SSL/TLS certificate for production

## 🚀 Installation

### 1. Clone Repository
```bash
git clone <repository-url>
cd BugRicer/backend
```

### 2. Install Dependencies
```bash
# Using Composer (recommended)
composer install

# Or using the bundled composer.phar
php composer.phar install
```

### 3. Database Setup
```bash
# Create database (if not exists)
mysql -u root -p < sql/database.sql

# Or import the full schema
mysql -u root -p < sql/currentdb.sql
```

### 4. Configure Environment
Create a `.env` file in the `backend` directory:
```env
# Database Configuration
DB_HOST=localhost
DB_NAME=u262074081_bugfixer_db
DB_USER=root
DB_PASS=

# Google OAuth (Optional)
GOOGLE_CLIENT_ID=your_client_id_here
GOOGLE_CLIENT_SECRET=your_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost/BugRicer/backend/api/oauth/callback

# JWT Secret (if not using default)
JWT_SECRET=your_jwt_secret_key_here
```

### 5. Configure Web Server

#### Apache (XAMPP)
The `.htaccess` file is already configured. Ensure:
- `mod_rewrite` is enabled
- `AllowOverride All` is set in `httpd.conf`

#### Nginx
```nginx
location /api/ {
    try_files $uri $uri/ /api/404.php;
    fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### 6. Set Permissions
```bash
# Create uploads directory structure
mkdir -p uploads/files uploads/screenshots uploads/voice_notes
chmod -R 755 uploads/

# Ensure write permissions for logs (if logging enabled)
mkdir -p logs
chmod 755 logs/
```

### 7. Verify Installation
```bash
# Health check endpoint
curl http://localhost/BugRicer/backend/api/health.php
```

Expected response:
```json
{
  "success": true,
  "status": "healthy",
  "database": "connected",
  "server": {
    "php_version": "8.0.x",
    "server_time": "2024-01-01T00:00:00+00:00"
  }
}
```

## ⚙️ Configuration

### Database Configuration
Edit `config/database.php` to customize database settings:
- **Connection Pooling**: Automatic connection pooling for better performance
- **Caching**: Built-in query result caching (5-minute default TTL)
- **Environment Detection**: Automatic local/production environment detection
- **Persistent Connections**: Enabled by default for efficiency

### CORS Configuration
Edit `config/cors.php` to manage allowed origins:
```php
$allowedOrigins = [
    'http://localhost:8080',
    'http://localhost:3000',
    'https://bugricer.com',
    // Add your domains
];
```

### JWT Configuration
JWT secret and expiration settings are managed in `config/utils.php` or environment variables.

## 🏗 API Architecture

### Base Classes

#### BaseAPI
The foundation class for all API endpoints:
```php
require_once __DIR__ . '/BaseAPI.php';

class MyController extends BaseAPI {
    public function handleRequest() {
        // Automatic database connection
        // Automatic CORS handling
        // Token validation available via $this->validateToken()
    }
}
```

**Key Features:**
- Automatic database connection management
- Built-in caching system
- Token validation and user authentication
- Permission checking via `requirePermission()`
- Standardized JSON response formatting
- Request data parsing (JSON/POST)
- Error handling and logging

#### OptimizedBaseAPI
Extended version with enhanced performance features:
- Advanced memory caching
- Query optimization
- Connection pooling
- Batch query execution
- Cache statistics

### Controller Pattern
Each feature module follows a consistent controller pattern:
```
api/
  bugs/
    BugController.php    # Main controller
    create.php           # Endpoint handler
    getAll.php           # Endpoint handler
    ...
```

### Response Format
All API responses follow a consistent structure:
```json
{
  "success": true|false,
  "message": "Human-readable message",
  "data": { /* Response data */ }
}
```

## 🔐 Authentication & Authorization

### JWT Authentication
The API uses JSON Web Tokens (JWT) for stateless authentication:

1. **Login** → Receive JWT token
2. **Include in Requests** → `Authorization: Bearer <token>`
3. **Token Validation** → Automatic validation via `BaseAPI::validateToken()`

### Authentication Methods
- **Email/Password**: Traditional credentials
- **Google OAuth**: Single Sign-On via Google
- **Magic Link**: Passwordless email-based authentication
- **OTP**: SMS/Email-based one-time password
- **Token Refresh**: Automatic token refresh mechanism

### Permission System
Granular permission-based access control:

```php
// Check permission in controller
$this->requirePermission('BUGS_CREATE', $projectId);

// Permission scopes:
// - GLOBAL: System-wide permissions
// - PROJECT: Project-specific permissions
```

**Permission Structure:**
- **Role Permissions**: Default permissions per role (Admin, Developer, Tester)
- **User Permissions**: Individual user permission overrides
- **Super Admin**: Bypass all permission checks

### Admin Impersonation
Secure user impersonation for support purposes:
```php
// Via header
X-Impersonate-User: <user_id>

// Via query parameter
?impersonate=<user_id>
```

## 📡 API Endpoints

### Authentication (`/api/auth/`)
- `POST /login.php` - User login
- `POST /register.php` - User registration
- `POST /forgot_password.php` - Password reset request
- `POST /reset_password.php` - Reset password
- `POST /send_otp.php` - Send OTP
- `POST /verify_otp.php` - Verify OTP
- `POST /send_magic_link.php` - Send magic link
- `GET /verify_magic_link.php` - Verify magic link
- `POST /refresh_token.php` - Refresh JWT token
- `GET /me.php` - Get current user info

### Bugs (`/api/bugs/`)
- `GET /getAll.php` - List all bugs (with filters)
- `GET /get.php?id={id}` - Get bug details
- `POST /create.php` - Create new bug
- `PUT /update.php` - Update bug
- `DELETE /delete.php` - Delete bug
- `DELETE /delete_image.php` - Delete bug screenshot

### Projects (`/api/projects/`)
- `GET /getAll.php` - List all projects
- `GET /get.php?id={id}` - Get project details
- `POST /create.php` - Create new project
- `PUT /update.php` - Update project
- `DELETE /delete.php` - Delete project
- `POST /add_member.php` - Add project member
- `DELETE /remove_member.php` - Remove project member
- `GET /get_members.php` - Get project members

### Activities (`/api/activities/`)
- `GET /getAll.php` - List all activities
- `GET /project_activities.php?project_id={id}` - Project activities
- `GET /weekly.php` - Weekly activity statistics
- `GET /activity_stats.php` - Activity analytics
- `POST /log_activity.php` - Log new activity

### Messaging (`/api/messaging/`)
- `GET /conversations.php` - List conversations
- `GET /messages.php?conversation_id={id}` - Get messages
- `POST /send.php` - Send message
- `POST /upload.php` - Upload file attachment
- `GET /voice_notes.php` - Get voice notes

### Tasks (`/api/tasks/`)
- `GET /getAll.php` - List all tasks
- `GET /get.php?id={id}` - Get task details
- `POST /create.php` - Create new task
- `PUT /update.php` - Update task
- `DELETE /delete.php` - Delete task

### Time Tracking (`/api/time-tracking/`)
- `POST /check-in.php` - Check in
- `POST /check-out.php` - Check out
- `POST /pause.php` - Pause session
- `POST /resume.php` - Resume session
- `GET /current-session.php` - Get current session
- `GET /session-history.php` - Get session history

### Meetings (`/api/meetings/`)
- `GET /list.php` - List meetings
- `POST /create.php` - Create meeting
- `POST /join.php` - Join meeting
- `POST /leave.php` - Leave meeting
- `GET /messages.php` - Get meeting messages

### Users (`/api/users/`)
- `GET /getAll.php` - List all users
- `GET /get.php?id={id}` - Get user details
- `PUT /update.php` - Update user
- `DELETE /delete.php` - Delete user
- `GET /get_all_admins.php` - List admins
- `GET /get_all_developers.php` - List developers
- `GET /get_all_testers.php` - List testers

### Announcements (`/api/announcements/`)
- `GET /getAll.php` - List all announcements
- `GET /get_latest.php` - Get latest announcements
- `POST /create.php` - Create announcement
- `PUT /update.php` - Update announcement
- `DELETE /delete.php` - Delete announcement
- `POST /broadcast.php` - Broadcast announcement

### Notifications (`/api/notifications/`)
- `GET /get_recent.php` - Get recent notifications
- `POST /broadcast.php` - Broadcast notification

### Feedback (`/api/feedback/`)
- `POST /submit.php` - Submit feedback
- `GET /stats.php` - Get feedback statistics
- `PUT /status.php` - Update feedback status
- `DELETE /delete.php` - Delete feedback

### Health & Utilities
- `GET /health.php` - Health check endpoint
- `GET /image.php` - Serve images
- `GET /audio.php` - Serve audio files
- `GET /get_attachment.php` - Serve file attachments

## 🗄 Database Schema

The database uses a normalized relational structure with the following key tables:

### Core Tables
- **users**: User accounts and profiles
- **projects**: Project information
- **bugs**: Bug reports and details
- **tasks**: Task management
- **activities**: Activity logs
- **messages**: Messaging system
- **conversations**: Conversation threads

### Supporting Tables
- **permissions**: Permission definitions
- **role_permissions**: Role-permission mappings
- **user_permissions**: User-specific permission overrides
- **project_members**: Project membership
- **notifications**: Notification records
- **announcements**: System announcements
- **time_sessions**: Time tracking sessions
- **meetings**: Meeting records
- **feedback**: User feedback

### Schema Management
- Initial schema: `sql/database.sql`
- Current schema dump: `sql/currentdb.sql`
- Migrations: `api/run_migration.php`

## ⚡ Performance Optimizations

### Database Optimizations
- **Connection Pooling**: Reuse database connections
- **Query Caching**: Cache frequently accessed data (5-minute TTL)
- **Prepared Statements**: Protection against SQL injection + performance
- **Indexing**: Properly indexed tables for fast queries
- **Batch Operations**: Execute multiple queries in transactions

### Application Optimizations
- **Memory Caching**: In-memory cache for repeated queries
- **Lazy Loading**: Load data only when needed
- **Pagination**: Built-in pagination for list endpoints
- **Response Compression**: JSON responses optimized for size
- **Eager Loading**: Load related data in single queries

### Caching Strategy
```php
// Automatic caching (5 minutes)
$result = $this->fetchCached($query, $params, 'cache_key');

// Custom timeout
$result = $this->fetchCached($query, $params, 'cache_key', 3600);

// Clear cache
$this->clearCache('cache_key_pattern');
```

## 🔒 Security

### Security Features
- **SQL Injection Prevention**: PDO prepared statements
- **XSS Protection**: Input sanitization and output escaping
- **CSRF Protection**: Token-based validation (where applicable)
- **JWT Security**: Secure token generation and validation
- **Password Hashing**: Bcrypt/Argon2 password hashing
- **Rate Limiting**: Implemented at application level
- **CORS Configuration**: Strict origin validation
- **Input Validation**: Comprehensive input validation

### Best Practices
- Never log sensitive data (passwords, tokens)
- Use HTTPS in production
- Regular security audits
- Keep dependencies updated
- Follow OWASP guidelines

## 💻 Development

### Project Structure
```
backend/
├── api/                    # API endpoints
│   ├── auth/              # Authentication endpoints
│   ├── bugs/              # Bug management
│   ├── projects/          # Project management
│   └── ...                # Other modules
├── config/                # Configuration files
│   ├── database.php       # Database configuration
│   ├── cors.php          # CORS settings
│   └── environment.php   # Environment variables
├── sql/                   # Database schemas
├── uploads/              # User uploads
│   ├── files/
│   ├── screenshots/
│   └── voice_notes/
├── utils/                 # Utility functions
├── vendor/                # Composer dependencies
└── composer.json         # Dependencies manifest
```

### Development Workflow
1. **Local Development**: Use XAMPP or similar local server
2. **Database**: Use local MySQL instance
3. **Environment**: Automatic detection of local vs production
4. **Debugging**: Error logging via `error_log()`

### Code Standards
- **PSR-12**: PHP coding standard
- **Naming**: PascalCase for classes, camelCase for methods
- **Documentation**: PHPDoc comments for all public methods
- **Error Handling**: Try-catch blocks with proper logging

### Adding New Endpoints
1. Create controller class extending `BaseAPI`
2. Implement endpoint handler file
3. Add routing logic (if needed)
4. Implement permission checks
5. Test thoroughly
6. Update API documentation

Example:
```php
// api/myfeature/MyController.php
require_once __DIR__ . '/../BaseAPI.php';

class MyController extends BaseAPI {
    public function handleRequest() {
        $this->validateToken();
        $this->requirePermission('FEATURE_ACCESS');
        
        $data = $this->getRequestData();
        // Process request
        $this->sendJsonResponse(200, "Success", $result);
    }
}
```

## 🚢 Deployment

### Production Checklist
- [ ] Update database credentials in `config/database.php`
- [ ] Set production environment variables
- [ ] Configure CORS for production domains
- [ ] Enable HTTPS/SSL
- [ ] Set up proper file permissions
- [ ] Configure error logging
- [ ] Set up backup strategy
- [ ] Configure firewall rules
- [ ] Set PHP memory limits appropriately
- [ ] Disable debug mode

### Environment Variables
Production environment should set:
```env
DB_HOST=your_production_host
DB_NAME=your_production_db
DB_USER=your_production_user
DB_PASS=your_secure_password
GOOGLE_CLIENT_ID=production_client_id
GOOGLE_CLIENT_SECRET=production_client_secret
```

### Server Configuration
- **PHP Settings**: 
  - `memory_limit`: 256M minimum
  - `upload_max_filesize`: 10M
  - `post_max_size`: 10M
  - `max_execution_time`: 30

### Backup Strategy
```bash
# Database backup
mysqldump -u user -p database_name > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf uploads_backup_$(date +%Y%m%d).tar.gz uploads/
```

## 🧪 Testing

### Manual Testing
- Use Postman or similar tool
- Test each endpoint with valid/invalid data
- Verify authentication and authorization
- Test error handling

### Health Check
```bash
curl http://your-domain/api/health.php
```

### API Testing Example
```bash
# Login
curl -X POST http://localhost/api/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Use token
curl http://localhost/api/bugs/getAll.php \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## 🔧 Troubleshooting

### Common Issues

#### Database Connection Failed
- Check database credentials in `config/database.php`
- Verify MySQL service is running
- Check database name exists
- Verify user permissions

#### CORS Errors
- Update allowed origins in `config/cors.php`
- Check server headers configuration
- Verify preflight OPTIONS requests

#### JWT Token Invalid
- Check token expiration
- Verify JWT secret key
- Ensure token format is correct (`Bearer <token>`)
- Check server time synchronization

#### File Upload Issues
- Check `uploads/` directory permissions
- Verify PHP upload settings
- Check `post_max_size` and `upload_max_filesize`
- Ensure sufficient disk space

#### Permission Denied
- Verify user role and permissions
- Check project membership
- Review `PermissionManager` logic
- Check for user permission overrides

### Debug Mode
Enable debug logging by adding to your endpoint:
```php
error_log("Debug: " . json_encode($data));
```

### Log Files
- PHP Error Log: Check server error logs
- Application Logs: `logs/` directory (if enabled)
- Apache Logs: `/var/log/apache2/error.log` (varies by OS)

## 📚 Additional Resources

### API Documentation
- Check individual endpoint files for detailed documentation
- Review controller classes for method documentation
- See `api/docs/` for additional documentation

### Related Documentation
- [Frontend README](../frontend/README.md)
- Database schema: `sql/currentdb.sql`
- Environment setup: `config/environment.php`

## 📝 License

[Specify your license here]

## 🤝 Contributing

[Add contribution guidelines if applicable]

## 📞 Support

For issues, questions, or contributions:
- Create an issue in the repository
- Contact the development team
- Review existing documentation

---

**Built with ❤️ for efficient bug tracking and project management**

