<?php
/**
 * Validation utility functions for BugRicer
 */

/**
 * Validate email format
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 */
function validatePassword($password) {
    // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$/', $password);
}

/**
 * Validate phone number format
 */
function validatePhone($phone) {
    // Remove all non-digit characters
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    // Check if it's between 10-15 digits
    return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
}

/**
 * Sanitize input string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate username format
 */
function validateUsername($username) {
    // 3-20 characters, alphanumeric and underscores only
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
}

/**
 * Validate token format (for password reset tokens)
 */
function validateToken($token) {
    // 64 character hex string
    return preg_match('/^[a-f0-9]{64}$/', $token);
}

/**
 * Validate JSON input
 */
function validateJsonInput($input) {
    if (empty($input)) {
        return false;
    }
    
    // Check if it's an array (decoded JSON)
    if (!is_array($input)) {
        return false;
    }
    
    return true;
}

/**
 * Validate required fields
 */
function validateRequiredFields($data, $required_fields) {
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $missing_fields[] = $field;
        }
    }
    
    return $missing_fields;
}

/**
 * Validate password confirmation
 */
function validatePasswordConfirmation($password, $confirm_password) {
    return $password === $confirm_password;
}

/**
 * Validate date format
 */
function validateDate($date, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate integer range
 */
function validateIntegerRange($value, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return false;
    }
    
    $int_value = (int)$value;
    
    if ($min !== null && $int_value < $min) {
        return false;
    }
    
    if ($max !== null && $int_value > $max) {
        return false;
    }
    
    return true;
}

/**
 * Validate string length
 */
function validateStringLength($string, $min_length = null, $max_length = null) {
    $length = strlen($string);
    
    if ($min_length !== null && $length < $min_length) {
        return false;
    }
    
    if ($max_length !== null && $length > $max_length) {
        return false;
    }
    
    return true;
}

/**
 * Validate URL format
 */
function validateUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate IP address
 */
function validateIpAddress($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Validate file extension
 */
function validateFileExtension($filename, $allowed_extensions) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowed_extensions);
}

/**
 * Validate file size
 */
function validateFileSize($file_size, $max_size_bytes) {
    return $file_size <= $max_size_bytes;
}

/**
 * Generate validation error message
 */
function getValidationErrorMessage($field, $rule) {
    $messages = [
        'email' => 'Please enter a valid email address',
        'password' => 'Password must be at least 8 characters with uppercase, lowercase, and number',
        'phone' => 'Please enter a valid phone number',
        'username' => 'Username must be 3-20 characters (letters, numbers, underscores only)',
        'token' => 'Invalid token format',
        'required' => 'This field is required',
        'password_confirmation' => 'Passwords do not match',
        'date' => 'Please enter a valid date',
        'integer_range' => 'Value must be within the specified range',
        'string_length' => 'Text length must be within the specified range',
        'url' => 'Please enter a valid URL',
        'ip' => 'Please enter a valid IP address',
        'file_extension' => 'File type not allowed',
        'file_size' => 'File size exceeds the maximum allowed'
    ];
    
    return $messages[$rule] ?? 'Invalid input';
}

/**
 * Comprehensive input validation
 */
function validateInput($data, $rules) {
    $errors = [];
    
    foreach ($rules as $field => $field_rules) {
        $value = $data[$field] ?? '';
        
        foreach ($field_rules as $rule => $rule_value) {
            $is_valid = true;
            
            switch ($rule) {
                case 'required':
                    if (empty(trim($value))) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'email':
                    if (!empty($value) && !validateEmail($value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'password':
                    if (!empty($value) && !validatePassword($value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'phone':
                    if (!empty($value) && !validatePhone($value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'username':
                    if (!empty($value) && !validateUsername($value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'token':
                    if (!empty($value) && !validateToken($value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'min_length':
                    if (!empty($value) && !validateStringLength($value, $rule_value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'max_length':
                    if (!empty($value) && !validateStringLength($value, null, $rule_value)) {
                        $is_valid = false;
                    }
                    break;
                    
                case 'min_max_length':
                    if (!empty($value) && !validateStringLength($value, $rule_value[0], $rule_value[1])) {
                        $is_valid = false;
                    }
                    break;
            }
            
            if (!$is_valid) {
                $errors[$field] = getValidationErrorMessage($field, $rule);
                break; // Stop at first error for this field
            }
        }
    }
    
    return $errors;
}
?>
