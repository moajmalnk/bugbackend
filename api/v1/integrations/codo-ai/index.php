<?php
/**
 * CODO AI Innovations Profile Photo front controller.
 *
 * Partner-facing routes (after /api/ rewrite):
 *   GET  v1/users/{employeeId}/avatar
 *   GET  v1/users/avatars?ids=
 *   POST v1/users/avatars
 *
 * Always returns JSON (never HTML 500 pages).
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

require_once __DIR__ . '/../../../../config/cors.php';
require_once __DIR__ . '/../../../../utils/codo_ai_integration.php';
require_once __DIR__ . '/CodoAiAvatarController.php';

/**
 * @return array{0: string, 1: string|null} [route, key]
 */
function br_codo_ai_resolve_route(): array
{
    if (!empty($_GET['codo_route'])) {
        $route = trim((string)$_GET['codo_route'], '/');
        $key = isset($_GET['codo_key']) ? (string)$_GET['codo_key'] : null;
        return [$route !== '' ? $route : '', $key];
    }

    $uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $uri = rawurldecode($uri);

    if (preg_match('#/v1/users/avatars/?$#', $uri)) {
        return ['avatars', null];
    }
    if (preg_match('#/v1/users/([^/]+)/avatar/?$#', $uri, $m)) {
        return ['user-avatar', $m[1]];
    }

    if (!empty($_SERVER['PATH_INFO'])) {
        $path = trim((string)$_SERVER['PATH_INFO'], '/');
        if ($path === 'avatars') {
            return ['avatars', null];
        }
        if (preg_match('#^([^/]+)/avatar$#', $path, $m)) {
            return ['user-avatar', $m[1]];
        }
    }

    return ['', null];
}

[$route, $key] = br_codo_ai_resolve_route();
br_codo_ai_begin_request($route !== '' ? $route : 'unknown');

set_exception_handler(static function (Throwable $e) use ($route): void {
    error_log('CODO AI integration error [' . $route . ']: ' . $e->getMessage());
    if (!headers_sent()) {
        br_codo_ai_json_response(500, [
            'success' => false,
            'message' => 'Internal server error',
            'data' => null,
        ]);
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
        'CODO AI integration fatal [' . $route . ']: ' .
        ($err['message'] ?? 'unknown') . ' in ' .
        ($err['file'] ?? '?') . ':' . (string)($err['line'] ?? 0)
    );
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":false,"message":"Internal server error","data":null}';
    }
});

try {
    $controller = new CodoAiAvatarController();
    $controller->dispatch($route, $key);
} catch (Throwable $e) {
    error_log('CODO AI integration error [' . $route . ']: ' . $e->getMessage());
    br_codo_ai_json_response(500, [
        'success' => false,
        'message' => 'Internal server error',
        'data' => null,
    ]);
}
