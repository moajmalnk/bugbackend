<?php
/**
 * Shared formatters for bug_level and already_raised fields.
 */

function formatBugLevelLabel($level) {
    $map = [
        'normal' => 'Normal',
        'floap' => 'Floap',
        'utter_floap' => 'Utter Floap',
    ];
    $key = $level !== null && $level !== '' ? strtolower((string)$level) : 'normal';
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function isAlreadyRaisedValue($value) {
    return $value === 1 || $value === '1' || $value === true;
}

function formatAlreadyRaisedLabel($value) {
    return isAlreadyRaisedValue($value) ? 'Yes' : 'No';
}

function appendBugMetaToWhatsAppMessage($message, $bugLevel = null, $alreadyRaised = null) {
    $message .= "📊 *Bug Level:* " . formatBugLevelLabel($bugLevel) . "\n";
    $message .= "🔁 *Already Raised:* " . formatAlreadyRaisedLabel($alreadyRaised) . "\n";
    return $message;
}

function bugMetaEmailTableRows($bugLevel = null, $alreadyRaised = null) {
    $level = htmlspecialchars(formatBugLevelLabel($bugLevel));
    $raised = htmlspecialchars(formatAlreadyRaisedLabel($alreadyRaised));
    return '<tr><td style="padding: 8px 0; color: #64748b;">Bug Level</td><td style="padding: 8px 0; font-weight: 600;">' . $level . '</td></tr>'
        . '<tr><td style="padding: 8px 0; color: #64748b;">Already Raised</td><td style="padding: 8px 0; font-weight: 600;">' . $raised . '</td></tr>';
}

function buildBugCreatedNotificationMessage($bugTitle, $bugLevel = null, $alreadyRaised = null, $reporterName = null) {
    $who = $reporterName ? trim((string) $reporterName) : '';
    $message = $who !== ''
        ? "{$who} reported a new bug: {$bugTitle}"
        : "A new bug has been reported: {$bugTitle}";
    $message .= "\nBug Level: " . formatBugLevelLabel($bugLevel);
    $message .= "\nAlready Raised: " . formatAlreadyRaisedLabel($alreadyRaised);
    return $message;
}

function buildBugUpdatedNotificationMessage($bugTitle, $status = null, $bugLevel = null, $alreadyRaised = null, $updaterName = null) {
    $who = $updaterName ? trim((string) $updaterName) : '';
    $statusLabel = $status !== null && trim((string) $status) !== ''
        ? ucwords(str_replace('_', ' ', strtolower(trim((string) $status))))
        : null;
    $message = $who !== ''
        ? "{$who} updated bug: {$bugTitle}"
        : "Bug updated: {$bugTitle}";
    if ($statusLabel) {
        $message .= "\nStatus: {$statusLabel}";
    }
    $message .= "\nBug Level: " . formatBugLevelLabel($bugLevel);
    $message .= "\nAlready Raised: " . formatAlreadyRaisedLabel($alreadyRaised);
    return $message;
}

function formatNotificationDateTime($timestamp = null) {
    $ts = $timestamp ? strtotime((string) $timestamp) : time();
    if ($ts === false) {
        $ts = time();
    }
    return date('d M Y, g:i A', $ts) . ' IST';
}

function buildBugFixedNotificationMessage($bugTitle, $reporterName, $fixerName, $fixedAt = null, $bugLevel = null, $alreadyRaised = null) {
    $title = trim((string) $bugTitle);
    $reporter = trim((string) $reporterName) !== '' ? trim((string) $reporterName) : 'Unknown';
    $fixer = trim((string) $fixerName) !== '' ? trim((string) $fixerName) : 'Unknown';
    $when = formatNotificationDateTime($fixedAt);

    $message = "Bug '{$title}' was fixed by {$fixer} (reported by {$reporter}) on {$when}";
    $message .= "\nBug Level: " . formatBugLevelLabel($bugLevel);
    $message .= "\nAlready Raised: " . formatAlreadyRaisedLabel($alreadyRaised);
    return $message;
}
