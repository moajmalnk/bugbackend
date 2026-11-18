<?php
/**
 * Why: One-time public App Publishing form links for clients (no login).
 * Staff mints a share URL; submit consumes the link; a new link is required to update.
 */

function appPublishingLinksEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS project_app_form_links (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            token VARCHAR(64) NOT NULL,
            status ENUM('active','submitted','revoked','expired') NOT NULL DEFAULT 'active',
            draft_json LONGTEXT NULL,
            created_by VARCHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY uq_app_form_token (token),
            KEY idx_app_form_project (project_id),
            KEY idx_app_form_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function appPublishingLinksIsToken(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32,64}$/', $token);
}

function appPublishingLinksNewToken(): string
{
    return bin2hex(random_bytes(24));
}

function appPublishingLinksNewId(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Expire timed-out active links for a project (or all when projectId null).
 */
function appPublishingLinksExpireStale(PDO $pdo, ?string $projectId = null): void
{
    if ($projectId) {
        $stmt = $pdo->prepare(
            "UPDATE project_app_form_links
             SET status = 'expired'
             WHERE project_id = ?
               AND status = 'active'
               AND expires_at < NOW()"
        );
        $stmt->execute([$projectId]);
        return;
    }
    $pdo->exec(
        "UPDATE project_app_form_links
         SET status = 'expired'
         WHERE status = 'active' AND expires_at < NOW()"
    );
}

/**
 * @return array<string,mixed>|null
 */
function appPublishingLinksFindByToken(PDO $pdo, string $token): ?array
{
    appPublishingLinksEnsureSchema($pdo);
    appPublishingLinksExpireStale($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM project_app_form_links WHERE token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @return array<string,mixed>|null Active link for project, if any.
 */
function appPublishingLinksFindActive(PDO $pdo, string $projectId): ?array
{
    appPublishingLinksEnsureSchema($pdo);
    appPublishingLinksExpireStale($pdo, $projectId);
    $stmt = $pdo->prepare(
        "SELECT * FROM project_app_form_links
         WHERE project_id = ? AND status = 'active'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Revoke every active link for the project.
 */
function appPublishingLinksRevokeActive(PDO $pdo, string $projectId): void
{
    $stmt = $pdo->prepare(
        "UPDATE project_app_form_links
         SET status = 'revoked', revoked_at = NOW()
         WHERE project_id = ? AND status = 'active'"
    );
    $stmt->execute([$projectId]);
}

/**
 * Mint a new active one-time link (revokes previous active links).
 *
 * @return array<string,mixed>
 */
function appPublishingLinksCreate(
    PDO $pdo,
    string $projectId,
    ?string $createdBy,
    int $validDays = 14
): array {
    appPublishingLinksEnsureSchema($pdo);
    appPublishingLinksRevokeActive($pdo, $projectId);

    $id = appPublishingLinksNewId();
    $token = appPublishingLinksNewToken();
    $days = max(1, min(90, $validDays));

    $stmt = $pdo->prepare(
        'INSERT INTO project_app_form_links
            (id, project_id, token, status, draft_json, created_by, created_at, expires_at)
         VALUES
            (?, ?, ?, \'active\', NULL, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $stmt->execute([$id, $projectId, $token, $createdBy, $days]);

    $created = appPublishingLinksFindByToken($pdo, $token);
    if (!$created) {
        throw new RuntimeException('Failed to create app publishing link');
    }
    return $created;
}

/**
 * Public-safe link payload (never expose internal ids beyond project name).
 *
 * @param array<string,mixed> $link
 * @return array<string,mixed>
 */
function appPublishingLinksPublicMeta(array $link): array
{
    return [
        'status' => (string) ($link['status'] ?? ''),
        'expires_at' => $link['expires_at'] ?? null,
        'submitted_at' => $link['submitted_at'] ?? null,
        'created_at' => $link['created_at'] ?? null,
    ];
}
