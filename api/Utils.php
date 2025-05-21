<?php
// Add this to your Utils class
class Utils {
    public function isValidUUID($uuid) {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
    }
}

if (!$userId /*|| !$this->utils->isValidUUID($userId)*/) {
    $this->sendJsonResponse(400, "Invalid user ID format");
    return;
}