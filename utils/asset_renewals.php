<?php
/**
 * Why: Same catch-up rules as project deadline reminders so a missed cron
 * does not fire 60+30+14+3 WhatsApp messages in one blast.
 */

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/deadline_reminders.php';

/** @return int[] */
function assetRenewalOffsets(): array
{
    return [60, 30, 14, 3, 0];
}

/**
 * @return list<array{entity_type: string, entity_id: string, label: string, client_id: ?string, client_code: ?string, expires_at: string, auto_renew: int, vendor_cost: ?string, client_charge: ?string, kind: string}>
 */
function assetRenewalExpiringRows(PDO $conn): array
{
    $sql = "
        SELECT 'domain' AS entity_type, d.id AS entity_id, d.fqdn AS label, d.client_id,
               c.client_code, d.expires_at, d.auto_renew, d.vendor_cost, d.client_charge, 'domains' AS kind
        FROM assets_domains d
        LEFT JOIN clients c ON c.id = d.client_id
        WHERE d.deleted_at IS NULL AND d.expires_at IS NOT NULL
        UNION ALL
        SELECT 'ssl', s.id, COALESCE(s.covers, s.id), d.client_id, c.client_code,
               s.expires_at, s.auto_renew, s.vendor_cost, s.client_charge, 'ssl'
        FROM assets_ssl_certs s
        JOIN assets_domains d ON d.id = s.domain_id AND d.deleted_at IS NULL
        LEFT JOIN clients c ON c.id = d.client_id
        WHERE s.deleted_at IS NULL AND s.expires_at IS NOT NULL
        UNION ALL
        SELECT 'server', id, hostname, NULL, NULL, expires_at, auto_renew, vendor_cost, client_charge, 'servers'
        FROM assets_servers WHERE deleted_at IS NULL AND expires_at IS NOT NULL
        UNION ALL
        SELECT 'hosting', id, label, NULL, NULL, expires_at, auto_renew, vendor_cost, client_charge, 'hosting'
        FROM assets_hosting WHERE deleted_at IS NULL AND expires_at IS NOT NULL
        UNION ALL
        SELECT 'vercel', id, project_name, NULL, NULL, expires_at, auto_renew, vendor_cost, client_charge, 'vercel'
        FROM assets_vercel WHERE deleted_at IS NULL AND expires_at IS NOT NULL
        UNION ALL
        SELECT 'hardware', id, CONCAT(COALESCE(asset_tag, ''), ' ', COALESCE(model, '')),
               client_id, NULL, warranty_expires_at, 0, vendor_cost, client_charge, 'hardware'
        FROM assets_hardware WHERE deleted_at IS NULL AND warranty_expires_at IS NOT NULL
    ";
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'assets_tools'");
        if ($chk && $chk->fetchColumn()) {
            $sql .= "
        UNION ALL
        SELECT 'tool', id, name, NULL, NULL, expires_at, auto_renew, vendor_cost, client_charge, 'tools'
        FROM assets_tools WHERE deleted_at IS NULL AND expires_at IS NOT NULL
            ";
        }
    } catch (Throwable $e) {
        // ignore — tools table may not exist yet
    }
    $stmt = $conn->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function assetRenewalAlreadySent(PDO $conn, string $entityType, string $entityId, int $offset, string $expiryDate): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM assets_renewal_alerts
         WHERE entity_type = ? AND entity_id = ? AND reminder_offset = ? AND expiry_date = ?
           AND status IN ('sent', 'partial')
         LIMIT 1"
    );
    $stmt->execute([$entityType, $entityId, $offset, $expiryDate]);
    return (bool) $stmt->fetchColumn();
}

function assetPickRenewalOffset(PDO $conn, string $entityType, string $entityId, string $expiryDate, int $diffDays): ?int
{
    $offsets = assetRenewalOffsets();
    $candidates = [];
    foreach ($offsets as $offset) {
        $offset = (int) $offset;
        if (assetRenewalAlreadySent($conn, $entityType, $entityId, $offset, $expiryDate)) {
            continue;
        }
        [$lower, $upper] = deadlineReminderOffsetWindow($offset, $offsets);
        if ($diffDays >= $lower && $diffDays <= $upper) {
            $candidates[] = $offset;
        }
    }
    if (empty($candidates)) {
        return null;
    }
    return (int) min($candidates);
}

/**
 * @param array{email_count?: int, whatsapp_count?: int, push_ok?: bool, errors?: string[]} $channelResult
 */
function assetMarkRenewalSent(
    PDO $conn,
    string $entityType,
    string $entityId,
    int $offset,
    string $expiryDate,
    array $channelResult
): bool {
    $emailCount = (int) ($channelResult['email_count'] ?? 0);
    $whatsappCount = (int) ($channelResult['whatsapp_count'] ?? 0);
    $pushOk = !empty($channelResult['push_ok']);
    $errors = $channelResult['errors'] ?? [];
    $channelsOk = ($emailCount > 0 ? 1 : 0) + ($whatsappCount > 0 ? 1 : 0) + ($pushOk ? 1 : 0);
    if ($channelsOk === 0) {
        return false;
    }
    $status = !empty($errors) && $channelsOk > 0 ? 'partial' : 'sent';
    if ($channelsOk >= 2 && empty($errors)) {
        $status = 'sent';
    }
    $errorSummary = !empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : null;
    $stmt = $conn->prepare(
        'INSERT INTO assets_renewal_alerts
         (entity_type, entity_id, reminder_offset, expiry_date, sent_at,
          email_count, whatsapp_count, push_ok, status, error_summary)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           sent_at = VALUES(sent_at),
           email_count = VALUES(email_count),
           whatsapp_count = VALUES(whatsapp_count),
           push_ok = VALUES(push_ok),
           status = VALUES(status),
           error_summary = VALUES(error_summary)'
    );
    $stmt->execute([
        $entityType,
        $entityId,
        $offset,
        $expiryDate,
        $emailCount,
        $whatsappCount,
        $pushOk ? 1 : 0,
        $status,
        $errorSummary,
    ]);
    return true;
}

function assetRenewalOffsetLabel(int $offset): string
{
    if ($offset > 0) {
        return $offset === 1 ? 'tomorrow' : "in {$offset} days";
    }
    return 'due today';
}
