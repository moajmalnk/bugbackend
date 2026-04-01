<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../bugs/BugController.php';
require_once __DIR__ . '/../docs/BugDocsController.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
require_once __DIR__ . '/../updates/updateController.php';
require_once __DIR__ . '/../../services/GeminiService.php';
require_once __DIR__ . '/prompts.php';

class BugBotController extends BaseAPI
{
    private function parseGeminiJson(string $text): array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [
            'kind' => 'chat',
            'message' => $text !== '' ? $text : 'No response from model.',
            'draft' => null,
        ];
    }

    private function buildDocContext(?string $projectId, string $userId): string
    {
        if (!$projectId) {
            return '';
        }
        try {
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                return '';
            }
            $docsCtl = new BugDocsController();
            $res = $docsCtl->getDocumentsByProject($projectId, $userId, false);
            $docs = $res['documents'] ?? [];
            if (!is_array($docs) || count($docs) === 0) {
                return '(No linked documents for this project.)';
            }
            $lines = [];
            foreach (array_slice($docs, 0, 25) as $d) {
                $title = $d['doc_title'] ?? 'Untitled';
                $url = $d['google_doc_url'] ?? '';
                $type = $d['doc_type'] ?? '';
                $lines[] = "- {$title} ({$type}) " . ($url ? $url : '');
            }
            return implode("\n", $lines);
        } catch (Exception $e) {
            error_log('BugBot doc context: ' . $e->getMessage());
            return '';
        }
    }

    public function handleChat(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $data = $this->getRequestData();
            $messages = $data['messages'] ?? null;
            if (!is_array($messages) || count($messages) === 0) {
                $this->sendJsonResponse(400, 'messages[] required');
                return;
            }
            $mode = isset($data['mode']) && $data['mode'] === 'developer_update' ? 'developer_update' : 'bug_report';
            $projectId = isset($data['project_id']) && is_string($data['project_id']) ? trim($data['project_id']) : null;
            if ($projectId === '') {
                $projectId = null;
            }

            $docBlock = $this->buildDocContext($projectId, $userId);
            $system = bugbot_system_prompt($mode, $docBlock);

            $gemini = new GeminiService();
            if (!$gemini->isConfigured()) {
                $this->sendJsonResponse(503, 'BugBot AI is not configured (missing GEMINI_API_KEY)');
                return;
            }

            $turns = [['role' => 'user', 'text' => $system . "\n\nConversation (user/assistant turns follow as labeled):"]];
            foreach ($messages as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $role = ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
                $content = isset($m['content']) ? (string)$m['content'] : '';
                if ($content === '') {
                    continue;
                }
                $turns[] = ['role' => $role, 'text' => $content];
            }

            try {
                $raw = $gemini->generateFromTurns($turns, [
                    'responseMimeType' => 'application/json',
                ]);
            } catch (Exception $e) {
                $raw = $gemini->generateFromTurns($turns, []);
            }
            $parsed = $this->parseGeminiJson($raw);

            $this->sendJsonResponse(200, 'OK', [
                'reply' => $parsed,
            ]);
        } catch (Exception $e) {
            error_log('BugBot chat: ' . $e->getMessage());
            $this->sendJsonResponse(502, 'AI error: ' . $e->getMessage());
        }
    }

    public function handleFormatUpdate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }
        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $rawText = isset($data['raw_text']) ? trim((string)$data['raw_text']) : '';
            if ($rawText === '') {
                $this->sendJsonResponse(400, 'raw_text required');
                return;
            }
            $projectId = isset($data['project_id']) ? trim((string)$data['project_id']) : '';
            $gemini = new GeminiService();
            if (!$gemini->isConfigured()) {
                $this->sendJsonResponse(503, 'BugBot AI is not configured');
                return;
            }
            $docBlock = $this->buildDocContext($projectId !== '' ? $projectId : null, $decoded->user_id);
            $system = bugbot_system_prompt('developer_update', $docBlock);
            $turns = [
                ['role' => 'user', 'text' => $system],
                ['role' => 'user', 'text' => "Developer note to formalize:\n" . $rawText . "\nproject_id hint: " . ($projectId ?: '(none)')],
            ];
            try {
                $raw = $gemini->generateFromTurns($turns, ['responseMimeType' => 'application/json']);
            } catch (Exception $e) {
                $raw = $gemini->generateFromTurns($turns, []);
            }
            $parsed = $this->parseGeminiJson($raw);
            $this->sendJsonResponse(200, 'OK', ['reply' => $parsed]);
        } catch (Exception $e) {
            error_log('BugBot format-update: ' . $e->getMessage());
            $this->sendJsonResponse(502, 'AI error: ' . $e->getMessage());
        }
    }

    public function handleFinalizeBug(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }
        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $draft = $data['draft'] ?? null;
            if (!is_array($draft)) {
                $this->sendJsonResponse(400, 'draft object required');
                return;
            }
            $projectId = isset($draft['project_id']) ? trim((string)$draft['project_id']) : '';
            if ($projectId === '' && isset($data['project_id'])) {
                $projectId = trim((string)$data['project_id']);
            }
            if ($projectId === '') {
                $this->sendJsonResponse(400, 'project_id required');
                return;
            }
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($decoded->user_id, $projectId)) {
                $this->sendJsonResponse(403, 'No access to this project');
                return;
            }

            $title = isset($draft['title']) ? trim((string)$draft['title']) : '';
            $description = isset($draft['description']) ? trim((string)$draft['description']) : '';
            if ($title === '' || $description === '') {
                $this->sendJsonResponse(400, 'draft.title and draft.description required');
                return;
            }

            $steps = $draft['steps_to_reproduce'] ?? null;
            if (is_array($steps)) {
                $steps = implode("\n", array_map('strval', $steps));
            } elseif ($steps !== null) {
                $steps = trim((string)$steps);
            } else {
                $steps = '';
            }
            if ($steps !== '') {
                $description .= "\n\nSteps to reproduce:\n" . $steps;
            }

            $priority = isset($draft['priority_suggestion']) ? strtolower(trim((string)$draft['priority_suggestion'])) : 'medium';
            if (!in_array($priority, ['low', 'medium', 'high'], true)) {
                $priority = 'medium';
            }

            $expected = isset($draft['expected_result']) ? trim((string)$draft['expected_result']) : null;
            $actual = isset($draft['actual_result']) ? trim((string)$draft['actual_result']) : null;
            if ($expected === '') {
                $expected = null;
            }
            if ($actual === '') {
                $actual = null;
            }

            $aiMetadata = [
                'source' => 'bugbot',
                'draft' => $draft,
            ];

            $bugCtl = new BugController();
            $result = $bugCtl->bugBotCreateBug($decoded, [
                'title' => $title,
                'description' => $description,
                'project_id' => $projectId,
                'priority' => $priority,
                'expected_result' => $expected,
                'actual_result' => $actual,
                'ai_metadata' => $aiMetadata,
            ]);

            $this->sendJsonResponse(200, 'Bug created', $result);
        } catch (Exception $e) {
            error_log('BugBot finalize-bug: ' . $e->getMessage());
            $this->sendJsonResponse(500, $e->getMessage());
        }
    }

    public function handleCreateUpdate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }
        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $draft = $data['draft'] ?? $data;
            if (!is_array($draft)) {
                $this->sendJsonResponse(400, 'draft required');
                return;
            }
            $projectId = trim((string)($draft['project_id'] ?? $data['project_id'] ?? ''));
            if ($projectId === '') {
                $this->sendJsonResponse(400, 'project_id required');
                return;
            }
            $title = trim((string)($draft['title'] ?? ''));
            $description = trim((string)($draft['description'] ?? ''));
            $type = strtolower(trim((string)($draft['type'] ?? 'updation')));
            if (!in_array($type, ['feature', 'updation', 'maintenance'], true)) {
                $type = 'updation';
            }
            if ($title === '' || $description === '') {
                $this->sendJsonResponse(400, 'title and description required');
                return;
            }

            $uc = new UpdateController();
            $out = $uc->bugBotCreateUpdateJson($decoded, [
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'project_id' => $projectId,
            ]);
            $this->sendJsonResponse(201, 'Update created', $out);
        } catch (Exception $e) {
            error_log('BugBot create-update: ' . $e->getMessage());
            $this->sendJsonResponse(500, $e->getMessage());
        }
    }

    public function handleShadowIngest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }
        try {
            $this->validateToken();
            $data = $this->getRequestData();
            $events = $data['events'] ?? $data;
            if (!is_array($events)) {
                $this->sendJsonResponse(400, 'events array expected');
                return;
            }
            error_log('BugBot shadow-ingest: received ' . count($events) . ' event(s) (no-op storage)');
            $this->sendJsonResponse(200, 'Accepted', ['stored' => 0]);
        } catch (Exception $e) {
            $this->sendJsonResponse(401, $e->getMessage());
        }
    }
}
