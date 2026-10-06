<?php
/**
 * Why: Pay Verify is payroll-critical — employees and admins need push, email,
 * and WhatsApp on verify / paid / hike / adjustment events (same 3-channel pattern
 * as WFH and Official Leave). Never throw to callers; log and continue.
 */

/**
 * @return array{username:string,email:string,phone:string,name:string}
 */
function br_pay_verify_user_contact(PDO $conn, string $userId): array
{
    $empty = ['username' => 'Employee', 'email' => '', 'phone' => '', 'name' => 'Employee'];
    try {
        $stmt = $conn->prepare(
            'SELECT username, email, phone FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        $username = trim((string)($row['username'] ?? '')) ?: 'Employee';
        $name = $username;
        try {
            $nameStmt = $conn->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
            $nameStmt->execute([$userId]);
            $n = trim((string)($nameStmt->fetchColumn() ?: ''));
            if ($n !== '') {
                $name = $n;
            }
        } catch (Throwable $e) {
            // name column optional
        }
        return [
            'username' => $username,
            'email' => trim((string)($row['email'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
            'name' => $name,
        ];
    } catch (Throwable $e) {
        error_log('br_pay_verify_user_contact: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * @return list<array{email:string,phone:string}>
 */
function br_pay_verify_admin_contacts(PDO $conn, ?string $excludeUserId = null): array
{
    try {
        $sql = "SELECT email, phone, id FROM users
                WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)
                  AND (email IS NOT NULL OR phone IS NOT NULL)";
        $stmt = $conn->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out = [];
        foreach ($rows as $r) {
            if ($excludeUserId !== null && (string)($r['id'] ?? '') === (string)$excludeUserId) {
                continue;
            }
            $out[] = [
                'email' => trim((string)($r['email'] ?? '')),
                'phone' => trim((string)($r['phone'] ?? '')),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('br_pay_verify_admin_contacts: ' . $e->getMessage());
        return [];
    }
}

function br_pay_verify_month_label(string $yearMonth): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
        return $yearMonth;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth, new DateTimeZone('Asia/Kolkata'));
    return $dt ? $dt->format('F Y') : $yearMonth;
}

function br_pay_verify_week_label(string $weekStart): string
{
    try {
        $start = new DateTimeImmutable($weekStart, new DateTimeZone('Asia/Kolkata'));
        $end = $start->modify('+6 days');
        return $start->format('d M') . ' – ' . $end->format('d M Y');
    } catch (Throwable $e) {
        return $weekStart;
    }
}

function br_pay_verify_frontend_url(string $path = '/pay-verify'): string
{
    $base = 'https://bugs.bugricer.com';
    if (function_exists('getFrontendBaseUrl')) {
        $base = rtrim((string)getFrontendBaseUrl(), '/');
    }
    return $base . $path;
}

function br_pay_verify_format_inr($amount): string
{
    $n = (float)$amount;
    $formatted = number_format(abs($n), 2);
    return ($n < 0 ? '-₹' : '₹') . $formatted;
}

/**
 * @param array{
 *   headline:string,
 *   subject:string,
 *   summary:string,
 *   detail?:string,
 *   note?:string|null,
 *   color?:string,
 *   cta_label?:string,
 *   cta_path?:string
 * } $copy
 */
function br_pay_verify_send_email(string $toEmail, string $username, array $copy): bool
{
    if ($toEmail === '') {
        return false;
    }
    require_once __DIR__ . '/email.php';
    $color = $copy['color'] ?? '#059669';
    $headline = htmlspecialchars((string)$copy['headline'], ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeSummary = htmlspecialchars((string)$copy['summary'], ENT_QUOTES, 'UTF-8');
    $detailHtml = !empty($copy['detail'])
        ? '<p style="margin:8px 0 0;">' . htmlspecialchars((string)$copy['detail'], ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $noteHtml = !empty($copy['note'])
        ? '<p style="margin:12px 0 0;"><strong>Note:</strong> '
            . htmlspecialchars((string)$copy['note'], ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $ctaPath = (string)($copy['cta_path'] ?? '/pay-verify');
    $ctaUrl = htmlspecialchars(br_pay_verify_frontend_url($ctaPath), ENT_QUOTES, 'UTF-8');
    $ctaLabel = htmlspecialchars((string)($copy['cta_label'] ?? 'Open Pay Verify'), ENT_QUOTES, 'UTF-8');

    $html = "
    <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 560px; margin: 0 auto;\">
      <div style=\"background: {$color}; padding: 20px; border-radius: 12px 12px 0 0;\">
        <h2 style=\"margin:0;color:#fff;\">{$headline}</h2>
      </div>
      <div style=\"border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 12px 12px;\">
        <p>Hi {$safeName},</p>
        <p>{$safeSummary}</p>
        {$detailHtml}
        {$noteHtml}
        <p style=\"margin-top:16px;\">
          <a href=\"{$ctaUrl}\" style=\"display:inline-block;background:{$color};color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:600;\">
            {$ctaLabel}
          </a>
        </p>
        <p style=\"margin-top:12px;color:#64748b;font-size:12px;\">BugRicer · Pay Verify · Asia/Kolkata</p>
      </div>
    </div>";

    $text = (string)$copy['headline'] . " — BugRicer\n\n"
        . (string)$copy['summary'] . "\n"
        . (!empty($copy['detail']) ? (string)$copy['detail'] . "\n" : '')
        . (!empty($copy['note']) ? 'Note: ' . (string)$copy['note'] . "\n" : '')
        . "\nOpen: " . br_pay_verify_frontend_url($ctaPath) . "\n";

    return (bool)sendEmail($toEmail, (string)$copy['subject'], $html, $text);
}

/**
 * @param array{headline:string,summary:string,detail?:string,note?:string|null} $copy
 */
function br_pay_verify_send_whatsapp(string $phone, string $username, array $copy): bool
{
    if ($phone === '') {
        return false;
    }
    require_once __DIR__ . '/whatsapp.php';
    $note = !empty($copy['note']) ? "\nNote: " . trim((string)$copy['note']) : '';
    $detail = !empty($copy['detail']) ? "\n" . trim((string)$copy['detail']) : '';
    $url = br_pay_verify_frontend_url('/pay-verify');
    $message = (string)$copy['headline'] . "\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Hi {$username},\n\n"
        . (string)$copy['summary']
        . $detail
        . $note
        . "\n\n{$url}\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n"
        . "🐞 BugRicer · Pay Verify · Asia/Kolkata";
    return (bool)sendWhatsAppMessage($phone, $message);
}

/**
 * @param array{headline:string,subject:string,summary:string,detail?:string,note?:string|null,color?:string,cta_label?:string,cta_path?:string} $copy
 */
function br_pay_verify_notify_employee(PDO $conn, string $userId, array $copy, callable $pushFn): void
{
    try {
        $pushFn();
    } catch (Throwable $e) {
        error_log('pay_verify push employee: ' . $e->getMessage());
    }

    try {
        $contact = br_pay_verify_user_contact($conn, $userId);
        $name = $contact['name'] !== '' ? $contact['name'] : $contact['username'];
        if ($contact['email'] !== '') {
            br_pay_verify_send_email($contact['email'], $name, $copy);
        }
        if ($contact['phone'] !== '') {
            br_pay_verify_send_whatsapp($contact['phone'], $name, $copy);
        }
    } catch (Throwable $e) {
        error_log('pay_verify mail/wa employee: ' . $e->getMessage());
    }
}

/**
 * @param array{headline:string,subject:string,summary:string,detail?:string,note?:string|null,color?:string} $copy
 */
function br_pay_verify_notify_admins(PDO $conn, string $actorUserId, array $copy, callable $pushFn): void
{
    try {
        $pushFn();
    } catch (Throwable $e) {
        error_log('pay_verify push admins: ' . $e->getMessage());
    }

    try {
        $admins = br_pay_verify_admin_contacts($conn, $actorUserId);
        foreach ($admins as $admin) {
            if ($admin['email'] !== '') {
                br_pay_verify_send_email($admin['email'], 'Admin', $copy);
            }
            if ($admin['phone'] !== '') {
                br_pay_verify_send_whatsapp($admin['phone'], 'Admin', $copy);
            }
        }
    } catch (Throwable $e) {
        error_log('pay_verify mail/wa admins: ' . $e->getMessage());
    }
}

/** Employee (or admin-on-behalf) completed / flagged a week. */
function br_notify_pay_verify_employee_week(
    PDO $conn,
    string $subjectUserId,
    string $actorUserId,
    string $weekStart,
    string $status,
    ?string $note = null
): void {
    $contact = br_pay_verify_user_contact($conn, $subjectUserId);
    $weekLabel = br_pay_verify_week_label($weekStart);
    $needsFix = $status === 'correction_needed';
    $onBehalf = $actorUserId !== $subjectUserId;

    if ($onBehalf) {
        // Admin verified on behalf of employee → tell the employee.
        $copy = [
            'headline' => $needsFix ? 'Week needs correction' : 'Week verified by admin',
            'subject' => ($needsFix ? 'Week correction' : 'Week verified') . " · {$weekLabel}",
            'summary' => $needsFix
                ? "An admin flagged your hours for {$weekLabel} for correction."
                : "An admin marked your hours for {$weekLabel} as verified.",
            'detail' => 'Open Pay Verify to review the week.',
            'note' => $note,
            'color' => $needsFix ? '#f59e0b' : '#059669',
            'cta_label' => 'Open Pay Verify',
        ];
        br_pay_verify_notify_employee(
            $conn,
            $subjectUserId,
            $copy,
            static function () use ($subjectUserId, $weekStart, $status, $note) {
                require_once __DIR__ . '/../api/NotificationManager.php';
                NotificationManager::getInstance()->notifyPayVerifyWeekEmployee(
                    $subjectUserId,
                    $weekStart,
                    $status,
                    $note,
                    true
                );
            }
        );
        return;
    }

    $copy = [
        'headline' => $needsFix ? '⚠️ Pay Verify · week correction' : '✅ Pay Verify · week ready',
        'subject' => ($needsFix ? 'Week correction' : 'Week verified')
            . " · {$contact['username']} · {$weekLabel}",
        'summary' => $needsFix
            ? "{$contact['name']} flagged week {$weekLabel} for correction."
            : "{$contact['name']} verified week {$weekLabel}.",
        'detail' => 'Review in Pay Verify before salary lock.',
        'note' => $note,
        'color' => $needsFix ? '#f59e0b' : '#0ea5e9',
        'cta_label' => 'Open Pay Verify',
    ];
    br_pay_verify_notify_admins(
        $conn,
        $actorUserId,
        $copy,
        static function () use ($subjectUserId, $weekStart, $status, $note, $contact) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifyWeekToAdmins(
                $subjectUserId,
                $contact['name'],
                $weekStart,
                $status,
                $note
            );
        }
    );
}

/** Admin approved / requested correction on a week. */
function br_notify_pay_verify_admin_week(
    PDO $conn,
    string $userId,
    string $weekStart,
    string $status,
    ?string $note = null
): void {
    $weekLabel = br_pay_verify_week_label($weekStart);
    $ok = $status === 'approved';
    $copy = [
        'headline' => $ok ? 'Week approved' : 'Week needs correction',
        'subject' => ($ok ? 'Week approved' : 'Week correction requested') . " · {$weekLabel}",
        'summary' => $ok
            ? "Your week {$weekLabel} was approved for payroll."
            : "Please correct your hours for {$weekLabel} and re-verify.",
        'detail' => 'Open Pay Verify to continue.',
        'note' => $note,
        'color' => $ok ? '#059669' : '#f59e0b',
    ];
    br_pay_verify_notify_employee(
        $conn,
        $userId,
        $copy,
        static function () use ($userId, $weekStart, $status, $note) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifyWeekEmployee(
                $userId,
                $weekStart,
                $status,
                $note,
                false
            );
        }
    );
}

/** Employee (or admin-on-behalf) completed / flagged a month. */
function br_notify_pay_verify_employee_month(
    PDO $conn,
    string $subjectUserId,
    string $actorUserId,
    string $yearMonth,
    string $status,
    ?string $note = null,
    $netEstimate = null
): void {
    $contact = br_pay_verify_user_contact($conn, $subjectUserId);
    $monthLabel = br_pay_verify_month_label($yearMonth);
    $needsFix = $status === 'correction_needed';
    $onBehalf = $actorUserId !== $subjectUserId;
    // Why: Net / salary figures stay on the Pay Verify screen — never in mail / WA / push.
    unset($netEstimate);

    if ($onBehalf) {
        $copy = [
            'headline' => $needsFix ? 'Month needs correction' : 'Month verified by admin',
            'subject' => ($needsFix ? 'Month correction' : 'Month verified') . " · {$monthLabel}",
            'summary' => $needsFix
                ? "An admin flagged {$monthLabel} for correction before payment."
                : "An admin verified your hours for {$monthLabel}. Payment can proceed once marked paid.",
            'detail' => 'Open Pay Verify to review.',
            'note' => $note,
            'color' => $needsFix ? '#f59e0b' : '#059669',
        ];
        br_pay_verify_notify_employee(
            $conn,
            $subjectUserId,
            $copy,
            static function () use ($subjectUserId, $yearMonth, $status, $note) {
                require_once __DIR__ . '/../api/NotificationManager.php';
                NotificationManager::getInstance()->notifyPayVerifyMonthEmployee(
                    $subjectUserId,
                    $yearMonth,
                    $status,
                    $note,
                    true
                );
            }
        );
        return;
    }

    $copy = [
        'headline' => $needsFix ? '⚠️ Pay Verify · month correction' : '✅ Pay Verify · month ready',
        'subject' => ($needsFix ? 'Month correction' : 'Month verified')
            . " · {$contact['username']} · {$monthLabel}",
        'summary' => $needsFix
            ? "{$contact['name']} flagged {$monthLabel} for correction."
            : "{$contact['name']} verified {$monthLabel} — ready to mark as paid.",
        'detail' => 'Open Pay Verify to review.',
        'note' => $note,
        'color' => $needsFix ? '#f59e0b' : '#0ea5e9',
    ];
    br_pay_verify_notify_admins(
        $conn,
        $actorUserId,
        $copy,
        static function () use ($subjectUserId, $yearMonth, $status, $note, $contact) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifyMonthToAdmins(
                $subjectUserId,
                $contact['name'],
                $yearMonth,
                $status,
                $note
            );
        }
    );
}

/** Admin lock / unlock / correction on month. */
function br_notify_pay_verify_admin_month(
    PDO $conn,
    string $userId,
    string $yearMonth,
    string $action,
    ?string $note = null,
    $netEstimate = null
): void {
    $monthLabel = br_pay_verify_month_label($yearMonth);
    // Why: Amounts stay on-platform; external channels only describe the action.
    unset($netEstimate);

    if ($action === 'lock') {
        $copy = [
            'headline' => 'Salary marked paid',
            'subject' => "Paid · {$monthLabel}",
            'summary' => "Your salary for {$monthLabel} has been marked as paid.",
            'detail' => 'Open Pay Verify to view the full breakdown.',
            'note' => $note,
            'color' => '#059669',
        ];
    } elseif ($action === 'unlock') {
        $copy = [
            'headline' => 'Salary unmarked',
            'subject' => "Unpaid · {$monthLabel}",
            'summary' => "Your {$monthLabel} payment was unmarked. You can update hours if needed.",
            'detail' => 'Open Pay Verify to continue.',
            'note' => $note,
            'color' => '#64748b',
        ];
    } else {
        $copy = [
            'headline' => 'Month needs correction',
            'subject' => "Correction · {$monthLabel}",
            'summary' => "Please review and re-verify your hours for {$monthLabel}.",
            'detail' => 'Open Pay Verify to continue.',
            'note' => $note,
            'color' => '#f59e0b',
        ];
    }

    br_pay_verify_notify_employee(
        $conn,
        $userId,
        $copy,
        static function () use ($userId, $yearMonth, $action, $note) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifyMonthAdminAction(
                $userId,
                $yearMonth,
                $action,
                $note
            );
        }
    );
}

/** Salary hike saved / updated. */
function br_notify_pay_verify_salary_hike(
    PDO $conn,
    string $userId,
    float $newRate,
    string $effectiveFrom,
    ?float $previousRate = null,
    ?float $hikePct = null,
    ?string $note = null
): void {
    $fromLabel = '';
    try {
        $fromLabel = (new DateTimeImmutable($effectiveFrom, new DateTimeZone('Asia/Kolkata')))
            ->format('d M Y');
    } catch (Throwable $e) {
        $fromLabel = $effectiveFrom;
    }
    $copy = [
        'headline' => 'Salary rate updated',
        'subject' => 'Salary hike · effective ' . $fromLabel,
        'summary' => "Your hourly rate was updated, effective {$fromLabel}.",
        'detail' => 'Open Pay Verify to see the new rate and timeline.',
        'note' => $note,
        'color' => '#059669',
    ];
    // Why: Keep rate numbers off mail / WhatsApp; platform shows the full hike.
    br_pay_verify_notify_employee(
        $conn,
        $userId,
        $copy,
        static function () use ($userId, $newRate, $effectiveFrom, $previousRate, $hikePct, $note) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifySalaryHike(
                $userId,
                $newRate,
                $effectiveFrom,
                $previousRate,
                $hikePct,
                $note
            );
        }
    );
}

/** Scheduled (or same-day) hike removed by admin. */
function br_notify_pay_verify_salary_hike_removed(
    PDO $conn,
    string $userId,
    float $removedRate,
    string $effectiveFrom
): void {
    $fromLabel = $effectiveFrom;
    try {
        $fromLabel = (new DateTimeImmutable($effectiveFrom, new DateTimeZone('Asia/Kolkata')))
            ->format('d M Y');
    } catch (Throwable $e) {
        // keep
    }
    $copy = [
        'headline' => 'Scheduled hike removed',
        'subject' => 'Salary hike removed · ' . $fromLabel,
        'summary' => "A scheduled salary hike effective {$fromLabel} was removed.",
        'detail' => 'Open Pay Verify to review your active rate.',
        'note' => null,
        'color' => '#64748b',
    ];
    // Why: Do not include ₹ amounts in external channels.
    unset($removedRate);
    br_pay_verify_notify_employee(
        $conn,
        $userId,
        $copy,
        static function () use ($userId, $removedRate, $effectiveFrom) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifySalaryHikeRemoved(
                $userId,
                $removedRate,
                $effectiveFrom
            );
        }
    );
}

/** Month adjustment added or removed. */
function br_notify_pay_verify_adjustment(
    PDO $conn,
    string $userId,
    string $yearMonth,
    string $type,
    $amount,
    string $reason,
    bool $removed = false
): void {
    $monthLabel = br_pay_verify_month_label($yearMonth);
    $typeLabel = match ($type) {
        'project_incentive' => 'Project incentive',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
    // Why: Amounts stay on Pay Verify UI — mail / WA / push only describe the adjustment.
    unset($amount);
    $copy = [
        'headline' => $removed ? 'Adjustment removed' : 'Pay adjustment added',
        'subject' => ($removed ? 'Adjustment removed' : 'Adjustment') . " · {$typeLabel} · {$monthLabel}",
        'summary' => $removed
            ? "A {$typeLabel} adjustment was removed from {$monthLabel}."
            : "A {$typeLabel} was added to {$monthLabel}.",
        'detail' => $reason !== '' ? "Reason: {$reason}" : 'Open Pay Verify to see the amount.',
        'note' => null,
        'color' => $removed ? '#64748b' : '#f59e0b',
    ];
    br_pay_verify_notify_employee(
        $conn,
        $userId,
        $copy,
        static function () use ($userId, $yearMonth, $type, $amount, $reason, $removed) {
            require_once __DIR__ . '/../api/NotificationManager.php';
            NotificationManager::getInstance()->notifyPayVerifyAdjustment(
                $userId,
                $yearMonth,
                $type,
                $amount,
                $reason,
                $removed
            );
        }
    );
}
