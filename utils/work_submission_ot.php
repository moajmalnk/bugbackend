<?php

function br_work_submission_has_extra_request(array $s): bool
{
    $req = (float)($s['requested_extra_hours'] ?? 0) > 0;
    $reason = trim((string)($s['approval_reason'] ?? ''));
    return $req || $reason !== '';
}

/**
 * Overtime that counts toward period totals. Explicit extra-hour requests only count after admin approval (or change).
 * Rejected and pending requests contribute 0. Rows without an explicit request use stored overtime_hours (e.g. hours > 8).
 */
function br_effective_overtime_hours_for_stats(array $s): float
{
    $ot = (float)($s['overtime_hours'] ?? 0);
    if (!br_work_submission_has_extra_request($s)) {
        return $ot;
    }
    if (!array_key_exists('extra_hours_approval_status', $s)) {
        return $ot;
    }
    $st = strtolower(trim((string)$s['extra_hours_approval_status']));
    if ($st === 'pending') {
        return 0.0;
    }
    if ($st === 'rejected') {
        return 0.0;
    }
    if ($st === 'approved' || $st === 'changed') {
        return $ot;
    }
    if ($st === 'none') {
        // Legacy rows (before workflow): still count OT until user resubmits (then status becomes pending).
        return br_work_submission_has_extra_request($s) ? $ot : 0.0;
    }
    return 0.0;
}
