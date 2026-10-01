<?php
/**
 * Google Sign-In redirect target (GSI ux_mode="redirect").
 *
 * Google POSTs an HTML form (credential + g_csrf_token) here in the same tab.
 * On success we store a single-use code and 302 back to the frontend login
 * page with ?google_code=..., which the frontend exchanges for a JWT via
 * google-exchange.php. Errors redirect back with ?google_error=<reason>.
 *
 * Query ?return=local sends the browser back to the local dev frontend,
 * because local frontends also talk to this (production) backend.
 */

require_once __DIR__ . '/google_login_lib.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/**
 * Why: the destination is chosen from server config only, never from a URL
 * supplied by the request, so this endpoint cannot be used as an open redirect.
 */
function br_google_frontend_login_url(): string {
    if (($_GET['return'] ?? '') === 'local') {
        $base = Environment::get('LOCAL_FRONTEND_URL', 'http://localhost:8080');
    } else {
        $base = Environment::get('APP_BASE_URL', 'https://bugs.bugricer.com');
    }
    return rtrim((string) $base, '/') . '/login';
}

function br_google_redirect_back(array $params): void {
    header('Location: ' . br_google_frontend_login_url() . '?' . http_build_query($params), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    br_google_redirect_back(['google_error' => 'method_not_allowed']);
}

$credential = $_POST['credential'] ?? '';
if (!is_string($credential) || $credential === '') {
    br_google_redirect_back(['google_error' => 'missing_credential']);
}

// GSI double-submit CSRF check. The cookie is only visible when the frontend
// and this backend share a host; otherwise ID-token verification (signature,
// audience, expiry) is the protection.
$csrfCookie = $_COOKIE['g_csrf_token'] ?? null;
if ($csrfCookie !== null) {
    $csrfBody = $_POST['g_csrf_token'] ?? '';
    if (!is_string($csrfBody) || $csrfBody === '' || !hash_equals((string) $csrfCookie, $csrfBody)) {
        br_google_redirect_back(['google_error' => 'csrf_failed']);
    }
}

$startedAt = microtime(true);
try {
    $payload = br_google_verify_id_token($credential);
    $conn = Database::getInstance()->getConnection();
    $user = br_google_resolve_user($conn, $payload);
    $code = br_google_issue_login_code($conn, $user['id']);

    error_log(json_encode([
        'event' => 'google_login.redirect.code_issued',
        'user_id' => $user['id'],
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ]));

    br_google_redirect_back(['google_code' => $code]);
} catch (GoogleLoginException $e) {
    error_log(json_encode([
        'event' => 'google_login.redirect.failed',
        'reason' => $e->reason,
        'error' => $e->getMessage(),
    ]));
    br_google_redirect_back(['google_error' => $e->reason]);
} catch (Throwable $e) {
    error_log(json_encode([
        'event' => 'google_login.redirect.failed',
        'reason' => 'server_error',
        'error' => $e->getMessage(),
    ]));
    br_google_redirect_back(['google_error' => 'server_error']);
}
