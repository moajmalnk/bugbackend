<?php
/**
 * Gemini API configuration (server-side only).
 * Set GEMINI_API_KEY in the environment or in backend/.env loaded by your stack.
 */
if (!defined('GEMINI_API_KEY')) {
    $key = getenv('GEMINI_API_KEY');
    if ($key === false || $key === '') {
        $key = '';
    }
    define('GEMINI_API_KEY', $key);
}

if (!defined('GEMINI_MODEL')) {
    $model = getenv('GEMINI_MODEL');
    define('GEMINI_MODEL', ($model !== false && $model !== '') ? $model : 'gemini-2.0-flash');
}


