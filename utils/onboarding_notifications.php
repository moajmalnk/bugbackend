<?php
/**
 * Why: Onboarding verification is an HR workflow — admins must hear about new
 * submissions, and employees must hear when docs are verified or rejected,
 * across push, email, and WhatsApp (same channels as WFH requests).
 */

/**
 * Alert all active admins that an employee finished the onboarding wizard.
 */
function br_notify_admins_onboarding_submitted(PDO $conn, string $userId, ?string $username = null): void
{
    $username = trim((string) $username);
    if ($username === '') {
        try {
            $stmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $username = trim((string) ($stmt->fetchColumn() ?: '')) ?: 'Employee';
        } catch (Throwable $e) {
            $username = 'Employee';
        }
    }

    try {
        require_once __DIR__ . '/../api/NotificationManager.php';
        NotificationManager::getInstance()->notifyOnboardingSubmitted($userId, $username);
    } catch (Throwable $e) {
        error_log('onboarding submit push: ' . $e->getMessage());
    }

    try {
        $adminStmt = $conn->prepare(
            "SELECT email, phone FROM users
             WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)
               AND (email IS NOT NULL OR phone IS NOT NULL)"
        );
        $adminStmt->execute();
        $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $adminEmails = array_values(array_unique(array_filter(array_map(
            static fn($r) => trim((string) ($r['email'] ?? '')),
            $adminRows
        ))));
        $adminPhones = array_values(array_unique(array_filter(array_map(
            static fn($r) => trim((string) ($r['phone'] ?? '')),
            $adminRows
        ))));

        require_once __DIR__ . '/email.php';
        foreach ($adminEmails as $adminEmail) {
            sendOnboardingSubmittedAdminEmail($adminEmail, $username);
        }

        require_once __DIR__ . '/whatsapp.php';
        foreach ($adminPhones as $adminPhone) {
            sendOnboardingSubmittedAdminWhatsApp($adminPhone, $username);
        }
    } catch (Throwable $e) {
        error_log('onboarding submit mail/wa: ' . $e->getMessage());
    }
}

/**
 * Why: Profile edits re-queue HR review — push only so Save stays snappy
 * (email/WhatsApp to every admin made updates feel stuck on "Saving…").
 */
function br_notify_admins_onboarding_updated(PDO $conn, string $userId, ?string $username = null): void
{
    $username = trim((string) $username);
    if ($username === '') {
        try {
            $stmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $username = trim((string) ($stmt->fetchColumn() ?: '')) ?: 'Employee';
        } catch (Throwable $e) {
            $username = 'Employee';
        }
    }

    try {
        require_once __DIR__ . '/../api/NotificationManager.php';
        NotificationManager::getInstance()->notifyOnboardingSubmitted($userId, $username);
    } catch (Throwable $e) {
        error_log('onboarding update push: ' . $e->getMessage());
    }
}

/**
 * Alert the employee when HR verifies or rejects their onboarding documents.
 *
 * @param 'verified'|'rejected' $status
 */
function br_notify_employee_onboarding_decision(
    PDO $conn,
    string $userId,
    string $status,
    ?string $adminUsername = null
): void {
    $status = strtolower(trim($status));
    if (!in_array($status, ['verified', 'rejected'], true)) {
        return;
    }

    try {
        require_once __DIR__ . '/../api/NotificationManager.php';
        NotificationManager::getInstance()->notifyOnboardingVerificationDecision(
            $userId,
            $status,
            $adminUsername
        );
    } catch (Throwable $e) {
        error_log('onboarding decision push: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->prepare(
            'SELECT email, phone, username FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string) ($user['email'] ?? ''));
        $phone = trim((string) ($user['phone'] ?? ''));
        $username = trim((string) ($user['username'] ?? '')) ?: 'teammate';

        if ($email !== '') {
            require_once __DIR__ . '/email.php';
            sendOnboardingVerificationDecisionEmail($email, $username, $status, $adminUsername);
        }
        if ($phone !== '') {
            require_once __DIR__ . '/whatsapp.php';
            sendOnboardingVerificationDecisionWhatsApp($phone, $username, $status, $adminUsername);
        }
    } catch (Throwable $e) {
        error_log('onboarding decision mail/wa: ' . $e->getMessage());
    }
}
