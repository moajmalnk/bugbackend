<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../utils/email.php';
require_once __DIR__ . '/../../utils/workforce_access.php';

class ProjectComplianceController extends BaseAPI
{
    private const DEV_RULE_COUNT = 68;
    private const QA_RULE_COUNT = 36;

    private static $DEV_RULE_KEYS = [
        'dev_rule_1', 'dev_rule_2', 'dev_rule_3', 'dev_rule_4', 'dev_rule_5',
        'dev_rule_6', 'dev_rule_7', 'dev_rule_8', 'dev_rule_9', 'dev_rule_10',
        'dev_rule_11', 'dev_rule_12', 'dev_rule_13', 'dev_rule_14', 'dev_rule_15',
        'dev_rule_16', 'dev_rule_17', 'dev_rule_18', 'dev_rule_19', 'dev_rule_20',
        'dev_rule_21', 'dev_rule_22', 'dev_rule_23', 'dev_rule_24', 'dev_rule_25',
        'dev_rule_26', 'dev_rule_27', 'dev_rule_28', 'dev_rule_29', 'dev_rule_30',
        'dev_rule_31', 'dev_rule_32', 'dev_rule_33', 'dev_rule_35', 'dev_rule_36',
        'dev_rule_37', 'dev_rule_38', 'dev_rule_40', 'dev_rule_43', 'dev_rule_44',
        'dev_rule_45', 'dev_rule_46', 'dev_rule_47', 'dev_rule_48', 'dev_rule_49',
        'dev_rule_50', 'dev_rule_51', 'dev_rule_52', 'dev_rule_53', 'dev_rule_54',
        'dev_rule_55', 'dev_rule_56', 'dev_rule_57', 'dev_rule_58', 'dev_rule_59',
        'dev_rule_60', 'dev_rule_61', 'dev_rule_62', 'dev_rule_63', 'dev_rule_64',
        'dev_rule_65', 'dev_rule_66', 'dev_rule_67', 'dev_rule_68',
        'dev_rule_69', 'dev_rule_70', 'dev_rule_71', 'dev_rule_72',
    ];

    private static $QA_RULE_KEYS = [
        'qa_apple_sandbox',
        'qa_click_attack',
        'qa_theme_interruption',
        'qa_input_interception',
        'qa_empty_array',
        'qa_boundary_expansion',
        'qa_network_break',
        'qa_console_zero',
        'qa_high_volume',
        'qa_script_injection',
        'qa_modal_scope',
        'qa_rtl_stress',
        'qa_browser_back',
        'qa_loading_lifecycle',
        'qa_data_reconciliation',
        'qa_mutation_sync',
        'qa_race_condition',
        'qa_navigation_during_requests',
        'qa_slow_api_timeout',
        'qa_cross_browser_data',
        'qa_cache_isolation',
        'qa_concurrency',
        'qa_session_expiry',
        'qa_permission_boundary',
        'qa_pagination_integrity',
        'qa_financial_integrity',
        'qa_api_contract',
        'qa_production_build_env',
        'qa_deployment_smoke',
        'qa_regression',
        'qa_performance_regression',
        'qa_accessibility',
        'qa_responsive_matrix',
        'qa_release_acceptance',
        'qa_in_app_payment_upi',
        'qa_googlebot_html',
    ];

    private static $BUILTIN_RULE_TITLES = [
        'dev_rule_1' => 'Hard State Reset',
        'dev_rule_2' => 'Real-Time Input Validation',
        'dev_rule_3' => 'Persistent Input Protection',
        'dev_rule_4' => 'Data-Clear Verification',
        'dev_rule_5' => 'Numeric Character Constraints',
        'dev_rule_6' => 'Sanitization Defenses',
        'dev_rule_7' => 'Length Guardrails',
        'dev_rule_8' => 'Anti-Double Click Lockout',
        'dev_rule_9' => 'Mandatory Deletion Gating',
        'dev_rule_10' => 'Submit Button Lock',
        'dev_rule_11' => 'The Codo Corner',
        'dev_rule_12' => '12-Column Grid Alignment',
        'dev_rule_13' => 'Whitespace Isolation',
        'dev_rule_14' => 'Viewport Scroll Defenses',
        'dev_rule_15' => 'Theme Integrity',
        'dev_rule_16' => 'Bidirectional Text Safety',
        'dev_rule_17' => 'Custom Picker Normalization',
        'dev_rule_18' => 'Strict Data Sorting',
        'dev_rule_19' => 'Skeleton Shimmer Loaders',
        'dev_rule_20' => '1.5-Second Threshold',
        'dev_rule_21' => 'Database Indexing',
        'dev_rule_22' => 'High-Volume Scale',
        'dev_rule_23' => 'Console Scrubbing',
        'dev_rule_24' => 'Secret Variable Isolation',
        'dev_rule_25' => 'Documentation Mandate',
        'dev_rule_26' => 'SPA Router History Sync',
        'dev_rule_27' => 'Strict Test-Data Clearance',
        'dev_rule_28' => 'Layout Alignment Containment',
        'dev_rule_29' => 'Dynamic Status Feedback Toast',
        'dev_rule_30' => 'RTL Typography Safeguards',
        'dev_rule_31' => 'Native Scrollbar Preservation',
        'dev_rule_32' => 'Immutable Array Sorting',
        'dev_rule_33' => 'Canonical Tag Injection',
        'dev_rule_35' => 'Heading Hierarchy Enforcement',
        'dev_rule_36' => 'Image WebP & Alt Text Standard',
        'dev_rule_37' => 'Structured Data JSON-LD',
        'dev_rule_38' => 'Conversion Telemetry & GA4 Event Tracking',
        'dev_rule_40' => 'Core Web Vitals Optimization',
        'dev_rule_43' => 'Custom 404 Routing',
        'dev_rule_44' => 'Cross-Browser API Consistency',
        'dev_rule_45' => 'No Manual Hard Refresh Dependency',
        'dev_rule_46' => 'Explicit API Cache Policy',
        'dev_rule_47' => 'Cache Invalidation After Mutations',
        'dev_rule_48' => 'Frontend Query Cache Ownership',
        'dev_rule_49' => 'Service Worker Cache Safety',
        'dev_rule_50' => 'Request Identity & Credentials',
        'dev_rule_51' => 'API Contract & Backward Compatibility',
        'dev_rule_52' => 'Backend as Source of Truth',
        'dev_rule_53' => 'Complete API Request Lifecycle',
        'dev_rule_54' => 'Stale Request & Navigation Safety',
        'dev_rule_55' => 'Never Display Fake Business Data',
        'dev_rule_56' => 'Database Transaction Integrity',
        'dev_rule_57' => 'Concurrency Safety',
        'dev_rule_58' => 'Idempotent Critical APIs',
        'dev_rule_59' => 'N+1 Query Prevention',
        'dev_rule_60' => 'Backend Authorization',
        'dev_rule_61' => 'Environment Isolation',
        'dev_rule_62' => 'Safe Database Migrations',
        'dev_rule_63' => 'Production Observability',
        'dev_rule_64' => 'AI-Generated Code Verification',
        'dev_rule_65' => 'Dependency Discipline',
        'dev_rule_66' => 'Root Cause Over Workarounds',
        'dev_rule_67' => 'Release Readiness',
        'dev_rule_68' => 'Embedded WebView Payment Gateway & Intent Scheme Interception',
        'dev_rule_69' => 'Bot-Visible Unique Content (SPA Crawlability)',
        'dev_rule_70' => 'Trailing Slash URL Standardization',
        'dev_rule_71' => 'Unique Per-Page Metadata & Schema Isolation',
        'dev_rule_72' => 'Sitemap Canonical Hygiene',
        'qa_apple_sandbox' => 'The Apple Ecosystem Sandbox',
        'qa_click_attack' => 'The Click Attack Safeguard',
        'qa_theme_interruption' => 'The Theme Interruption Matrix',
        'qa_input_interception' => 'The Input Interception Prompt',
        'qa_empty_array' => 'The Empty Array Fallback',
        'qa_boundary_expansion' => 'The Boundary Expansion Constraint',
        'qa_network_break' => 'The Network Break Strategy',
        'qa_console_zero' => 'Console Zero-Tolerance',
        'qa_high_volume' => 'High-Volume Scale Audit',
        'qa_script_injection' => 'Script Injection Test',
        'qa_modal_scope' => 'Modal Overlay Scope',
        'qa_rtl_stress' => 'RTL Language Stress Test',
        'qa_browser_back' => 'Browser Back Button Drill',
        'qa_loading_lifecycle' => 'Loading Lifecycle Drill',
        'qa_data_reconciliation' => 'UI / API / Database Reconciliation',
        'qa_mutation_sync' => 'Mutation Synchronization Audit',
        'qa_race_condition' => 'Race Condition Drill',
        'qa_navigation_during_requests' => 'Navigation During Requests',
        'qa_slow_api_timeout' => 'Slow API & Timeout Test',
        'qa_cross_browser_data' => 'Cross-Browser Data Consistency',
        'qa_cache_isolation' => 'Cache Isolation Test',
        'qa_concurrency' => 'Multi-Tab & Concurrent Operations',
        'qa_session_expiry' => 'Session Expiry Test',
        'qa_permission_boundary' => 'Permission Boundary Test',
        'qa_pagination_integrity' => 'Pagination Integrity Test',
        'qa_financial_integrity' => 'Financial Data Integrity',
        'qa_api_contract' => 'API Contract Verification',
        'qa_production_build_env' => 'Production Build & Environment',
        'qa_deployment_smoke' => 'Deployment Smoke Test',
        'qa_regression' => 'Regression Testing',
        'qa_performance_regression' => 'Performance Regression Check',
        'qa_accessibility' => 'Accessibility Check',
        'qa_responsive_matrix' => 'Responsive Device Matrix',
        'qa_release_acceptance' => 'Final Release Acceptance',
        'qa_in_app_payment_upi' => 'In-App Payment Gateway & UPI App Switch Drill',
        'qa_googlebot_html' => 'Googlebot HTML Uniqueness Drill',
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

    /**
     * Resolve effective role from role_id (preferred) or legacy role string — mirrors frontend getEffectiveRole().
     */
    private function getEffectiveUserRole($decoded): string
    {
        $userId = $decoded->user_id ?? null;
        if ($userId) {
            try {
                $stmt = $this->conn->prepare("SELECT role, role_id FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (isset($row['role_id']) && $row['role_id'] !== null && $row['role_id'] !== '') {
                        $roleId = (int) $row['role_id'];
                        if ($roleId === 1) {
                            return 'admin';
                        }
                        if ($roleId === 2) {
                            return 'developer';
                        }
                        if ($roleId === 3) {
                            return 'tester';
                        }
                    }
                    if (!empty($row['role'])) {
                        return strtolower(trim($row['role']));
                    }
                }
            } catch (Exception $e) {
                error_log('getEffectiveUserRole error: ' . $e->getMessage());
            }
        }

        return strtolower(trim($decoded->role ?? 'user'));
    }

    public function userHasProjectAccess(string $userId, string $userRole, $decoded, string $projectId): bool
    {
        // Why: Impersonation must check membership as the target user.
        if (BaseAPI::hasGlobalDataScope($decoded)) {
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

    private function resolveRuleTitle(string $projectId, string $phase, string $ruleKey): string
    {
        if (isset(self::$BUILTIN_RULE_TITLES[$ruleKey])) {
            return self::$BUILTIN_RULE_TITLES[$ruleKey];
        }

        try {
            $custom = $this->getCustomRulesForProject($projectId, $phase);
            foreach ($custom as $rule) {
                if (($rule['rule_key'] ?? '') === $ruleKey && !empty($rule['title'])) {
                    return (string) $rule['title'];
                }
            }
        } catch (Exception $e) {
            error_log('resolveRuleTitle custom lookup failed: ' . $e->getMessage());
        }

        return $ruleKey;
    }

    private function formatPhaseLabel(string $phase): string
    {
        switch ($phase) {
            case 'developer':
                return 'Developer Matrix';
            case 'tester':
                return 'Tester Matrix';
            case 'project':
                return 'Project Checklist';
            default:
                return 'Compliance';
        }
    }

    private function notifyAdminsOfComplianceVerification(
        string $projectId,
        string $phase,
        string $ruleKey,
        string $verifiedBy
    ): void {
        try {
            $projectStmt = $this->conn->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $projectName = trim((string) ($project['name'] ?? '')) ?: 'Untitled project';
            $ruleTitle = $this->resolveRuleTitle($projectId, $phase, $ruleKey);
            $phaseLabel = $this->formatPhaseLabel($phase);

            $verifierStmt = $this->conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $verifierStmt->execute([$verifiedBy]);
            $verifierName = trim((string) ($verifierStmt->fetchColumn() ?: '')) ?: 'A team member';

            try {
                NotificationManager::getInstance()->notifyComplianceRuleVerified(
                    $projectId,
                    $projectName,
                    $phase,
                    $ruleKey,
                    $ruleTitle,
                    $verifiedBy
                );
            } catch (Throwable $e) {
                error_log('Compliance push notification failed: ' . $e->getMessage());
            }

            $adminStmt = $this->conn->prepare(
                "SELECT email FROM users
                 WHERE account_active = 1
                   AND (role = 'admin' OR role_id = 1)
                   AND email IS NOT NULL
                   AND TRIM(email) <> ''
                   AND id <> ?"
            );
            $adminStmt->execute([$verifiedBy]);
            $adminEmails = $adminStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if (empty($adminEmails)) {
                return;
            }

            $complianceUrl = 'https://bugs.bugricer.com/admin/projects/' . rawurlencode($projectId) . '/compliance';
            foreach ($adminEmails as $adminEmail) {
                try {
                    sendComplianceVerifiedEmail(
                        $adminEmail,
                        $verifierName,
                        $projectName,
                        $phaseLabel,
                        $ruleTitle,
                        $ruleKey,
                        $complianceUrl
                    );
                } catch (Throwable $e) {
                    error_log('Compliance email failed for ' . $adminEmail . ': ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('notifyAdminsOfComplianceVerification failed: ' . $e->getMessage());
        }
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
        $projectCount = $this->countVerified($projectId, 'project');
        $devTotal = $this->countRules($projectId, 'developer');
        $qaTotal = $this->countRules($projectId, 'tester');
        $projectTotal = $this->countRules($projectId, 'project');
        $projectComplete = $projectTotal === 0 || $projectCount >= $projectTotal;

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

        if ($devTotal > 0 && $devCount >= $devTotal && $qaTotal > 0 && $qaCount >= $qaTotal && $projectComplete) {
            $stage = 'admin_ready';
            if (!$testerCompleteAt) {
                $testerCompleteAt = date('Y-m-d H:i:s');
                $testerCompleteBy = $actorUserId;
            }
        } elseif ($devTotal > 0 && $devCount >= $devTotal && $qaTotal > 0 && $qaCount >= $qaTotal) {
            $stage = 'qa_complete';
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
        try {
            $col = $this->conn->query("SHOW COLUMNS FROM projects LIKE 'compliance_required'");
            if ($col && $col->rowCount() > 0) {
                $reqStmt = $this->conn->prepare(
                    'SELECT compliance_required FROM projects WHERE id = ? LIMIT 1'
                );
                $reqStmt->execute([$projectId]);
                $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
                if ($reqRow && (int) ($reqRow['compliance_required'] ?? 1) === 0) {
                    return ['allowed' => true, 'reason' => 'compliance_not_required'];
                }
            }
        } catch (Throwable $e) {
            error_log('canCloseProject compliance_required: ' . $e->getMessage());
        }

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
            "SELECT c.phase, c.rule_key, c.verified, c.verified_by, c.verified_at,
                    u.username AS verified_by_username
             FROM project_compliance_checks c
             LEFT JOIN users u ON u.id = c.verified_by
             WHERE c.project_id = ?
             ORDER BY c.id ASC"
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $developerChecks = [];
        $testerChecks = [];
        $projectChecks = [];
        foreach ($rows as $row) {
            $item = [
                'rule_key' => $row['rule_key'],
                'verified' => (bool) $row['verified'],
                'verified_by' => $row['verified_by'],
                'verified_at' => $row['verified_at'],
                'verified_by_username' => $row['verified_by_username'] ?? null,
            ];
            if ($row['phase'] === 'developer') {
                $developerChecks[] = $item;
            } elseif ($row['phase'] === 'tester') {
                $testerChecks[] = $item;
            } else {
                $projectChecks[] = $item;
            }
        }

        $devVerified = $this->countVerified($projectId, 'developer');
        $qaVerified = $this->countVerified($projectId, 'tester');
        $projectVerified = $this->countVerified($projectId, 'project');
        $devTotal = $this->countRules($projectId, 'developer');
        $qaTotal = $this->countRules($projectId, 'tester');
        $projectTotal = $this->countRules($projectId, 'project');
        $customRules = $this->getCustomRulesForProject($projectId);

        $projectStmt = $this->conn->prepare("SELECT id, name, status FROM projects WHERE id = ?");
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;

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
            'project_progress' => [
                'verified' => $projectVerified,
                'total' => $projectTotal,
            ],
            'developer_checks' => $developerChecks,
            'tester_checks' => $testerChecks,
            'project_checks' => $projectChecks,
            'custom_rules' => $customRules,
            'project' => $project,
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
            'project_verified' => $this->countVerified($projectId, 'project'),
            'project_total' => $this->countRules($projectId, 'project'),
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
            if (!br_require_codo_standards_access($this, $this->conn, $decoded)) {
                return;
            }
            $projectId = $_GET['project_id'] ?? null;
            if (!$projectId) {
                $this->sendJsonResponse(400, 'project_id is required');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $this->getEffectiveUserRole($decoded), $decoded, $projectId)) {
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
            if (!br_require_codo_standards_access($this, $this->conn, $decoded)) {
                return;
            }
            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $phase = $data['phase'] ?? null;
            $ruleKey = $data['rule_key'] ?? null;
            $verified = isset($data['verified']) ? (bool) $data['verified'] : true;
            $userRole = $this->getEffectiveUserRole($decoded);

            if (!$projectId || !$phase || !$ruleKey) {
                $this->sendJsonResponse(400, 'project_id, phase, and rule_key are required');
                return;
            }

            if (!in_array($phase, ['developer', 'tester', 'project'], true)) {
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

            if ($phase === 'project' && $userRole !== 'admin') {
                $this->sendJsonResponse(403, 'Only admins can verify project-level rules');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $userRole, $decoded, $projectId)) {
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

            if ($phase === 'project') {
                $devCount = $this->countVerified($projectId, 'developer');
                $devTotal = $this->countRules($projectId, 'developer');
                $qaCount = $this->countVerified($projectId, 'tester');
                $qaTotal = $this->countRules($projectId, 'tester');
                if (
                    ($devTotal > 0 && $devCount < $devTotal) ||
                    ($qaTotal > 0 && $qaCount < $qaTotal)
                ) {
                    $this->sendJsonResponse(403, 'Developer and QA checklists must be complete before project-level verification');
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

            if ($verified) {
                $this->notifyAdminsOfComplianceVerification(
                    $projectId,
                    $phase,
                    $ruleKey,
                    $decoded->user_id
                );
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

    /**
     * Why: Admins sign off Developer / Tester matrices on the team's behalf — one rule,
     * a selection, or every rule — without loosening the role gates of toggleCheck()
     * that developers and testers keep using. One UPDATE in a transaction, one activity
     * entry, and no per-rule admin notifications (the actor is the admin).
     */
    public function adminSetChecks()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (!br_require_codo_standards_access($this, $this->conn, $decoded)) {
                return;
            }
            if ($this->getEffectiveUserRole($decoded) !== 'admin') {
                $this->sendJsonResponse(403, 'Only admins can bulk verify compliance rules');
                return;
            }

            $data = $this->getRequestData();
            $projectId = trim((string) ($data['project_id'] ?? ''));
            $phase = (string) ($data['phase'] ?? '');
            $verified = isset($data['verified']) ? (bool) $data['verified'] : true;
            $all = !empty($data['all']);
            $ruleKeys = [];
            if (!$all) {
                $raw = $data['rule_keys'] ?? [];
                if (!is_array($raw)) {
                    $this->sendJsonResponse(422, 'rule_keys must be an array');
                    return;
                }
                foreach ($raw as $key) {
                    $key = trim((string) $key);
                    if ($key !== '' && strlen($key) <= 100) {
                        $ruleKeys[$key] = true;
                    }
                }
                $ruleKeys = array_keys($ruleKeys);
                if (!$ruleKeys) {
                    $this->sendJsonResponse(422, 'Select at least one rule');
                    return;
                }
                if (count($ruleKeys) > 500) {
                    $this->sendJsonResponse(422, 'Too many rules in one request');
                    return;
                }
            }

            if ($projectId === '' || !in_array($phase, ['developer', 'tester'], true)) {
                $this->sendJsonResponse(422, 'project_id and phase (developer|tester) are required');
                return;
            }

            $exists = $this->conn->prepare('SELECT id FROM projects WHERE id = ?');
            $exists->execute([$projectId]);
            if (!$exists->fetch()) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $this->ensureComplianceInitialized($projectId);

            $verifiedVal = $verified ? 1 : 0;
            $sql = "UPDATE project_compliance_checks
                    SET verified = ?, verified_by = ?, verified_at = ?
                    WHERE project_id = ? AND phase = ? AND verified <> ?";
            $params = [
                $verifiedVal,
                $verified ? $decoded->user_id : null,
                $verified ? date('Y-m-d H:i:s') : null,
                $projectId,
                $phase,
                $verifiedVal,
            ];
            if (!$all) {
                $sql .= ' AND rule_key IN (' . implode(',', array_fill(0, count($ruleKeys), '?')) . ')';
                $params = array_merge($params, $ruleKeys);
            }

            $this->conn->beginTransaction();
            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $changed = (int) $stmt->rowCount();
                $stage = $this->recomputePipelineStage($projectId, $decoded->user_id);
                $this->conn->commit();
            } catch (Throwable $e) {
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                throw $e;
            }

            if ($changed > 0) {
                try {
                    ActivityLogger::getInstance()->logActivity(
                        $decoded->user_id,
                        $projectId,
                        'compliance_admin_verify',
                        sprintf(
                            'Admin %s %d %s rule%s',
                            $verified ? 'verified' : 'unverified',
                            $changed,
                            $this->formatPhaseLabel($phase),
                            $changed === 1 ? '' : 's'
                        ),
                        $projectId,
                        [
                            'phase' => $phase,
                            'verified' => $verified,
                            'scope' => $all ? 'all' : 'selected',
                            'count' => $changed,
                        ]
                    );
                } catch (Throwable $e) {
                    error_log('Compliance admin verify log failed: ' . $e->getMessage());
                }
            }

            $payload = $this->buildCompliancePayload($projectId);
            $payload['pipeline_stage'] = $stage;
            $payload['changed'] = $changed;
            $this->sendJsonResponse(200, 'Checks updated', $payload);
        } catch (Throwable $e) {
            error_log('Compliance adminSetChecks error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Could not update compliance checks');
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

    /**
     * Why: When admin finalizes a project as completed/release_ready, pending
     * retests (fixed bugs awaiting tester verification) should leave the Retests
     * queue as verified fixed so the project close does not leave open QA work.
     *
     * @return int Number of bugs auto-verified
     */
    public function autoVerifyPendingRetests(string $projectId, string $actorUserId): int
    {
        if ($projectId === '' || !$this->conn) {
            return 0;
        }

        try {
            $hasRetest = $this->conn->query("SHOW COLUMNS FROM bugs LIKE 'tester_retested'");
            if (!$hasRetest || $hasRetest->rowCount() === 0) {
                return 0;
            }

            $hasDeletedAt = false;
            try {
                $delCol = $this->conn->query("SHOW COLUMNS FROM bugs LIKE 'deleted_at'");
                $hasDeletedAt = (bool) ($delCol && $delCol->rowCount() > 0);
            } catch (Throwable $e) {
                $hasDeletedAt = false;
            }

            $hasNotes = false;
            try {
                $notesCol = $this->conn->query("SHOW COLUMNS FROM bugs LIKE 'tester_verification_notes'");
                $hasNotes = (bool) ($notesCol && $notesCol->rowCount() > 0);
            } catch (Throwable $e) {
                $hasNotes = false;
            }

            $noteText = 'Auto-verified as fixed when project was marked completed by admin.';
            $setParts = [
                'tester_retested = 1',
                'tester_issue_fixed = 1',
                'tester_verified_by = ?',
                'tester_verified_at = NOW()',
                'updated_at = CURRENT_TIMESTAMP()',
            ];
            $params = [$actorUserId !== '' ? $actorUserId : null];

            if ($hasNotes) {
                // Keep existing tester notes; only stamp system note when empty.
                $setParts[] = 'tester_verification_notes = CASE
                    WHEN tester_verification_notes IS NULL OR TRIM(tester_verification_notes) = \'\'
                    THEN ?
                    ELSE tester_verification_notes
                END';
                $params[] = $noteText;
            }

            $where = [
                'project_id = ?',
                "status = 'fixed'",
                'tester_retested IS NULL',
            ];
            $params[] = $projectId;
            if ($hasDeletedAt) {
                $where[] = 'deleted_at IS NULL';
            }

            $sql = 'UPDATE bugs SET ' . implode(', ', $setParts)
                . ' WHERE ' . implode(' AND ', $where);
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $count = (int) $stmt->rowCount();

            if ($count > 0) {
                try {
                    $projectName = 'Project';
                    $nameStmt = $this->conn->prepare('SELECT name FROM projects WHERE id = ?');
                    $nameStmt->execute([$projectId]);
                    $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($nameRow['name'])) {
                        $projectName = (string) $nameRow['name'];
                    }

                    $logger = ActivityLogger::getInstance();
                    $logger->logActivity(
                        $actorUserId,
                        $projectId,
                        'project_updated',
                        "Auto-verified {$count} pending retest" . ($count === 1 ? '' : 's') . " as fixed on project close: {$projectName}",
                        $projectId,
                        [
                            'action' => 'auto_verify_pending_retests',
                            'verified_count' => $count,
                            'reason' => 'project_completed',
                        ]
                    );
                } catch (Throwable $e) {
                    error_log('autoVerifyPendingRetests activity log: ' . $e->getMessage());
                }
            }

            return $count;
        } catch (Throwable $e) {
            error_log('autoVerifyPendingRetests error: ' . $e->getMessage());
            return 0;
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
                "UPDATE projects
                 SET status = ?,
                     completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP()),
                     updated_at = CURRENT_TIMESTAMP()
                 WHERE id = ?"
            );
            try {
                $stmt->execute([$status, $projectId]);
            } catch (Throwable $e) {
                // Hosts without migration 087 yet — still finalize status
                if (stripos($e->getMessage(), 'completed_at') !== false) {
                    $fallback = $this->conn->prepare(
                        "UPDATE projects SET status = ?, updated_at = CURRENT_TIMESTAMP() WHERE id = ?"
                    );
                    $fallback->execute([$status, $projectId]);
                    $stmt = $fallback;
                } else {
                    throw $e;
                }
            }

            if ($stmt->rowCount() === 0) {
                $check = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
                $check->execute([$projectId]);
                if (!$check->fetch()) {
                    $this->sendJsonResponse(404, 'Project not found');
                    return;
                }
            }

            $autoVerified = $this->autoVerifyPendingRetests(
                (string) $projectId,
                (string) ($decoded->user_id ?? '')
            );

            $projectStmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ?");
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

            $payload = $this->buildCompliancePayload($projectId);
            $payload['project'] = $project;
            $payload['auto_verified_retests'] = $autoVerified;

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
            if (!br_require_codo_standards_access($this, $this->conn, $decoded)) {
                return;
            }
            $data = $this->getRequestData();
            $projectId = $data['project_id'] ?? null;
            $phase = $data['phase'] ?? null;
            $title = trim($data['title'] ?? '');
            $description = trim($data['description'] ?? '');
            $subtitle = trim($data['subtitle'] ?? '');
            $userRole = $this->getEffectiveUserRole($decoded);

            if (!$projectId || !$phase || $title === '' || $description === '') {
                $this->sendJsonResponse(400, 'project_id, phase, title, and description are required');
                return;
            }

            if (!in_array($phase, ['developer', 'tester', 'project'], true)) {
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

            if ($phase === 'project' && $userRole !== 'admin') {
                $this->sendJsonResponse(403, 'Only admins can add project-level rules');
                return;
            }

            if (!$this->userHasProjectAccess($decoded->user_id, $userRole, $decoded, $projectId)) {
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
