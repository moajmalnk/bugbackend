<?php
/**
 * Why: CODO employee codes encode join month/year + DOB month/year with a
 * fixed letter cipher so IDs are human-readable and deterministic.
 *
 * Cipher: K=1 L=2 M=3 N=4 O=5 P=6 Q=7 R=8 S=9 T=0
 * Format: CODO-{join MMYY cipher}-{dob MMYY cipher}[+ -A/-B on collision]
 * Example: join 2024-06-05 + DOB 2001-12-30 → CODO-TPLN-KLTK
 */

/**
 * Digit → CODO letter map (0–9).
 *
 * @return array<string, string>
 */
function br_employee_id_digit_map(): array
{
    return [
        '0' => 'T',
        '1' => 'K',
        '2' => 'L',
        '3' => 'M',
        '4' => 'N',
        '5' => 'O',
        '6' => 'P',
        '7' => 'Q',
        '8' => 'R',
        '9' => 'S',
    ];
}

/**
 * Encode a 4-digit MMYY segment into 4 letters.
 *
 * @param string $mmyy Exactly 4 digits
 */
function br_encode_codo_segment(string $mmyy): ?string
{
    if (!preg_match('/^\d{4}$/', $mmyy)) {
        return null;
    }
    $map = br_employee_id_digit_map();
    $out = '';
    for ($i = 0; $i < 4; $i++) {
        $out .= $map[$mmyy[$i]];
    }
    return $out;
}

/**
 * Build MMYY from a DATE / YYYY-MM-DD string (month + 2-digit year).
 */
function br_date_to_mmyy(?string $date): ?string
{
    if ($date === null || trim($date) === '') {
        return null;
    }
    $ts = strtotime(substr(trim($date), 0, 10));
    if ($ts === false) {
        return null;
    }
    return date('my', $ts); // mm + yy
}

/**
 * Build base (no suffix) employee code from join + DOB dates.
 */
function br_employee_code_base(?string $joiningDate, ?string $dateOfBirth): ?string
{
    $joinSeg = br_encode_codo_segment(br_date_to_mmyy($joiningDate) ?? '');
    $dobSeg = br_encode_codo_segment(br_date_to_mmyy($dateOfBirth) ?? '');
    if ($joinSeg === null || $dobSeg === null) {
        return null;
    }
    return 'CODO-' . $joinSeg . '-' . $dobSeg;
}

/**
 * Normalize a client-supplied employee code (uppercase, allowed charset).
 */
function br_normalize_employee_code(?string $code): ?string
{
    if ($code === null) {
        return null;
    }
    $trimmed = strtoupper(trim($code));
    if ($trimmed === '') {
        return null;
    }
    // CODO-XXXX-XXXX or CODO-XXXX-XXXX-A
    if (!preg_match('/^CODO-[A-Z]{4}-[A-Z]{4}(-[A-Z])?$/', $trimmed)) {
        return null;
    }
    return $trimmed;
}

/**
 * Whether employee_code is already taken by another user.
 *
 * @param PDO $pdo
 * @param string $code
 * @param string|null $excludeUserId
 */
function br_employee_code_exists(PDO $pdo, string $code, ?string $excludeUserId = null): bool
{
    if ($excludeUserId) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM users WHERE employee_code = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$code, $excludeUserId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM users WHERE employee_code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
    }
    return (bool) $stmt->fetchColumn();
}

/**
 * Generate a unique CODO employee code. Appends -A … -Z on collision.
 *
 * @param PDO $pdo
 * @param string $joiningDate YYYY-MM-DD
 * @param string $dateOfBirth YYYY-MM-DD
 * @param string|null $excludeUserId Skip this user when checking uniqueness
 * @return string|null Null when dates invalid or alphabet exhausted
 */
function br_generate_employee_code(
    PDO $pdo,
    string $joiningDate,
    string $dateOfBirth,
    ?string $excludeUserId = null
): ?string {
    $base = br_employee_code_base($joiningDate, $dateOfBirth);
    if ($base === null) {
        return null;
    }

    if (!br_employee_code_exists($pdo, $base, $excludeUserId)) {
        return $base;
    }

    foreach (range('A', 'Z') as $suffix) {
        $candidate = $base . '-' . $suffix;
        if (!br_employee_code_exists($pdo, $candidate, $excludeUserId)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Auto-assign employee_code when empty and both dates are available.
 *
 * @return string|null The code that was set (or already present), null if skipped
 */
function br_ensure_employee_code(PDO $pdo, string $userId): ?string
{
    $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('employee_code', $cols, true)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT employee_code, joining_date FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }

    $existing = isset($user['employee_code']) ? trim((string) $user['employee_code']) : '';
    if ($existing !== '') {
        return $existing;
    }

    $joining = $user['joining_date'] ?? null;
    if (!$joining) {
        return null;
    }

    $dobStmt = $pdo->prepare(
        'SELECT date_of_birth FROM user_onboarding_details WHERE user_id = ? LIMIT 1'
    );
    $dobStmt->execute([$userId]);
    $dob = $dobStmt->fetchColumn();
    if (!$dob) {
        return null;
    }

    $code = br_generate_employee_code($pdo, (string) $joining, (string) $dob, $userId);
    if ($code === null) {
        return null;
    }

    $upd = $pdo->prepare('UPDATE users SET employee_code = ? WHERE id = ? AND (employee_code IS NULL OR employee_code = \'\')');
    $upd->execute([$code, $userId]);
    return $code;
}

/**
 * Append HR employment columns to a users SELECT list when they exist.
 *
 * @param list<string> $select
 * @param list<string> $cols
 * @return list<string>
 */
function br_user_hr_select_cols(array $select, array $cols): array
{
    $hr = [
        'employee_code',
        'job_title',
        'job_level',
        'department',
        'reports_to_user_id',
        'contract_type',
        'offer_letter_issued',
        'probation_end_date',
        'employment_status',
    ];
    foreach ($hr as $col) {
        if (in_array($col, $cols, true) && !in_array($col, $select, true)) {
            $select[] = $col;
        }
    }
    return $select;
}

/**
 * Resolve manager display name for API responses.
 *
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function br_user_with_reports_to_name(PDO $pdo, array $user): array
{
    $managerId = isset($user['reports_to_user_id']) ? trim((string) $user['reports_to_user_id']) : '';
    if ($managerId === '') {
        $user['reports_to_username'] = null;
        return $user;
    }
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$managerId]);
    $name = $stmt->fetchColumn();
    $user['reports_to_username'] = $name !== false ? (string) $name : null;
    return $user;
}

/**
 * Force-regenerate employee_code from joining_date + DOB (admin action).
 */
function br_regenerate_employee_code(PDO $pdo, string $userId): ?string
{
    $stmt = $pdo->prepare('SELECT joining_date FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $joining = $stmt->fetchColumn();
    if (!$joining) {
        return null;
    }

    $dobStmt = $pdo->prepare(
        'SELECT date_of_birth FROM user_onboarding_details WHERE user_id = ? LIMIT 1'
    );
    $dobStmt->execute([$userId]);
    $dob = $dobStmt->fetchColumn();
    if (!$dob) {
        return null;
    }

    $code = br_generate_employee_code($pdo, (string) $joining, (string) $dob, $userId);
    if ($code === null) {
        return null;
    }

    $upd = $pdo->prepare('UPDATE users SET employee_code = ? WHERE id = ?');
    $upd->execute([$code, $userId]);
    return $code;
}
