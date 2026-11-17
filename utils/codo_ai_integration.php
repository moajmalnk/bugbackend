<?php
/**
 * CODO AI Innovations M2M Profile Photo API helpers.
 *
 * Why: codoai.in needs read-only employee avatar URLs for public team pages.
 * Auth is a shared Bearer secret (never JWT). Response fields are identity keys
 * + photo URL only — no Aadhaar, PAN, bank, or other PII.
 */

require_once __DIR__ . '/user_avatar.php';

/** @var float|null */
$GLOBALS['br_codo_ai_req_start'] = $GLOBALS['br_codo_ai_req_start'] ?? null;
/** @var string */
$GLOBALS['br_codo_ai_req_route'] = $GLOBALS['br_codo_ai_req_route'] ?? '';
/** @var bool */
$GLOBALS['br_codo_ai_req_logged'] = $GLOBALS['br_codo_ai_req_logged'] ?? false;

const BR_CODO_AI_RATE_LIMIT_MAX = 120;
const BR_CODO_AI_RATE_LIMIT_WINDOW_SEC = 60;
const BR_CODO_AI_BATCH_MAX = 100;
const BR_CODO_AI_CACHE_MAX_AGE = 86400;
const BR_CODO_AI_BACKEND_ORIGIN_DEFAULT = 'https://bugbackend.bugricer.com';

/**
 * Mark request start for latency logging.
 */
function br_codo_ai_begin_request(string $route): void
{
    $GLOBALS['br_codo_ai_req_start'] = microtime(true);
    $GLOBALS['br_codo_ai_req_route'] = $route;
    $GLOBALS['br_codo_ai_req_logged'] = false;
}

/**
 * Structured access log: route, status, latency_ms.
 */
function br_codo_ai_log_request(int $status): void
{
    if (!empty($GLOBALS['br_codo_ai_req_logged'])) {
        return;
    }
    $GLOBALS['br_codo_ai_req_logged'] = true;

    $start = $GLOBALS['br_codo_ai_req_start'] ?? null;
    $route = (string)($GLOBALS['br_codo_ai_req_route'] ?? 'unknown');
    $ms = ($start !== null) ? (int)round((microtime(true) - (float)$start) * 1000) : 0;
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '?');

    error_log(sprintf(
        'codo_ai_integration route=%s method=%s status=%d latency_ms=%d',
        $route !== '' ? $route : 'unknown',
        $method,
        $status,
        $ms
    ));
}

/**
 * Lightweight file-backed rate limit for /v1/users/*avatar*.
 */
function br_codo_ai_rate_limit_check(): void
{
    $dir = sys_get_temp_dir();
    $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bugricer_codo_ai_rl.json';
    $now = time();
    $windowStart = $now - BR_CODO_AI_RATE_LIMIT_WINDOW_SEC;

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }

        $raw = stream_get_contents($fh);
        $hits = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $ts) {
                    $t = (int)$ts;
                    if ($t >= $windowStart) {
                        $hits[] = $t;
                    }
                }
            }
        }

        if (count($hits) >= BR_CODO_AI_RATE_LIMIT_MAX) {
            flock($fh, LOCK_UN);
            fclose($fh);
            br_codo_ai_json_response(429, [
                'success' => false,
                'message' => 'Too many requests',
                'data' => null,
            ]);
        }

        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        error_log('codo_ai_rate_limit: ' . $e->getMessage());
    }
}

/**
 * Read Authorization: Bearer token from common Apache / CGI headers.
 */
function br_codo_ai_read_bearer_token(): ?string
{
    $header = null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, 'Authorization') === 0) {
                $header = trim((string)$value);
                break;
            }
        }
    }

    if ($header !== null && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Shared server secret only — reject missing/invalid Bearer with 401.
 */
function br_codo_ai_require_auth(): void
{
    if (!class_exists('Environment')) {
        require_once __DIR__ . '/../config/environment.php';
    }

    $expected = getenv('CODO_AI_INTEGRATION_TOKEN');
    if ($expected === false || $expected === '') {
        $expected = (string)(Environment::get('CODO_AI_INTEGRATION_TOKEN') ?? '');
    }
    $expected = trim((string)$expected);
    $provided = br_codo_ai_read_bearer_token();

    if ($expected === '' || $provided === null || !hash_equals($expected, $provided)) {
        br_codo_ai_json_response(401, [
            'success' => false,
            'message' => 'Unauthorized',
            'data' => null,
        ]);
    }
}

/**
 * Emit JSON and exit. Sets Cache-Control / ETag when provided.
 *
 * @param array<string,mixed> $payload
 * @param string|null $etag
 */
function br_codo_ai_json_response(int $status, array $payload, ?string $etag = null): void
{
    br_codo_ai_log_request($status);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if ($status >= 200 && $status < 300) {
            header('Cache-Control: private, max-age=' . BR_CODO_AI_CACHE_MAX_AGE);
            if ($etag !== null && $etag !== '') {
                header('ETag: "' . $etag . '"');
            }
        } else {
            header('Cache-Control: private, no-store');
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return list<string>
 */
function br_codo_ai_users_columns(PDO $conn): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cols = [];
    try {
        $res = $conn->query('SHOW COLUMNS FROM users');
        while ($res && ($row = $res->fetch(PDO::FETCH_ASSOC))) {
            $cols[] = (string)($row['Field'] ?? '');
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    $cached = $cols;
    return $cached;
}

/**
 * Active staff filter for avatar API.
 *
 * Why: Admin UI "Account active" is driven by users.account_active only.
 * Requiring employment_status='active' 404'd recently-activated users when HR
 * status lagged behind account_active (e.g. theshrifa / CODO-TSLP-TKTM).
 */
function br_codo_ai_active_user_where(PDO $conn, string $alias = 'u'): string
{
    $cols = br_codo_ai_users_columns($conn);
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'u';
    $parts = [];
    if (in_array('deleted_at', $cols, true)) {
        $parts[] = "{$a}.deleted_at IS NULL";
    }
    if (in_array('account_active', $cols, true)) {
        $parts[] = "COALESCE({$a}.account_active, 1) = 1";
    }
    return $parts ? (' AND ' . implode(' AND ', $parts)) : '';
}

/**
 * True when key looks like a CODO employee_code (CODO-XXXX-XXXX…).
 */
function br_codo_ai_looks_like_employee_code(string $key): bool
{
    return (bool)preg_match('/^CODO-[A-Z0-9]{4}-[A-Z0-9]{4}(-[A-Z])?$/i', trim($key));
}

/**
 * Backend origin for absolute upload URLs (no trailing slash).
 */
function br_codo_ai_backend_origin(): string
{
    if (!class_exists('Environment')) {
        require_once __DIR__ . '/../config/environment.php';
    }

    $fromEnv = getenv('BUGRICER_BACKEND_ORIGIN');
    if ($fromEnv === false || $fromEnv === '') {
        $fromEnv = (string)(Environment::get('BUGRICER_BACKEND_ORIGIN') ?? '');
    }
    $fromEnv = rtrim(trim((string)$fromEnv), '/');
    if ($fromEnv !== '' && preg_match('#^https?://#i', $fromEnv)) {
        return $fromEnv;
    }

    $apiBase = getenv('BUGRICER_API_BASE_URL');
    if ($apiBase === false || $apiBase === '') {
        $apiBase = (string)(Environment::get('BUGRICER_API_BASE_URL') ?? '');
    }
    $apiBase = rtrim(trim((string)$apiBase), '/');
    if ($apiBase !== '' && preg_match('#^https?://#i', $apiBase)) {
        return preg_replace('#/api$#i', '', $apiBase) ?: BR_CODO_AI_BACKEND_ORIGIN_DEFAULT;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host)) {
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    return BR_CODO_AI_BACKEND_ORIGIN_DEFAULT;
}

/**
 * True when avatar is a real photo (not empty, brand mark, or ui-avatars).
 */
function br_codo_ai_has_photo(?string $raw): bool
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return false;
    }
    if (preg_match('#^https?://ui-avatars\.com/#i', $raw)) {
        return false;
    }
    if (preg_match('#/brand/bugricer-(mark|wordmark)-#i', $raw)) {
        return false;
    }
    if (preg_match('#/logo\.png(?:$|\?)#i', $raw)) {
        return false;
    }
    return true;
}

/**
 * Build a stable absolute HTTPS (or http for local) avatar URL, or null.
 */
function br_codo_ai_absolute_avatar_url(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if (!br_codo_ai_has_photo($raw)) {
        return null;
    }

    $origin = br_codo_ai_backend_origin();

    if (preg_match('#^https?://#i', $raw)) {
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/#i', $raw)) {
            if (preg_match('#(uploads/[^?#]+)#i', $raw, $m)) {
                return $origin . '/' . ltrim($m[1], '/');
            }
        }
        return $raw;
    }

    if (preg_match('#(uploads/[^?#]+)#i', $raw, $m)) {
        return $origin . '/' . ltrim($m[1], '/');
    }

    if (str_starts_with($raw, '/')) {
        return $origin . $raw;
    }

    return $origin . '/' . ltrim(preg_replace('#^\./#', '', $raw) ?? $raw, '/');
}

/**
 * ISO-8601 UTC from DB datetime.
 */
function br_codo_ai_iso_utc(?string $dbDatetime): ?string
{
    if ($dbDatetime === null || trim($dbDatetime) === '') {
        return null;
    }
    try {
        $dt = new DateTime($dbDatetime);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Map a users row to the public CODO avatar DTO.
 *
 * @param array<string,mixed> $row
 * @return array{
 *   employee_id: string|null,
 *   email: string|null,
 *   username: string|null,
 *   avatar_url: string|null,
 *   has_photo: bool,
 *   updated_at: string|null
 * }
 */
function br_codo_ai_map_avatar_dto(array $row): array
{
    $resolved = br_user_resolve_avatar($row);
    $url = br_codo_ai_absolute_avatar_url($resolved);
    $employeeCode = trim((string)($row['employee_code'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));

    return [
        'employee_id' => $employeeCode !== '' ? $employeeCode : null,
        'email' => $email !== '' ? $email : null,
        'username' => $username !== '' ? $username : null,
        'avatar_url' => $url,
        'has_photo' => $url !== null,
        'updated_at' => br_codo_ai_iso_utc(
            isset($row['updated_at']) ? (string)$row['updated_at'] : null
        ),
    ];
}

/**
 * Empty placeholder DTO for unknown/inactive keys in batch responses.
 *
 * @return array{
 *   employee_id: string|null,
 *   email: string|null,
 *   username: string|null,
 *   avatar_url: null,
 *   has_photo: false,
 *   updated_at: null
 * }
 */
function br_codo_ai_empty_dto(
    ?string $employeeId = null,
    ?string $email = null,
    ?string $username = null
): array {
    return [
        'employee_id' => $employeeId !== null && $employeeId !== '' ? $employeeId : null,
        'email' => $email !== null && $email !== '' ? $email : null,
        'username' => $username !== null && $username !== '' ? $username : null,
        'avatar_url' => null,
        'has_photo' => false,
        'updated_at' => null,
    ];
}

/**
 * SELECT fragment for avatar lookup rows.
 *
 * @return array{0: string, 1: bool} [selectSql, hasEmployeeCode]
 */
function br_codo_ai_select_sql(PDO $conn): array
{
    $cols = br_codo_ai_users_columns($conn);
    $select = ['id', 'email', 'username', 'updated_at'];
    $hasEmployeeCode = in_array('employee_code', $cols, true);
    if ($hasEmployeeCode) {
        $select[] = 'employee_code';
    }
    $select = br_user_avatar_select_cols($select, $cols);
    return [implode(', ', $select), $hasEmployeeCode];
}

/**
 * Find one active user by employee_code, email, username, or onboarding contact_email.
 *
 * @return array<string,mixed>|null
 */
function br_codo_ai_find_user(
    PDO $conn,
    ?string $employeeCode = null,
    ?string $email = null,
    ?string $username = null
): ?array {
    [$selectSql, $hasEmployeeCode] = br_codo_ai_select_sql($conn);
    $active = br_codo_ai_active_user_where($conn, 'u');

    $employeeCode = $employeeCode !== null ? trim($employeeCode) : '';
    $email = $email !== null ? trim($email) : '';
    $username = $username !== null ? trim($username) : '';

    // Safety net: non-CODO path keys passed as employeeCode → also try username.
    if (
        $employeeCode !== ''
        && $username === ''
        && !br_codo_ai_looks_like_employee_code($employeeCode)
        && !str_contains($employeeCode, '@')
    ) {
        $username = $employeeCode;
        $employeeCode = '';
    }

    if ($employeeCode !== '' && $hasEmployeeCode && br_codo_ai_looks_like_employee_code($employeeCode)) {
        $sql = "SELECT {$selectSql} FROM users u
                WHERE UPPER(TRIM(u.employee_code)) = UPPER(?) {$active}
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$employeeCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if ($email !== '') {
        $sql = "SELECT {$selectSql} FROM users u
                WHERE LOWER(TRIM(u.email)) = LOWER(?) {$active}
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // Fallback: onboarding contact_email (personal) when login email differs.
        try {
            $odCols = [];
            $odRes = $conn->query('SHOW COLUMNS FROM user_onboarding_details');
            while ($odRes && ($odRow = $odRes->fetch(PDO::FETCH_ASSOC))) {
                $odCols[] = (string)($odRow['Field'] ?? '');
            }
            if (in_array('contact_email', $odCols, true) && in_array('user_id', $odCols, true)) {
                $sql = "SELECT {$selectSql}
                        FROM users u
                        INNER JOIN user_onboarding_details od ON od.user_id = u.id
                        WHERE LOWER(TRIM(od.contact_email)) = LOWER(?) {$active}
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$email]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable $e) {
            error_log('codo_ai contact_email lookup: ' . $e->getMessage());
        }
    }

    if ($username !== '') {
        $sql = "SELECT {$selectSql} FROM users u
                WHERE LOWER(TRIM(u.username)) = LOWER(?) {$active}
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

/**
 * Batch load active users by employee_codes.
 *
 * @param list<string> $codes
 * @return array<string, array<string,mixed>> keyed by UPPER(employee_code)
 */
function br_codo_ai_find_users_by_codes(PDO $conn, array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map(
        static fn($c) => strtoupper(trim((string)$c)),
        $codes
    ), static fn($c) => $c !== '')));
    if ($codes === []) {
        return [];
    }

    [$selectSql, $hasEmployeeCode] = br_codo_ai_select_sql($conn);
    if (!$hasEmployeeCode) {
        return [];
    }

    $active = br_codo_ai_active_user_where($conn, 'u');
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT {$selectSql} FROM users u
            WHERE UPPER(TRIM(u.employee_code)) IN ({$placeholders}) {$active}";
    $stmt = $conn->prepare($sql);
    $stmt->execute($codes);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = strtoupper(trim((string)($row['employee_code'] ?? '')));
        if ($key !== '') {
            $out[$key] = $row;
        }
    }
    return $out;
}

/**
 * Batch load active users by emails (lowercase keys).
 *
 * @param list<string> $emails
 * @return array<string, array<string,mixed>>
 */
function br_codo_ai_find_users_by_emails(PDO $conn, array $emails): array
{
    $emails = array_values(array_unique(array_filter(array_map(
        static fn($e) => strtolower(trim((string)$e)),
        $emails
    ), static fn($e) => $e !== '')));
    if ($emails === []) {
        return [];
    }

    [$selectSql] = br_codo_ai_select_sql($conn);
    $active = br_codo_ai_active_user_where($conn, 'u');
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $sql = "SELECT {$selectSql} FROM users u
            WHERE LOWER(TRIM(u.email)) IN ({$placeholders}) {$active}";
    $stmt = $conn->prepare($sql);
    $stmt->execute($emails);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = strtolower(trim((string)($row['email'] ?? '')));
        if ($key !== '') {
            $out[$key] = $row;
        }
    }
    return $out;
}

/**
 * Batch load active users by usernames (lowercase keys).
 *
 * @param list<string> $usernames
 * @return array<string, array<string,mixed>>
 */
function br_codo_ai_find_users_by_usernames(PDO $conn, array $usernames): array
{
    $usernames = array_values(array_unique(array_filter(array_map(
        static fn($u) => strtolower(trim((string)$u)),
        $usernames
    ), static fn($u) => $u !== '')));
    if ($usernames === []) {
        return [];
    }

    [$selectSql] = br_codo_ai_select_sql($conn);
    $active = br_codo_ai_active_user_where($conn, 'u');
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $sql = "SELECT {$selectSql} FROM users u
            WHERE LOWER(TRIM(u.username)) IN ({$placeholders}) {$active}";
    $stmt = $conn->prepare($sql);
    $stmt->execute($usernames);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = strtolower(trim((string)($row['username'] ?? '')));
        if ($key !== '') {
            $out[$key] = $row;
        }
    }
    return $out;
}

/**
 * ETag from DTO list (photo path + updated_at).
 *
 * @param list<array<string,mixed>> $dtos
 */
function br_codo_ai_etag_for_dtos(array $dtos): string
{
    $parts = [];
    foreach ($dtos as $dto) {
        $parts[] = implode('|', [
            (string)($dto['employee_id'] ?? ''),
            (string)($dto['email'] ?? ''),
            (string)($dto['username'] ?? ''),
            (string)($dto['avatar_url'] ?? ''),
            (string)($dto['updated_at'] ?? ''),
            !empty($dto['has_photo']) ? '1' : '0',
        ]);
    }
    return substr(hash('sha256', implode("\n", $parts)), 0, 32);
}

/**
 * Normalize a comma-separated or array list of keys; enforce batch cap.
 *
 * @param mixed $raw
 * @return list<string>|null null when over cap
 */
function br_codo_ai_normalize_key_list($raw): ?array
{
    $items = [];
    if (is_string($raw)) {
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        foreach ($parts as $p) {
            $t = trim((string)$p);
            if ($t !== '') {
                $items[] = $t;
            }
        }
    } elseif (is_array($raw)) {
        foreach ($raw as $p) {
            $t = trim((string)$p);
            if ($t !== '') {
                $items[] = $t;
            }
        }
    }

    $items = array_values(array_unique($items));
    if (count($items) > BR_CODO_AI_BATCH_MAX) {
        return null;
    }
    return $items;
}
