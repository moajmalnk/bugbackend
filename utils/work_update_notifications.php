<?php
/**
 * Why: After checkout the employee gets a receipt on push, email and WhatsApp.
 * Callers run this after the HTTP response is flushed, so it never slows the
 * "Check out" button. Free-text notes stay out of the receipt — it only lists
 * what affects attendance and payroll (hours, breaks, OT, project time).
 */

function br_work_hours_label($hours): string
{
    $h = round((float) $hours, 2);
    return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . 'h';
}

/**
 * Build the label => value receipt shown in email and WhatsApp.
 *
 * @param array{
 *   check_in_time?: ?string,
 *   hours_today?: float|int|string,
 *   total_break_minutes?: int,
 *   requested_extra_hours?: float|int,
 *   project_updates?: ?array,
 *   total_working_days?: int|string|null,
 *   total_hours_cumulative?: float|string|null
 * } $data
 * @return array<string, string>
 */
function br_work_checkout_summary(PDO $conn, string $date, array $data): array
{
    $ts = strtotime($date . ' 12:00:00');
    $checkIn = '';
    if (!empty($data['check_in_time'])) {
        $ci = strtotime((string) $data['check_in_time']);
        if ($ci !== false) {
            $checkIn = date('h:i A', $ci);
        }
    }

    $projectsText = '';
    $updates = is_array($data['project_updates'] ?? null) ? $data['project_updates'] : [];
    if (!empty($updates)) {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($u) => is_array($u) ? (string) ($u['project_id'] ?? '') : '',
            $updates
        ))));
        $names = [];
        if (!empty($ids)) {
            try {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare("SELECT id, name FROM projects WHERE id IN ($ph)");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $names[(string) $row['id']] = (string) $row['name'];
                }
            } catch (Throwable $e) {
                // Project names are cosmetic — fall back to "Project".
            }
        }
        $lines = [];
        foreach ($updates as $u) {
            if (!is_array($u)) {
                continue;
            }
            $name = $names[(string) ($u['project_id'] ?? '')] ?? 'Project';
            $bits = [];
            if ((float) ($u['hours'] ?? 0) > 0) {
                $bits[] = br_work_hours_label($u['hours']);
            }
            if (isset($u['progress_percentage']) && (int) $u['progress_percentage'] > 0) {
                $bits[] = (int) $u['progress_percentage'] . '%';
            }
            $lines[] = '  ' . $name . (empty($bits) ? '' : ' — ' . implode(', ', $bits));
        }
        $projectsText = implode("\n", $lines);
    }

    $breakMins = (int) ($data['total_break_minutes'] ?? 0);
    $extra = (float) ($data['requested_extra_hours'] ?? 0);
    $days = $data['total_working_days'] ?? null;
    $cumulative = $data['total_hours_cumulative'] ?? null;
    $monthLabel = '';
    if ($days !== null && $days !== '' && $cumulative !== null && $cumulative !== '') {
        $monthLabel = (int) $days . ' days · ' . br_work_hours_label($cumulative);
    }

    return [
        'Date' => $ts !== false ? date('D, d M Y', $ts) : $date,
        'Check-in' => $checkIn,
        'Checked out' => date('h:i A'),
        'Hours today' => br_work_hours_label($data['hours_today'] ?? 0),
        'Breaks' => $breakMins > 0 ? $breakMins . ' min' : '',
        'Overtime request' => $extra > 0 ? br_work_hours_label($extra) . ' · pending admin approval' : '',
        'Projects' => $projectsText !== '' ? "\n" . $projectsText : '',
        'This month' => $monthLabel,
    ];
}

/**
 * Send the employee their checkout receipt on push, email and WhatsApp.
 * Each channel is isolated so one provider failing never blocks the others.
 */
function br_notify_employee_work_checkout(
    PDO $conn,
    string $userId,
    string $date,
    array $data,
    bool $isUpdate = false
): void {
    try {
        require_once __DIR__ . '/../api/NotificationManager.php';
        NotificationManager::getInstance()->notifyWorkCheckOutToEmployee(
            $userId . ':' . $date,
            $userId,
            $date,
            $data['hours_today'] ?? null,
            $isUpdate
        );
    } catch (Throwable $e) {
        error_log('work checkout employee push: ' . $e->getMessage());
    }

    try {
        $stmt = $conn->prepare('SELECT username, email, phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$user) {
            return;
        }
        $username = trim((string) ($user['username'] ?? '')) ?: 'teammate';
        $email = trim((string) ($user['email'] ?? ''));
        $phone = trim((string) ($user['phone'] ?? ''));

        try {
            $od = $conn->prepare('SELECT contact_email FROM user_onboarding_details WHERE user_id = ? LIMIT 1');
            $od->execute([$userId]);
            $contactEmail = trim((string) ($od->fetchColumn() ?: ''));
            if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                $email = $contactEmail;
            }
        } catch (Throwable $e) {
            // Older DBs may not have onboarding details — keep the account email.
        }

        $summary = br_work_checkout_summary($conn, $date, $data);

        if ($email !== '') {
            require_once __DIR__ . '/email.php';
            sendWorkCheckoutEmployeeEmail($email, $username, $summary, $isUpdate);
        }
        if ($phone !== '') {
            require_once __DIR__ . '/whatsapp.php';
            sendWorkCheckoutEmployeeWhatsApp($phone, $username, $summary, $isUpdate);
        }
    } catch (Throwable $e) {
        error_log('work checkout employee mail/wa: ' . $e->getMessage());
    }
}
