<?php
/**
 * Gemini API configuration (server-side only).
 * Set GEMINI_API_KEY / GEMINI_MODEL in the server environment or in backend/.env (gitignored).
 */
require_once __DIR__ . '/environment.php';

if (!defined('GEMINI_API_KEY')) {
    $key = getenv('GEMINI_API_KEY');
    if ($key === false || $key === '') {
        $key = (string)Environment::get('GEMINI_API_KEY', '');
    }
    define('GEMINI_API_KEY', trim($key));
}

if (!defined('GEMINI_MODEL')) {
    $model = getenv('GEMINI_MODEL');
    if ($model === false || $model === '') {
        $model = (string)Environment::get('GEMINI_MODEL', '');
    }
    define('GEMINI_MODEL', $model !== '' ? trim($model) : 'gemini-flash-latest');
}
