<?php
/**
 * Load Composer autoload without relying on a git-managed vendor/ folder.
 *
 * Hostinger git deploy wipes untracked files inside the repo (bugbackend/).
 * Keep packages in a sibling folder that is NEVER part of the git repo:
 *
 *   public_html/bugbackend/           ← git (code only)
 *   public_html/bugbackend_vendor/    ← upload once, survives every push
 *
 * Locally, falls back to backend/vendor/ from `composer install`.
 */

$candidates = [
    // Hostinger persistent packages (sibling of this app, outside git root)
    dirname(__DIR__) . '/../bugbackend_vendor/autoload.php',
    // Local / optional in-repo vendor (gitignored)
    dirname(__DIR__) . '/vendor/autoload.php',
];

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        return;
    }
}

throw new RuntimeException(
    'Composer autoload not found. On Hostinger upload packages to public_html/bugbackend_vendor/ ' .
    '(extract vendor zip so autoload.php is at bugbackend_vendor/autoload.php). ' .
    'Locally run: composer install in backend/.'
);
