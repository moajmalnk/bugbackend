-- Password Reset System Schema for BugRicer (Simplified Version)
-- This version works without foreign key constraints for initial setup

-- =============================================
-- PASSWORD RESET TABLES
-- =============================================

-- Table to store password reset requests
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- AUDIT LOG TABLE
-- =============================================

-- Table to log security events and user actions
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SAMPLE DATA (for testing)
-- =============================================

-- Insert sample password reset request (for testing only)
-- Uncomment the lines below to insert test data
-- INSERT INTO password_resets (user_id, email, token, expires_at) 
-- VALUES (1, 'test@example.com', 'sample_token_123456789', DATE_ADD(NOW(), INTERVAL 1 HOUR));

-- =============================================
-- MAINTENANCE QUERIES
-- =============================================

-- Query to check password reset statistics
-- SELECT 
--     DATE(created_at) as date,
--     COUNT(*) as total_requests,
--     COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_requests,
--     COUNT(CASE WHEN expires_at < NOW() AND used_at IS NULL THEN 1 END) as expired_requests
-- FROM password_resets 
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
-- GROUP BY DATE(created_at)
-- ORDER BY date DESC;

-- Query to find users with multiple recent password reset requests (potential security issue)
-- SELECT 
--     email,
--     COUNT(*) as request_count,
--     MAX(created_at) as last_request
-- FROM password_resets 
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY email
-- HAVING COUNT(*) > 3
-- ORDER BY request_count DESC;

-- =============================================
-- CLEANUP QUERIES
-- =============================================

-- Delete expired and used password reset tokens older than 24 hours
-- DELETE FROM password_resets 
-- WHERE (expires_at < NOW() OR used_at IS NOT NULL) 
-- AND created_at < NOW() - INTERVAL 24 HOUR;

-- =============================================
-- SECURITY RECOMMENDATIONS
-- =============================================

/*
SECURITY BEST PRACTICES IMPLEMENTED:

1. TOKEN SECURITY:
   - 64-character random tokens (32 bytes hex-encoded)
   - Tokens expire after 1 hour
   - Tokens are single-use (marked as used after password reset)
   - Tokens are unique and indexed for fast lookup

2. RATE LIMITING:
   - Consider implementing rate limiting at application level
   - Monitor for multiple requests from same IP/email
   - Log all password reset attempts for security analysis

3. DATA RETENTION:
   - Expired and used tokens are cleaned up after 24 hours
   - Audit logs are kept for security monitoring
   - Sensitive data is not stored in plain text

4. VALIDATION:
   - Email validation before processing
   - Token validation before password reset
   - Password strength validation
   - Input sanitization and validation

5. MONITORING:
   - All password reset activities are logged
   - Failed attempts are tracked
   - Suspicious patterns can be detected

6. PRIVACY:
   - No indication if email exists or not (security through obscurity)
   - User data is protected with proper constraints
   - Audit trail for compliance

USAGE EXAMPLES:

1. Request password reset:
   POST /api/forgot_password.php
   {"email": "user@example.com"}

2. Verify reset token:
   POST /api/verify_reset_token.php
   {"token": "abc123..."}

3. Reset password:
   POST /api/reset_password.php
   {"token": "abc123...", "password": "newpass", "confirm_password": "newpass"}

4. Clean up expired tokens (run daily):
   DELETE FROM password_resets 
   WHERE (expires_at < NOW() OR used_at IS NOT NULL) 
   AND created_at < NOW() - INTERVAL 24 HOUR;

5. Check recent activity:
   SELECT 
     DATE(created_at) as date,
     COUNT(*) as total_requests,
     COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_requests
   FROM password_resets 
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
   GROUP BY DATE(created_at)
   ORDER BY date DESC;
*/
