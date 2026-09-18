<?php
/**
 * BugAssets renewal alerts (domains, SSL, servers, hosting, Vercel, hardware warranty).
 *
 * Cron (recommended daily ~08:00 Asia/Kolkata):
 *   0 8 * * * php /path/to/bugbackend/api/assets/send_renewal_alerts.php
 *
 * HTTP:
 *   GET /api/assets/send_renewal_alerts.php?token=YOUR_SECRET
 *
 * Set ASSETS_RENEWAL_SECRET (or DEADLINE_REMINDER_SECRET) in backend/.env
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../utils/whatsapp.php';
require_once __DIR__ . '/../../utils/asset_renewals.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}

$isCli = (php_sapi_name() === 'cli');
Environment::load();
$secret = getenv('ASSETS_RENEWAL_SECRET')
    ?: (Environment::get('ASSETS_RENEWAL_SECRET')
        ?: (getenv('DEADLINE_REMINDER_SECRET') ?: (Environment::get('DEADLINE_REMINDER_SECRET') ?? '')));

if (!$isCli) {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if ($secret === '' || !hash_equals((string) $secret, (string) $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$tz = new DateTimeZone('Asia/Kolkata');
$today = new DateTime('now', $tz);
$today->setTime(0, 0, 0);
$todayStr = $today->format('Y-m-d');

$results = [
    'success' => true,
    'date' => $todayStr,
    'timezone' => 'Asia/Kolkata',
    'checked' => 0,
    'sent' => 0,
    'skipped' => 0,
    'emails' => 0,
    'whatsapp' => 0,
    'failed' => 0,
    'errors' => [],
    'details' => [],
];

try {
    $conn = Database::getInstance()->getConnection();
    $rows = assetRenewalExpiringRows($conn);
    $appBase = rtrim((string) (Environment::get('APP_BASE_URL') ?: 'https://bugs.bugricer.com'), '/');

    foreach ($rows as $row) {
        $results['checked']++;
        $exp = substr((string) ($row['expires_at'] ?? ''), 0, 10);
        $expDt = DateTime::createFromFormat('Y-m-d', $exp, $tz);
        if (!$expDt) {
            $results['skipped']++;
            continue;
        }
        $expDt->setTime(0, 0, 0);
        $diffDays = (int) $today->diff($expDt)->format('%r%a');
        $entityType = (string) $row['entity_type'];
        $entityId = (string) $row['entity_id'];
        $offset = assetPickRenewalOffset($conn, $entityType, $entityId, $exp, $diffDays);
        if ($offset === null) {
            $results['skipped']++;
            continue;
        }

        $label = trim((string) ($row['label'] ?? $entityType));
        $code = trim((string) ($row['client_code'] ?? ''));
        $kind = (string) ($row['kind'] ?? $entityType);
        $when = assetRenewalOffsetLabel($offset);
        $title = "BugAssets renewal {$when}";
        $message = "{$label}" . ($code !== '' ? " ({$code})" : '') . " expires {$exp} ({$when}).";
        $deepKind = $kind === 'ssl' ? 'domains' : $kind;
        $url = $appBase . '/bugassets/' . rawurlencode($deepKind) . '/' . rawurlencode($entityId);

        $channel = [
            'push_ok' => false,
            'email_count' => 0,
            'whatsapp_count' => 0,
            'errors' => [],
        ];

        try {
            require_once __DIR__ . '/../NotificationManager.php';
            $notifier = NotificationManager::getInstance();
            $push = $notifier->notifyAssetRenewal(
                $entityType,
                $entityId,
                $kind,
                $label,
                $exp,
                $offset,
                $code
            );
            $channel['push_ok'] = $push !== false && $push !== 0;
            if (!$channel['push_ok']) {
                $channel['errors'][] = 'Push failed or no recipients';
            }
        } catch (Throwable $e) {
            $channel['errors'][] = 'Push: ' . $e->getMessage();
        }

        $waBody = "BugAssets · {$when}\n{$label}" . ($code !== '' ? " ({$code})" : '') . " expires {$exp}\nAuto-renew: " . (!empty($row['auto_renew']) ? 'yes' : 'no');
        if ($row['vendor_cost'] !== null || $row['client_charge'] !== null) {
            $waBody .= "\nVendor ₹" . (string) ($row['vendor_cost'] ?? '—') . " · Charge ₹" . (string) ($row['client_charge'] ?? '—');
        }
        $waBody .= "\n{$url}";

        $phones = defined('WHATSAPP_ADMIN_NUMBERS') ? explode(',', (string) WHATSAPP_ADMIN_NUMBERS) : [];
        foreach ($phones as $phone) {
            $phone = trim($phone);
            if ($phone === '') {
                continue;
            }
            try {
                if (sendWhatsAppMessage($phone, $waBody)) {
                    $channel['whatsapp_count']++;
                } else {
                    $channel['errors'][] = 'WhatsApp failed for ' . $phone;
                }
            } catch (Throwable $e) {
                $channel['errors'][] = 'WhatsApp: ' . $e->getMessage();
            }
        }

        if (function_exists('sendEmail')) {
            try {
                $adminStmt = $conn->query("SELECT email FROM users WHERE account_active = 1 AND role = 'admin' AND email IS NOT NULL AND email <> ''");
                $emails = $adminStmt ? $adminStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                foreach ($emails as $email) {
                    if (sendEmail($email, $title, nl2br(htmlspecialchars($message . "\n" . $url)), $message . "\n" . $url)) {
                        $channel['email_count']++;
                    }
                }
            } catch (Throwable $e) {
                $channel['errors'][] = 'Email: ' . $e->getMessage();
            }
        }

        if (assetMarkRenewalSent($conn, $entityType, $entityId, $offset, $exp, $channel)) {
            $results['sent']++;
            $results['emails'] += $channel['email_count'];
            $results['whatsapp'] += $channel['whatsapp_count'];
            $results['details'][] = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'offset' => $offset,
                'label' => $label,
            ];
        } else {
            $results['failed']++;
            $results['errors'][] = $label . ': no channel succeeded';
        }
    }
} catch (Throwable $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
    error_log('send_renewal_alerts fatal: ' . $e->getMessage());
}

if ($isCli) {
    echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($results);
}
