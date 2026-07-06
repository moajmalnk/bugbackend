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
    if (isAlreadyRaisedValue($alreadyRaised)) {
        $message .= "🔁 *Already Raised:* Yes\n";
    }
    return $message;
}

function bugMetaEmailTableRows($bugLevel = null, $alreadyRaised = null) {
    $level = htmlspecialchars(formatBugLevelLabel($bugLevel));
    $raised = htmlspecialchars(formatAlreadyRaisedLabel($alreadyRaised));
    return '<tr><td style="padding: 8px 0; color: #64748b;">Bug Level</td><td>' . $level . '</td></tr>'
        . '<tr><td style="padding: 8px 0; color: #64748b;">Already Raised</td><td>' . $raised . '</td></tr>';
}

function buildBugCreatedNotificationMessage($bugTitle, $bugLevel = null, $alreadyRaised = null) {
    $message = "A new bug has been reported: {$bugTitle}";
    $level = formatBugLevelLabel($bugLevel);
    $message .= " (Level: {$level})";
    if (isAlreadyRaisedValue($alreadyRaised)) {
        $message .= " — Previously raised";
    }
    return $message;
}
