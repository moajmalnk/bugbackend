<?php
/**
 * Why: Public (no login) App Publishing form for a one-time share token.
 * GET  ?token= → project label + form draft/prefill + link status
 * POST { token, intent: draft|submit, form: {...} } → save draft or consume link
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/app_publishing_links.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function appPubPublicJson(int $code, string $message, $data = null): void
{
    http_response_code($code);
    echo json_encode([
        'success' => $code >= 200 && $code < 300,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

function appPubEnsureFormColumn(PDO $conn): void
{
    try {
        $cols = [];
        $res = $conn->query('SHOW COLUMNS FROM projects');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        if (!in_array('app_publishing_form', $cols, true)) {
            $conn->exec(
                "ALTER TABLE projects ADD COLUMN app_publishing_form LONGTEXT DEFAULT NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('appPubEnsureFormColumn: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string,mixed>>
 */
function appPubListPublishingAttachments(PDO $conn, string $projectId): array
{
    $folders = [
        'publishing_icon',
        'publishing_feature_graphic',
        'publishing_phone_screenshots',
        'publishing_tablet_screenshots',
    ];
    $placeholders = implode(',', array_fill(0, count($folders), '?'));
    $params = array_merge([$projectId], $folders);
    try {
        $stmt = $conn->prepare(
            "SELECT id, project_id, file_name, file_path, file_type, category, folder, created_at
             FROM project_attachments
             WHERE project_id = ? AND folder IN ($placeholders)
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

try {
    $pdo = Database::getInstance()->getConnection();
    appPublishingLinksEnsureSchema($pdo);
    appPubEnsureFormColumn($pdo);

    if ($method === 'GET') {
        $token = isset($_GET['token']) ? strtolower(trim((string) $_GET['token'])) : '';
        if (!appPublishingLinksIsToken($token)) {
            appPubPublicJson(404, 'This form link is invalid or has expired');
        }

        $link = appPublishingLinksFindByToken($pdo, $token);
        if (!$link) {
            appPubPublicJson(404, 'This form link is invalid or has expired');
        }

        $status = (string) ($link['status'] ?? '');
        $projStmt = $pdo->prepare(
            'SELECT id, name, app_publishing_form, client_name, client_contact_name, client_phone,
                    platforms, client_id
             FROM projects WHERE id = ? LIMIT 1'
        );
        $projStmt->execute([$link['project_id']]);
        $project = $projStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            appPubPublicJson(404, 'This form link is invalid or has expired');
        }

        $canEdit = $status === 'active';
        $formJson = null;
        if (!empty($link['draft_json'])) {
            $formJson = $link['draft_json'];
        } elseif (!empty($project['app_publishing_form'])) {
            $formJson = $project['app_publishing_form'];
        }

        $attachments = $canEdit
            ? appPubListPublishingAttachments($pdo, (string) $project['id'])
            : [];

        appPubPublicJson(200, 'OK', [
            'link' => appPublishingLinksPublicMeta($link),
            'can_edit' => $canEdit,
            'project' => [
                'name' => $project['name'],
                'client_name' => $project['client_name'],
                'client_contact_name' => $project['client_contact_name'],
                'client_phone' => $project['client_phone'],
                'platforms' => $project['platforms'],
                'app_publishing_form' => $formJson,
            ],
            'attachments' => $attachments,
            'message' => $canEdit
                ? null
                : ($status === 'submitted'
                    ? 'This form was already submitted. Ask your team for a new link if you need to update anything.'
                    : 'This form link is no longer available. Ask your team for a new share link.'),
        ]);
    }

    if ($method !== 'POST') {
        appPubPublicJson(405, 'Method not allowed');
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        appPubPublicJson(400, 'Invalid request body');
    }

    $token = isset($data['token']) ? strtolower(trim((string) $data['token'])) : '';
    $intent = isset($data['intent']) ? strtolower(trim((string) $data['intent'])) : 'draft';
    $form = $data['form'] ?? null;

    if (!appPublishingLinksIsToken($token)) {
        appPubPublicJson(404, 'This form link is invalid or has expired');
    }
    if (!in_array($intent, ['draft', 'submit'], true)) {
        appPubPublicJson(422, 'intent must be draft or submit');
    }
    if (!is_array($form)) {
        appPubPublicJson(422, 'form payload is required');
    }

    $link = appPublishingLinksFindByToken($pdo, $token);
    if (!$link || ($link['status'] ?? '') !== 'active') {
        appPubPublicJson(410, 'This form link has already been used or expired');
    }

    // Light server-side required checks on submit
    if ($intent === 'submit') {
        $required = ['company_name', 'contact_person', 'phone_number', 'app_name'];
        foreach ($required as $key) {
            if (!isset($form[$key]) || trim((string) $form[$key]) === '') {
                appPubPublicJson(422, 'Please complete all required fields before submitting');
            }
        }
        if (empty($form['publish_platforms']) || empty($form['app_release_type'])) {
            appPubPublicJson(422, 'Please complete all required fields before submitting');
        }
    }

    $form['status'] = $intent === 'submit' ? 'submitted' : 'draft';
    $form['updated_at'] = date('c');
    if ($intent === 'submit') {
        $form['submitted_at'] = date('c');
    }
    $encoded = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        appPubPublicJson(400, 'Could not save form data');
    }
    // Cap size (~1MB)
    if (strlen($encoded) > 1000000) {
        appPubPublicJson(413, 'Form data is too large');
    }

    if ($intent === 'draft') {
        $upd = $pdo->prepare(
            "UPDATE project_app_form_links SET draft_json = ? WHERE id = ? AND status = 'active'"
        );
        $upd->execute([$encoded, $link['id']]);
        if ($upd->rowCount() < 1) {
            appPubPublicJson(410, 'This form link has already been used or expired');
        }
        appPubPublicJson(200, 'Draft saved', [
            'link' => ['status' => 'active', 'expires_at' => $link['expires_at']],
            'form' => $form,
        ]);
    }

    // Submit: write to project + consume link atomically
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT id, status FROM project_app_form_links WHERE id = ? FOR UPDATE"
        );
        $lock->execute([$link['id']]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$locked || ($locked['status'] ?? '') !== 'active') {
            $pdo->rollBack();
            appPubPublicJson(410, 'This form link has already been used or expired');
        }

        $saveProj = $pdo->prepare(
            'UPDATE projects SET app_publishing_form = ? WHERE id = ?'
        );
        $saveProj->execute([$encoded, $link['project_id']]);

        $consume = $pdo->prepare(
            "UPDATE project_app_form_links
             SET status = 'submitted', submitted_at = NOW(), draft_json = ?
             WHERE id = ? AND status = 'active'"
        );
        $consume->execute([$encoded, $link['id']]);
        if ($consume->rowCount() < 1) {
            $pdo->rollBack();
            appPubPublicJson(410, 'This form link has already been used or expired');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    appPubPublicJson(200, 'Form submitted successfully', [
        'link' => [
            'status' => 'submitted',
            'submitted_at' => date('c'),
        ],
        'form' => $form,
    ]);
} catch (Throwable $e) {
    error_log('public app_publishing_form: ' . $e->getMessage());
    appPubPublicJson(500, 'Could not process this form right now');
}
