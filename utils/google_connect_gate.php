<?php

/**
 * Why: Push Coverage can ask a person to link Google. The dashboard stays
 * locked until a refresh token exists, then the requirement clears itself.
 */

function br_ensure_google_connect_required(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_connect_required'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return $ready = true;
        }
        $conn->exec(
            'ALTER TABLE users ADD COLUMN google_connect_required TINYINT(1) NOT NULL DEFAULT 0'
        );
        return $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_google_connect_required: ' . $e->getMessage());
        return $ready = false;
    }
}

function br_user_has_google_link(PDO $conn, string $userId): bool
{
    if ($userId === '') {
        return false;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT 1 FROM google_tokens
             WHERE bugricer_user_id = ?
               AND refresh_token IS NOT NULL
               AND TRIM(refresh_token) <> ''
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('br_user_has_google_link: ' . $e->getMessage());
        return false;
    }
}

function br_clear_google_connect_requirement(PDO $conn, string $userId): void
{
    if ($userId === '' || !br_ensure_google_connect_required($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        'UPDATE users SET google_connect_required = 0 WHERE id = ? AND google_connect_required = 1'
    );
    $stmt->execute([$userId]);
}

/**
 * @return array{required: bool, connected: bool}
 */
function br_google_connect_gate_status(PDO $conn, string $userId): array
{
    $connected = br_user_has_google_link($conn, $userId);
    $required = false;
    if (br_ensure_google_connect_required($conn)) {
        $stmt = $conn->prepare('SELECT google_connect_required FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $required = (int) $stmt->fetchColumn() === 1;
    }
    if ($required && $connected) {
        br_clear_google_connect_requirement($conn, $userId);
        $required = false;
    }
    return ['required' => $required, 'connected' => $connected];
}
