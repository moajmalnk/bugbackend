<?php
/**
 * Why: Shared attendance copy for email / WhatsApp so Office·WFH, late strikes,
 * geofence, and checkout notices stay consistent and professional.
 */

/**
 * @param mixed $mode
 */
function br_attendance_work_mode_label($mode): string
{
    $m = strtolower(trim((string)$mode));
    if ($m === 'wfh') {
        return 'Work from home (WFH)';
    }
    if ($m === 'office') {
        return 'Office';
    }
    return 'Not recorded';
}

/**
 * Short label for WhatsApp / subjects.
 *
 * @param mixed $mode
 */
function br_attendance_work_mode_short($mode): string
{
    $m = strtolower(trim((string)$mode));
    if ($m === 'wfh') {
        return 'WFH';
    }
    if ($m === 'office') {
        return 'Office';
    }
    return '—';
}

/**
 * @param array $meta
 */
function br_attendance_punctuality_label(array $meta): string
{
    if (!empty($meta['is_sunday'])) {
        return 'Sunday holiday · on-time (never late)';
    }
    $cutoffLabel = trim((string)($meta['checkin_cutoff_label'] ?? ''));
    if ($cutoffLabel === '') {
        $cutoffLabel = '10:00 AM IST';
    }
    if (!empty($meta['is_late'])) {
        $count = isset($meta['late_count']) ? (int)$meta['late_count'] : null;
        $limit = isset($meta['late_limit']) ? (int)$meta['late_limit'] : 3;
        if ($count !== null && $count > 0) {
            return "Late check-in ({$count}/{$limit} strikes)";
        }
        return "Late check-in (after {$cutoffLabel})";
    }
    return "On time (before {$cutoffLabel})";
}

/**
 * @param array $meta
 */
function br_attendance_location_note(array $meta): ?string
{
    $mode = strtolower(trim((string)($meta['work_mode'] ?? '')));
    if ($mode === 'wfh') {
        return 'Location not required for WFH';
    }
    if ($mode !== 'office') {
        return null;
    }
    $label = trim((string)($meta['office_label'] ?? 'office'));
    if ($label === '') {
        $label = 'office';
    }
    if (isset($meta['check_in_distance_m']) && is_numeric($meta['check_in_distance_m'])) {
        $m = (int)round((float)$meta['check_in_distance_m']);
        return "Verified near {$label} (~{$m} m)";
    }
    return "Office check-in at {$label}";
}

/**
 * @param array $meta
 * @return list<string>
 */
function br_attendance_policy_notes(array $meta): array
{
    $notes = [];
    if (!empty($meta['restriction_created']) && !empty($meta['upcoming_office_only_week']['week_start'])) {
        $ws = $meta['upcoming_office_only_week']['week_start'];
        $we = $meta['upcoming_office_only_week']['week_end'] ?? $ws;
        $notes[] = "Office-only week scheduled: {$ws} – {$we} (WFH disabled that week).";
    } elseif (!empty($meta['office_only']) && !empty($meta['office_only_week_start'])) {
        $ws = $meta['office_only_week_start'];
        $we = $meta['office_only_week_end'] ?? $ws;
        $notes[] = "Currently in Office-only week: {$ws} – {$we}.";
    } elseif (!empty($meta['upcoming_office_only_week']['week_start'])) {
        $ws = $meta['upcoming_office_only_week']['week_start'];
        $we = $meta['upcoming_office_only_week']['week_end'] ?? $ws;
        $notes[] = "Upcoming Office-only week: {$ws} – {$we}.";
    }

    $warning = trim((string)($meta['warning'] ?? ''));
    if ($warning !== '') {
        $notes[] = $warning;
    }

    return $notes;
}

/**
 * Build a compact attendance meta block for WhatsApp (lines, no trailing newline).
 *
 * @param array $meta
 */
function br_attendance_whatsapp_meta_block(array $meta): string
{
    if ($meta === []) {
        return '';
    }

    $lines = [];
    if (array_key_exists('work_mode', $meta) && $meta['work_mode'] !== null && $meta['work_mode'] !== '') {
        $lines[] = '📍 Location: *' . br_attendance_work_mode_short($meta['work_mode']) . '*';
    }
    if (array_key_exists('is_late', $meta) || !empty($meta['is_sunday'])) {
        $lines[] = '⏱ Status: *' . br_attendance_punctuality_label($meta) . '*';
    }
    $loc = br_attendance_location_note($meta);
    if ($loc !== null) {
        $lines[] = '📌 ' . $loc;
    }
    foreach (br_attendance_policy_notes($meta) as $note) {
        $lines[] = '⚠️ ' . $note;
    }

    return $lines === [] ? '' : implode("\n", $lines);
}

/**
 * HTML snippet for attendance meta (safe escaped).
 *
 * @param array $meta
 */
function br_attendance_email_meta_html(array $meta): string
{
    if ($meta === []) {
        return '';
    }

    $rows = [];
    if (array_key_exists('work_mode', $meta) && $meta['work_mode'] !== null && $meta['work_mode'] !== '') {
        $rows[] = '<strong>Work location:</strong> ' . htmlspecialchars(br_attendance_work_mode_label($meta['work_mode']));
    }
    if (array_key_exists('is_late', $meta) || !empty($meta['is_sunday'])) {
        $rows[] = '<strong>Punctuality:</strong> ' . htmlspecialchars(br_attendance_punctuality_label($meta));
    }
    $loc = br_attendance_location_note($meta);
    if ($loc !== null) {
        $rows[] = '<strong>Geo:</strong> ' . htmlspecialchars($loc);
    }
    foreach (br_attendance_policy_notes($meta) as $note) {
        $rows[] = '<strong>Policy:</strong> ' . htmlspecialchars($note);
    }

    if ($rows === []) {
        return '';
    }

    $isLate = !empty($meta['is_late']);
    $bg = $isLate ? '#fff7ed' : '#f0fdf4';
    $border = $isLate ? '#f97316' : '#10b981';
    $color = $isLate ? '#9a3412' : '#166534';

    $html = '<div style="margin-bottom: 15px; padding: 12px; background-color: ' . $bg . '; border-left: 4px solid ' . $border . '; border-radius: 4px;">';
    $html .= '<p style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: ' . $color . ';"><strong>Attendance policy</strong></p>';
    foreach ($rows as $row) {
        $html .= '<p style="margin: 0; font-size: 14px; color: ' . $color . ';">' . $row . '</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Plain-text attendance meta for email.
 *
 * @param array $meta
 */
function br_attendance_email_meta_text(array $meta): string
{
    if ($meta === []) {
        return '';
    }
    $lines = ["Attendance policy"];
    if (array_key_exists('work_mode', $meta) && $meta['work_mode'] !== null && $meta['work_mode'] !== '') {
        $lines[] = 'Work location: ' . br_attendance_work_mode_label($meta['work_mode']);
    }
    if (array_key_exists('is_late', $meta) || !empty($meta['is_sunday'])) {
        $lines[] = 'Punctuality: ' . br_attendance_punctuality_label($meta);
    }
    $loc = br_attendance_location_note($meta);
    if ($loc !== null) {
        $lines[] = 'Geo: ' . $loc;
    }
    foreach (br_attendance_policy_notes($meta) as $note) {
        $lines[] = 'Policy: ' . $note;
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Shared subject/body snippets for same-day WFH request notifications.
 */
function br_wfh_request_copy(string $username, string $date, ?string $userNote = null): array
{
    $dateFormatted = date('D, M j, Y', strtotime($date));
    $note = $userNote !== null ? trim((string)$userNote) : '';
    return [
        'subject' => "WFH request · {$username} · {$dateFormatted}",
        'headline' => 'WFH request',
        'summary' => "{$username} requested work-from-home for {$dateFormatted}.",
        'note' => $note !== '' ? $note : null,
        'cta' => 'Open Attendance exceptions in BugRicer to approve or reject.',
    ];
}

/**
 * Shared copy when a WFH request is approved or rejected.
 */
function br_wfh_request_decision_copy(string $username, string $date, string $status, ?string $adminNote = null): array
{
    $dateFormatted = date('D, M j, Y', strtotime($date));
    $approved = strtolower((string)$status) === 'approved';
    $note = $adminNote !== null ? trim((string)$adminNote) : '';
    return [
        'subject' => ($approved ? 'WFH approved' : 'WFH rejected') . " · {$dateFormatted}",
        'headline' => $approved ? 'Your WFH request was approved' : 'Your WFH request was rejected',
        'summary' => $approved
            ? 'You can check in as WFH for this day on BugUpdate.'
            : 'Office check-in still applies unless an admin grants an exception.',
        'note' => $note !== '' ? $note : null,
        'username' => $username,
        'date_label' => $dateFormatted,
        'approved' => $approved,
    ];
}
