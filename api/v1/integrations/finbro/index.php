<?php
/**
 * Finbro integration front controller.
 *
 * Routes (after /api/ rewrite):
 *   GET v1/integrations/finbro/users/status
 *   GET v1/integrations/finbro/hours
 *   GET v1/integrations/finbro/hours/by-user
 */

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

try {
    $controller = new FinbroIntegrationController();
    $controller->dispatch($route);
} catch (Throwable $e) {
    error_log('Finbro integration error [' . $route . ']: ' . $e->getMessage());
    br_finbro_json_response(500, ['error' => 'Internal server error']);
}
