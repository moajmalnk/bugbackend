<?php
/**
 * Dedicated OPTIONS responder so preflight never hits login/DB.
 */
require_once __DIR__ . '/../config/cors.php';
http_response_code(204);
exit;
