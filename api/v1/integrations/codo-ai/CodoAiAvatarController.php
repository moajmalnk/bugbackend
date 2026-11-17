<?php
/**
 * CODO AI Innovations read-only Profile Photo API (M2M).
 *
 * Why: Partner fetches stable HTTPS avatar URLs by employee_code / email / username
 * for public team pages. Manual photos on codoai.in always win on their side.
 */

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../utils/codo_ai_integration.php';

class CodoAiAvatarController
{
    /** @var PDO */
    private $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function dispatch(string $route, ?string $key = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        br_codo_ai_require_auth();
        br_codo_ai_rate_limit_check();

        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($route === 'user-avatar') {
            if ($method !== 'GET') {
                br_codo_ai_json_response(405, [
                    'success' => false,
                    'message' => 'Method not allowed',
                    'data' => null,
                ]);
            }
            $this->singleAvatar($key);
            return;
        }

        if ($route === 'avatars') {
            if ($method === 'GET') {
                $this->batchAvatarsGet();
                return;
            }
            if ($method === 'POST') {
                $this->batchAvatarsPost();
                return;
            }
            br_codo_ai_json_response(405, [
                'success' => false,
                'message' => 'Method not allowed',
                'data' => null,
            ]);
        }

        br_codo_ai_json_response(404, [
            'success' => false,
            'message' => 'Not found',
            'data' => null,
        ]);
    }

    /**
     * GET /v1/users/{key}/avatar
     * Optional overrides: ?email= & ?username= (path key still tried as employee_code).
     */
    private function singleAvatar(?string $pathKey): void
    {
        $pathKey = $pathKey !== null ? trim(rawurldecode($pathKey)) : '';
        $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
        $username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';

        $employeeCode = $pathKey;
        // If path looks like an email, treat as email lookup.
        if ($employeeCode !== '' && str_contains($employeeCode, '@')) {
            if ($email === '') {
                $email = $employeeCode;
            }
            $employeeCode = '';
        }

        if ($employeeCode === '' && $email === '' && $username === '') {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'employee id, email, or username is required',
                'data' => null,
            ]);
        }

        $row = br_codo_ai_find_user(
            $this->conn,
            $employeeCode !== '' ? $employeeCode : null,
            $email !== '' ? $email : null,
            $username !== '' ? $username : null
        );

        if ($row === null) {
            br_codo_ai_json_response(404, [
                'success' => false,
                'message' => 'User not found',
                'data' => null,
            ]);
        }

        $dto = br_codo_ai_map_avatar_dto($row);
        $etag = br_codo_ai_etag_for_dtos([$dto]);

        if ($this->clientEtagMatches($etag)) {
            br_codo_ai_log_request(304);
            http_response_code(304);
            header('ETag: "' . $etag . '"');
            header('Cache-Control: private, max-age=' . BR_CODO_AI_CACHE_MAX_AGE);
            exit;
        }

        br_codo_ai_json_response(200, [
            'success' => true,
            'data' => [$dto],
        ], $etag);
    }

    /**
     * GET /v1/users/avatars?ids=&emails=&usernames=
     */
    private function batchAvatarsGet(): void
    {
        $ids = br_codo_ai_normalize_key_list($_GET['ids'] ?? '');
        $emails = br_codo_ai_normalize_key_list($_GET['emails'] ?? '');
        $usernames = br_codo_ai_normalize_key_list($_GET['usernames'] ?? '');

        if ($ids === null || $emails === null || $usernames === null) {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'Batch limit is ' . BR_CODO_AI_BATCH_MAX . ' keys',
                'data' => null,
            ]);
        }

        $this->respondBatch($ids, $emails, $usernames);
    }

    /**
     * POST /v1/users/avatars
     * Body: { "employee_ids": [], "emails": [], "usernames": [] }
     */
    private function batchAvatarsPost(): void
    {
        $raw = file_get_contents('php://input');
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'JSON body required',
                'data' => null,
            ]);
        }

        $ids = br_codo_ai_normalize_key_list(
            $body['employee_ids'] ?? ($body['ids'] ?? [])
        );
        $emails = br_codo_ai_normalize_key_list($body['emails'] ?? []);
        $usernames = br_codo_ai_normalize_key_list($body['usernames'] ?? []);

        if ($ids === null || $emails === null || $usernames === null) {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'Batch limit is ' . BR_CODO_AI_BATCH_MAX . ' keys',
                'data' => null,
            ]);
        }

        $this->respondBatch($ids, $emails, $usernames);
    }

    /**
     * @param list<string> $ids
     * @param list<string> $emails
     * @param list<string> $usernames
     */
    private function respondBatch(array $ids, array $emails, array $usernames): void
    {
        if ($ids === [] && $emails === [] && $usernames === []) {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'Provide ids, emails, and/or usernames',
                'data' => null,
            ]);
        }

        $total = count($ids) + count($emails) + count($usernames);
        if ($total > BR_CODO_AI_BATCH_MAX) {
            br_codo_ai_json_response(422, [
                'success' => false,
                'message' => 'Batch limit is ' . BR_CODO_AI_BATCH_MAX . ' keys',
                'data' => null,
            ]);
        }

        $byCode = br_codo_ai_find_users_by_codes($this->conn, $ids);
        $byEmail = br_codo_ai_find_users_by_emails($this->conn, $emails);
        $byUsername = br_codo_ai_find_users_by_usernames($this->conn, $usernames);

        $data = [];
        foreach ($ids as $id) {
            $key = strtoupper(trim($id));
            if (isset($byCode[$key])) {
                $data[] = br_codo_ai_map_avatar_dto($byCode[$key]);
            } else {
                $data[] = br_codo_ai_empty_dto($id, null, null);
            }
        }
        foreach ($emails as $email) {
            $key = strtolower(trim($email));
            if (isset($byEmail[$key])) {
                $data[] = br_codo_ai_map_avatar_dto($byEmail[$key]);
            } else {
                $data[] = br_codo_ai_empty_dto(null, $email, null);
            }
        }
        foreach ($usernames as $username) {
            $key = strtolower(trim($username));
            if (isset($byUsername[$key])) {
                $data[] = br_codo_ai_map_avatar_dto($byUsername[$key]);
            } else {
                $data[] = br_codo_ai_empty_dto(null, null, $username);
            }
        }

        $etag = br_codo_ai_etag_for_dtos($data);
        if ($this->clientEtagMatches($etag)) {
            br_codo_ai_log_request(304);
            http_response_code(304);
            header('ETag: "' . $etag . '"');
            header('Cache-Control: private, max-age=' . BR_CODO_AI_CACHE_MAX_AGE);
            exit;
        }

        br_codo_ai_json_response(200, [
            'success' => true,
            'data' => $data,
        ], $etag);
    }

    private function clientEtagMatches(string $etag): bool
    {
        $incoming = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($incoming === '') {
            return false;
        }
        $incoming = trim($incoming, " \t\"");
        return hash_equals($etag, $incoming);
    }
}
