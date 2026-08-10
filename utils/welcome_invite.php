<?php
/**
 * Why: New-hire welcome emails use a one-click invite so users skip the login
 * form and land in the app (onboarding popup shows when required).
 */

require_once __DIR__ . '/../config/utils.php';
require_once __DIR__ . '/whatsapp.php';

function br_create_welcome_login_url(
    string $userId,
    string $username,
    string $role,
    int $ttlSeconds = 604800
): string {
    $token = Utils::generateWelcomeInviteJWT($userId, $username, $role, $ttlSeconds);
    $base = rtrim(getFrontendBaseUrl(), '/');
    return $base . '/login?welcome_token=' . rawurlencode($token);
}
