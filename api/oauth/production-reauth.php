<?php
/**
 * Google OAuth (re-)authorization entry point for Connect Google.
 *
 * Used by every frontend (local and production), because all of them talk to
 * this backend. The caller's JWT (query ?token=) must be valid and belong to
 * ?user_id; return_url must point at a known BugRicer frontend origin.
 */

require_once __DIR__ . '/GoogleOAuthController.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/database.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/**
 * Why: return_url ends up in the OAuth state and the callback redirects to it,
 * so only known frontend origins are accepted (no open redirect).
 */
function br_reauth_is_allowed_return_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    if (in_array($host, ['localhost', '127.0.0.1'], true)) {
        return in_array($parts['scheme'], ['http', 'https'], true);
    }
    $allowedHosts = ['bugs.bugricer.com', 'bugricer.com', 'www.bugricer.com', 'bugs.moajmalnk.in', 'bugracers.vercel.app'];
    return $parts['scheme'] === 'https' && in_array($host, $allowedHosts, true);
}

function br_reauth_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

try {
    $token = $_GET['token'] ?? '';
    $decoded = is_string($token) && $token !== '' ? Utils::validateJWT($token) : false;
    if (!is_object($decoded) || empty($decoded->user_id)) {
        br_reauth_fail(401, 'Your session has expired. Please sign in to BugRicer again, then reconnect Google.');
    }

    $bugricerUserId = (string) $decoded->user_id;
    $requestedUserId = $_GET['user_id'] ?? '';
    if ($requestedUserId !== '' && $requestedUserId !== $bugricerUserId) {
        br_reauth_fail(403, 'You can only connect Google for your own account.');
    }

    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$bugricerUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        br_reauth_fail(404, 'User not found.');
    }

    $returnUrl = $_GET['return_url'] ?? '';
    if (!is_string($returnUrl) || !br_reauth_is_allowed_return_url($returnUrl)) {
        $rolePath = in_array($user['role'], ['admin', 'developer', 'tester'], true) ? $user['role'] : 'admin';
        $returnUrl = rtrim((string) Environment::get('APP_BASE_URL', 'https://bugs.bugricer.com'), '/') . "/{$rolePath}/meet";
    }

    // The OAuth callback is registered for this backend's host only.
    $_SERVER['HTTP_HOST'] = 'bugbackend.bugricer.com';

    // Clear existing tokens so Google issues a fresh refresh token with the current scopes.
    $pdo->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?')->execute([$bugricerUserId]);

    $state = base64_encode(json_encode([
        'jwt_token' => $token,
        'user_id' => $bugricerUserId,
        'return_url' => $returnUrl,
    ]));

    $oauthController = new GoogleOAuthController();
    $authUrl = $oauthController->getAuthorizationUrl($state);

    header('Location: ' . $authUrl, true, 302);
    exit;
} catch (Throwable $e) {
    error_log(json_encode([
        'event' => 'google_oauth.reauth.failed',
        'error' => $e->getMessage(),
    ]));
    br_reauth_fail(500, 'Could not start Google connection. Please try again.');
}
