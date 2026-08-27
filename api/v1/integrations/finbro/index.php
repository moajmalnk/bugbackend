<?php
/**
 * Finbro integration front controller.
 *
 * Routes (after /api/ rewrite):
 *   GET v1/integrations/finbro/users/status
 *   GET v1/integrations/finbro/hours
 *   GET v1/integrations/finbro/hours/by-user
 *
 * Always returns JSON (never HTML 500 pages). Finbro maps non-JSON/5xx → 503.
 */

// Suppress HTML error output — Finbro requires JSON-only bodies.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

require_once __DIR__ . '/../../../../config/cors.php';
require_once __DIR__ . '/../../../../utils/finbro_integration.php';

require_once __DIR__ . '/FinbroIntegrationController.php';

/**
 * Resolve route path relative to /v1/integrations/finbro/
 */
function br_finbro_resolve_route(): string
{
    if (!empty($_GET['finbro_route'])) {
        return trim((string)$_GET['finbro_route'], '/');
    }

    $uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $uri = rawurldecode($uri);

    if (preg_match('#/v1/integrations/finbro/(.+)$#', $uri, $m)) {
        $path = $m[1];
        // Strip trailing index.php if present
        $path = preg_replace('#/index\.php$#', '', $path);
        return trim((string)$path, '/');
    }

    // Direct hit on index.php with PATH_INFO
    if (!empty($_SERVER['PATH_INFO'])) {
        return trim((string)$_SERVER['PATH_INFO'], '/');
    }

    return '';
}

$route = br_finbro_resolve_route();
br_finbro_begin_request($route !== '' ? $route : 'unknown');

set_exception_handler(static function (Throwable $e) use ($route): void {
    error_log('Finbro integration error [' . $route . ']: ' . $e->getMessage());
    if (!headers_sent()) {
        br_finbro_json_response(500, ['error' => 'Internal server error']);
    }
});

register_shutdown_function(static function () use ($route): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) {
        return;
    }
    error_log(
        'Finbro integration fatal [' . $route . ']: ' .
        ($err['message'] ?? 'unknown') . ' in ' .
        ($err['file'] ?? '?') . ':' . (string)($err['line'] ?? 0)
    );
    if (!headers_sent()) {
        // Cannot reliably call exit helpers after fatal; emit minimal JSON.
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Internal server error"}';
    }
});

try {
    $controller = new FinbroIntegrationController();
    $controller->dispatch($route);
} catch (Throwable $e) {
    error_log('Finbro integration error [' . $route . ']: ' . $e->getMessage());
    br_finbro_json_response(500, ['error' => 'Internal server error']);
}
