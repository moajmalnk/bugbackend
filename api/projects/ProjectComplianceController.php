<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';

class ProjectComplianceController extends BaseAPI
{
    private const DEV_RULE_COUNT = 25;
    private const QA_RULE_COUNT = 7;

    private static $DEV_RULE_KEYS = [
        'dev_rule_1', 'dev_rule_2', 'dev_rule_3', 'dev_rule_4', 'dev_rule_5',
        'dev_rule_6', 'dev_rule_7', 'dev_rule_8', 'dev_rule_9', 'dev_rule_10',
        'dev_rule_11', 'dev_rule_12', 'dev_rule_13', 'dev_rule_14', 'dev_rule_15',
        'dev_rule_16', 'dev_rule_17', 'dev_rule_18', 'dev_rule_19', 'dev_rule_20',
        'dev_rule_21', 'dev_rule_22', 'dev_rule_23', 'dev_rule_24', 'dev_rule_25',
    ];

    private static $QA_RULE_KEYS = [
        'qa_apple_sandbox',
        'qa_click_attack',
        'qa_theme_interruption',
        'qa_input_interception',
        'qa_empty_array',
        'qa_boundary_expansion',
        'qa_network_break',
    ];

    private static $CLOSED_STATUSES = ['completed', 'release_ready', 'archived'];

    public function __construct()
    {
        parent::__construct();
    }

    public static function getDevRuleKeys(): array
    {
        return self::$DEV_RULE_KEYS;
    }

    public static function getQaRuleKeys(): array
    {
        return self::$QA_RULE_KEYS;
    }

    public static function isClosedStatus(string $status): bool
    {
        return in_array($status, self::$CLOSED_STATUSES, true);
    }

    public function userHasProjectAccess(string $userId, string $userRole, $decoded, string $projectId): bool
    {
        $userRole = strtolower(trim($userRole));
        $isImpersonated = false;
        if (isset($decoded->impersonated)) {
            $isImpersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
        }
        if (!$isImpersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
            $isImpersonated = true;
        }
        $adminRole = isset($decoded->admin_role) ? strtolower(trim($decoded->admin_role)) : null;
        $isAdmin = ($userRole === 'admin' && !$isImpersonated) || ($isImpersonated && $adminRole === 'admin');

        if ($isAdmin) {
            return true;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$projectId, $userId]);
        return (bool) $stmt->fetch();
    }

    public function ensureComplianceInitialized(string $projectId): void
    {
        $stmt = $this->conn->prepare("SELECT project_id FROM project_compliance WHERE project_id = ?");
        $stmt->execute([$projectId]);
        if ($stmt->fetch()) {
            return;
        }

        $insertMeta = $this->conn->prepare(
            "INSERT INTO project_compliance (project_id, pipeline_stage) VALUES (?, 'developer_unverified')"
        );
        $insertMeta->execute([$projectId]);

        $insertCheck = $this->conn->prepare(
            "INSERT INTO project_compliance_checks (project_id, phase, rule_key, verified) VALUES (?, ?, ?, 0)"
        );

        foreach (self::$DEV_RULE_KEYS as $key) {
            $insertCheck->execute([$projectId, 'developer', $key]);
        }
        foreach (self::$QA_RULE_KEYS as $key) {
            $insertCheck->execute([$projectId, 'tester', $key]);
        }
    }

    private function countVerified(string $projectId, string $phase): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM project_compliance_checks
             WHERE project_id = ? AND phase = ? AND verified = 1"
        );
        $stmt->execute([$projectId, $phase]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    private function countRules(string $projectId, string $phase): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM project_compliance_checks
             WHERE project_id = ? AND phase = ?"
        );
        $stmt->execute([$projectId, $phase]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    private function ruleCheckExists(string $projectId, string $phase, string $ruleKey): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM project_compliance_checks
             WHERE project_id = ? AND phase = ? AND rule_key = ? LIMIT 1"
        );
        $stmt->execute([$projectId, $phase, $ruleKey]);
        return (bool) $stmt->fetch();
    }

    private function getCustomRulesForProject(string $projectId, ?string $phase = null): array
    {
        if ($phase) {
            $stmt = $this->conn->prepare(
                "SELECT rule_key, phase, title, subtitle, description, created_by, created_at
                 FROM project_compliance_custom_rules
                 WHERE project_id = ? AND phase = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$projectId, $phase]);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT rule_key, phase, title, subtitle, description, created_by, created_at
                 FROM project_compliance_custom_rules
                 WHERE project_id = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$projectId]);
        }

        return array_map(function ($row) {
            return [
                'rule_key' => $row['rule_key'],
                'phase' => $row['phase'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
                'description' => $row['description'],
                'created_by' => $row['created_by'],
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getComplianceMeta(string $projectId): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM project_compliance WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function recomputePipelineStage(string $projectId, ?string $actorUserId = null): string
    {
        $devCount = $this->countVerified($projectId, 'developer');
        $qaCount = $this->countVerified($projectId, 'tester');
        $devTotal = $this->countRules($projectId, 'developer');
        $qaTotal = $this->countRules($projectId, 'tester');

        $meta = $this->getComplianceMeta($projectId);
        if (!$meta) {
            return 'developer_unverified';
        }

        $stage = 'developer_unverified';
        $devCompleteAt = $meta['developer_completed_at'];
        $devCompleteBy = $meta['developer_completed_by'];
        $testerCompleteAt = $meta['tester_completed_at'];
        $testerCompleteBy = $meta['tester_completed_by'];

        if ($devTotal > 0 && $devCount >= $devTotal) {
            $stage = 'qa_inspection';
            if (!$devCompleteAt) {
                $devCompleteAt = date('Y-m-d H:i:s');
                $devCompleteBy = $actorUserId;
            }
        }

        if ($devTotal > 0 && $devCount >= $devTotal && $qaTotal > 0 && $qaCount >= $qaTotal) {
            $stage = 'admin_ready';
            if (!$testerCompleteAt) {
                $testerCompleteAt = date('Y-m-d H:i:s');
                $testerCompleteBy = $actorUserId;
            }
        } elseif ($devTotal > 0 && $devCount >= $devTotal && $qaCount > 0 && $qaCount < $qaTotal) {
            $stage = 'qa_inspection';
        }

        $update = $this->conn->prepare(
            "UPDATE project_compliance SET
                pipeline_stage = ?,
                developer_completed_at = ?,
                developer_completed_by = ?,
                tester_completed_at = ?,
                tester_completed_by = ?,
                updated_at = CURRENT_TIMESTAMP()
             WHERE project_id = ?"
        );
        $update->execute([
            $stage,
            $devCompleteAt,
            $devCompleteBy,
            $testerCompleteAt,
            $testerCompleteBy,
            $projectId,
        ]);

        return $stage;
    }

    public function canCloseProject(string $projectId): array
    {
        $this->ensureComplianceInitialized($projectId);
        $meta = $this->getComplianceMeta($projectId);
        if (!$meta) {
            return ['allowed' => false, 'reason' => 'Compliance record not found'];
        }

        if ((int) $meta['emergency_bypass'] === 1) {
            return ['allowed' => true, 'reason' => 'emergency_bypass'];
        }

        if ($meta['pipeline_stage'] === 'admin_ready') {
            return ['allowed' => true, 'reason' => 'pipeline_complete'];
        }

        return [
            'allowed' => false,
            'reason' => 'pipeline_incomplete',
            'pipeline_stage' => $meta['pipeline_stage'],
        ];
    }

    public function buildCompliancePayload(string $projectId): array
    {
        $this->ensureComplianceInitialized($projectId);
        $meta = $this->getComplianceMeta($projectId);

        $stmt = $this->conn->prepare(
            "SELECT phase, rule_key, verified, verified_by, verified_at
             FROM project_compliance_checks WHERE project_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $developerChecks = [];
        $testerChecks = [];
        foreach ($rows as $row) {
            $item = [
                'rule_key' => $row['rule_key'],
                'verified' => (bool) $row['verified'],
                'verified_by' => $row['verified_by'],
                'verified_at' => $row['verified_at'],
            ];
            if ($row['phase'] === 'developer') {
                $developerChecks[] = $item;
            } else {
                $testerChecks[] = $item;
            }
        }

        $devVerified = $this->countVerified($projectId, 'developer');
        $qaVerified = $this->countVerified($projectId, 'tester');
        $devTotal = $this->countRules($projectId, 'developer');
        $qaTotal = $this->countRules($projectId, 'tester');
        $customRules = $this->getCustomRulesForProject($projectId);

        return [
            'project_id' => $projectId,
            'pipeline_stage' => $meta['pipeline_stage'] ?? 'developer_unverified',
            'developer_completed_at' => $meta['developer_completed_at'] ?? null,
            'developer_completed_by' => $meta['developer_completed_by'] ?? null,
            'tester_completed_at' => $meta['tester_completed_at'] ?? null,
            'tester_completed_by' => $meta['tester_completed_by'] ?? null,
            'emergency_bypass' => (bool) ($meta['emergency_bypass'] ?? false),
            'emergency_bypass_by' => $meta['emergency_bypass_by'] ?? null,
            'emergency_bypass_at' => $meta['emergency_bypass_at'] ?? null,
            'emergency_bypass_reason' => $meta['emergency_bypass_reason'] ?? null,
            'developer_progress' => [
                'verified' => $devVerified,
                'total' => $devTotal,
            ],
            'tester_progress' => [
                'verified' => $qaVerified,
                'total' => $qaTotal,
            ],
            'developer_checks' => $developerChecks,
            'tester_checks' => $testerChecks,
            'custom_rules' => $customRules,
        ];
    }

    public function getSummaryForProject(string $projectId): ?array
    {
        $stmt = $this->conn->prepare("SELECT project_id FROM project_compliance WHERE project_id = ?");
        $stmt->execute([$projectId]);
        if (!$stmt->fetch()) {
            return null;
        }

        $meta = $this->getComplianceMeta($projectId);
        if (!$meta) {
            return null;
        }

        return [
            'pipeline_stage' => $meta['pipeline_stage'],
            'developer_verified' => $this->countVerified($projectId, 'developer'),
            'developer_total' => $this->countRules($projectId, 'developer'),
            'tester_verified' => $this->countVerified($projectId, 'tester'),
            'tester_total' => $this->countRules($projectId, 'tester'),
            'emergency_bypass' => (bool) $meta['emergency_bypass'],
        ];
    }

    public function get()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $projectId = $_GET['project_id'] ?? null;
            if (!$projectId) {
                $this->sendJsonResponse(400, 'project_id is required');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $decoded->role, $decoded, $projectId)) {
                $this->sendJsonResponse(403, 'Access denied to this project');
                return;
            }

            $exists = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
            $exists->execute([$projectId]);
            if (!$exists->fetch()) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $payload = $this->buildCompliancePayload($projectId);
            $this->sendJsonResponse(200, 'Compliance data retrieved', $payload);
        } catch (Exception $e) {
            error_log('Compliance get error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function toggleCheck()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $phase = $data['phase'] ?? null;
            $ruleKey = $data['rule_key'] ?? null;
            $verified = isset($data['verified']) ? (bool) $data['verified'] : true;
            $userRole = strtolower(trim($decoded->role));

            if (!$projectId || !$phase || !$ruleKey) {
                $this->sendJsonResponse(400, 'project_id, phase, and rule_key are required');
                return;
            }

            if (!in_array($phase, ['developer', 'tester'], true)) {
                $this->sendJsonResponse(400, 'Invalid phase');
                return;
            }

            if ($phase === 'developer' && $userRole !== 'developer') {
                $this->sendJsonResponse(403, 'Only developers can verify developer rules');
                return;
            }

            if ($phase === 'tester' && $userRole !== 'tester') {
                $this->sendJsonResponse(403, 'Only testers can verify QA rules');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $decoded->role, $decoded, $projectId)) {
                $this->sendJsonResponse(403, 'Access denied to this project');
                return;
            }

            $this->ensureComplianceInitialized($projectId);

            if ($phase === 'tester') {
                $devCount = $this->countVerified($projectId, 'developer');
                $devTotal = $this->countRules($projectId, 'developer');
                if ($devTotal > 0 && $devCount < $devTotal) {
                    $this->sendJsonResponse(403, 'Developer checklist must be 100% complete before QA verification');
                    return;
                }
            }

            if (!$this->ruleCheckExists($projectId, $phase, $ruleKey)) {
                $this->sendJsonResponse(400, 'Invalid rule_key');
                return;
            }

            $verifiedVal = $verified ? 1 : 0;
            $verifiedBy = $verified ? $decoded->user_id : null;
            $verifiedAt = $verified ? date('Y-m-d H:i:s') : null;

            $stmt = $this->conn->prepare(
                "UPDATE project_compliance_checks
                 SET verified = ?, verified_by = ?, verified_at = ?
                 WHERE project_id = ? AND phase = ? AND rule_key = ?"
            );
            $stmt->execute([$verifiedVal, $verifiedBy, $verifiedAt, $projectId, $phase, $ruleKey]);

            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, 'Compliance check not found');
                return;
            }

            $stage = $this->recomputePipelineStage($projectId, $decoded->user_id);
            $payload = $this->buildCompliancePayload($projectId);
            $payload['pipeline_stage'] = $stage;

            $this->sendJsonResponse(200, 'Check updated', $payload);
        } catch (Exception $e) {
            error_log('Compliance toggle error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function emergencyBypass()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (strtolower(trim($decoded->role)) !== 'admin') {
                $this->sendJsonResponse(403, 'Only admins can authorize emergency bypass');
                return;
            }

            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $reason = trim($data['reason'] ?? '');

            if (!$projectId || $reason === '') {
                $this->sendJsonResponse(400, 'project_id and reason are required');
                return;
            }

            $exists = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
            $exists->execute([$projectId]);
            if (!$exists->fetch()) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $this->ensureComplianceInitialized($projectId);

            $stmt = $this->conn->prepare(
                "UPDATE project_compliance SET
                    emergency_bypass = 1,
                    emergency_bypass_by = ?,
                    emergency_bypass_at = NOW(),
                    emergency_bypass_reason = ?,
                    pipeline_stage = 'admin_ready',
                    updated_at = CURRENT_TIMESTAMP()
                 WHERE project_id = ?"
            );
            $stmt->execute([$decoded->user_id, $reason, $projectId]);

            try {
                $logger = ActivityLogger::getInstance();
                $logger->logActivity(
                    $decoded->user_id,
                    $projectId,
                    'compliance_emergency_bypass',
                    'Emergency compliance bypass authorized',
                    $projectId,
                    ['reason' => $reason]
                );
            } catch (Exception $e) {
                error_log('Failed to log emergency bypass: ' . $e->getMessage());
            }

            $payload = $this->buildCompliancePayload($projectId);
            $this->sendJsonResponse(200, 'Emergency bypass authorized', $payload);
        } catch (Exception $e) {
            error_log('Compliance bypass error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function finalizeStatus()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (strtolower(trim($decoded->role)) !== 'admin') {
                $this->sendJsonResponse(403, 'Only admins can finalize project status');
                return;
            }

            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $status = $data['status'] ?? null;

            if (!$projectId || !$status) {
                $this->sendJsonResponse(400, 'project_id and status are required');
                return;
            }

            if (!in_array($status, ['completed', 'release_ready'], true)) {
                $this->sendJsonResponse(400, 'status must be completed or release_ready');
                return;
            }

            $gate = $this->canCloseProject($projectId);
            if (!$gate['allowed']) {
                $this->sendJsonResponse(403, 'Compliance pipeline not satisfied. Complete Developer and QA checklists or authorize emergency bypass.');
                return;
            }

            $stmt = $this->conn->prepare(
                "UPDATE projects SET status = ?, updated_at = CURRENT_TIMESTAMP() WHERE id = ?"
            );
            $stmt->execute([$status, $projectId]);

            if ($stmt->rowCount() === 0) {
                $check = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
                $check->execute([$projectId]);
                if (!$check->fetch()) {
                    $this->sendJsonResponse(404, 'Project not found');
                    return;
                }
            }

            $projectStmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ?");
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

            $payload = $this->buildCompliancePayload($projectId);
            $payload['project'] = $project;

            $this->sendJsonResponse(200, 'Project status finalized', $payload);
        } catch (Exception $e) {
            error_log('Compliance finalize error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function addCustomRule()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $phase = $data['phase'] ?? null;
            $title = trim($data['title'] ?? '');
            $description = trim($data['description'] ?? '');
            $subtitle = trim($data['subtitle'] ?? '');
            $userRole = strtolower(trim($decoded->role));

            if (!$projectId || !$phase || $title === '' || $description === '') {
                $this->sendJsonResponse(400, 'project_id, phase, title, and description are required');
                return;
            }

            if (!in_array($phase, ['developer', 'tester'], true)) {
                $this->sendJsonResponse(400, 'Invalid phase');
                return;
            }

            if ($phase === 'developer' && !in_array($userRole, ['developer', 'admin'], true)) {
                $this->sendJsonResponse(403, 'Only developers or admins can add developer rules');
                return;
            }

            if ($phase === 'tester' && !in_array($userRole, ['tester', 'admin'], true)) {
                $this->sendJsonResponse(403, 'Only testers or admins can add QA rules');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $decoded->role, $decoded, $projectId)) {
                $this->sendJsonResponse(403, 'Access denied to this project');
                return;
            }

            $exists = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
            $exists->execute([$projectId]);
            if (!$exists->fetch()) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $this->ensureComplianceInitialized($projectId);

            $ruleKey = 'custom_' . $phase . '_' . bin2hex(random_bytes(8));

            $insertRule = $this->conn->prepare(
                "INSERT INTO project_compliance_custom_rules
                    (project_id, phase, rule_key, title, subtitle, description, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insertRule->execute([
                $projectId,
                $phase,
                $ruleKey,
                $title,
                $subtitle !== '' ? $subtitle : null,
                $description,
                $decoded->user_id,
            ]);

            $insertCheck = $this->conn->prepare(
                "INSERT INTO project_compliance_checks (project_id, phase, rule_key, verified)
                 VALUES (?, ?, ?, 0)"
            );
            $insertCheck->execute([$projectId, $phase, $ruleKey]);

            try {
                $logger = ActivityLogger::getInstance();
                $logger->logActivity(
                    $decoded->user_id,
                    $projectId,
                    'compliance_custom_rule_added',
                    'Custom compliance rule added',
                    $projectId,
                    ['phase' => $phase, 'rule_key' => $ruleKey, 'title' => $title]
                );
            } catch (Exception $e) {
                error_log('Failed to log custom rule: ' . $e->getMessage());
            }

            $stage = $this->recomputePipelineStage($projectId, $decoded->user_id);
            $payload = $this->buildCompliancePayload($projectId);
            $payload['pipeline_stage'] = $stage;

            $this->sendJsonResponse(201, 'Custom rule added', $payload);
        } catch (Exception $e) {
            error_log('Compliance add rule error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}
