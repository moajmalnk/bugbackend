<?php
// Add this to your Utils class
class Utils {
    public function isValidUUID($uuid) {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
    }

    public static function normalizePhone($phone) {
        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);
        // Ensure it starts with country code, e.g., +91
        if (strlen($digits) === 10) {
            return '+91' . $digits;
        } elseif (strlen($digits) === 12 && strpos($digits, '91') === 0) {
            return '+' . $digits;
        } elseif (strlen($digits) === 13 && strpos($digits, '+91') === 0) {
            return $digits;
        }
        // fallback: return as is
        return $phone;
    }
}

if (!$userId /*|| !$this->utils->isValidUUID($userId)*/) {
    $this->sendJsonResponse(400, "Invalid user ID format");
    return;
}