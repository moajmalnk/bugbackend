<?php
/**
 * Why: Onboarding contact email / emergency WhatsApp must not reuse any
 * existing account credentials or another employee's onboarding contacts.
 */

/**
 * Normalize to last 10 digits for Indian mobile uniqueness checks.
 */
function br_onboarding_phone_last10(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($digits) >= 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    return $digits;
}

/**
 * @return string|null Error message when taken, null when available
 */
function br_onboarding_contact_email_conflict(PDO $conn, string $email, string $excludeUserId): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address';
    }

    $stmt = $conn->prepare(
        'SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'This email is already registered on BugRicer. Use a different contact email.';
    }

    $hasTable = false;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'user_onboarding_details'");
        $hasTable = $t && $t->rowCount() > 0;
    } catch (Throwable $e) {
        $hasTable = false;
    }
    if (!$hasTable) {
        return null;
    }

    $cols = [];
    $colRes = $conn->query('SHOW COLUMNS FROM user_onboarding_details');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    }
    if (!in_array('contact_email', $cols, true)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT user_id FROM user_onboarding_details
         WHERE contact_email IS NOT NULL
           AND LOWER(TRIM(contact_email)) = ?
           AND user_id <> ?
         LIMIT 1'
    );
    $stmt->execute([$email, $excludeUserId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'This contact email is already used by another employee. Use a different email.';
    }

    return null;
}

/**
 * @return string|null Error message when taken, null when available
 */
function br_onboarding_emergency_phone_conflict(PDO $conn, string $phoneRaw, string $excludeUserId): ?string
{
    $last10 = br_onboarding_phone_last10($phoneRaw);
    if (strlen($last10) !== 10) {
        return 'Enter a valid 10-digit Indian mobile number';
    }

    // users.phone may be stored as +91… / 91… / 10 digits
    $stmt = $conn->prepare(
        "SELECT id FROM users
         WHERE phone IS NOT NULL AND phone <> ''
           AND RIGHT(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), 10) = ?
         LIMIT 1"
    );
    $stmt->execute([$last10]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'This WhatsApp number is already registered on BugRicer. Use a different emergency mobile.';
    }

    $hasTable = false;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'user_onboarding_details'");
        $hasTable = $t && $t->rowCount() > 0;
    } catch (Throwable $e) {
        $hasTable = false;
    }
    if (!$hasTable) {
        return null;
    }

    $cols = [];
    $colRes = $conn->query('SHOW COLUMNS FROM user_onboarding_details');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    }
    if (!in_array('emergency_contact', $cols, true)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT user_id FROM user_onboarding_details
         WHERE emergency_contact IS NOT NULL AND emergency_contact <> ''
           AND RIGHT(REPLACE(REPLACE(REPLACE(emergency_contact, '+', ''), ' ', ''), '-', ''), 10) = ?
           AND user_id <> ?
         LIMIT 1"
    );
    $stmt->execute([$last10, $excludeUserId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'This emergency mobile is already used by another employee. Use a different number.';
    }

    return null;
}
