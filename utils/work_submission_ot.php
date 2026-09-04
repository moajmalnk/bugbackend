<?php

/**
 * Why: Admin soft-deletes move work_submissions to the recycle bin; live lists must hide those rows.
 */
function br_work_submission_deleted_at_supported(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $check = $conn->query("SHOW COLUMNS FROM work_submissions LIKE 'deleted_at'");
        $cached = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('br_work_submission_deleted_at_supported: ' . $e->getMessage());
    }
    return $cached;
}

/**
 * SQL AND fragment excluding soft-deleted work submissions (empty when column missing).
 */
function br_work_submission_live_and(PDO $conn, string $alias = ''): string
{
    if (!br_work_submission_deleted_at_supported($conn)) {
        return '';
    }
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return " AND {$p}deleted_at IS NULL";
}

/**
 * Revive a soft-deleted work_submissions row and clear its recycle-bin listing.
 * Why: Unique (user_id, submission_date) keeps the soft-deleted row; re-adding hours
 * must reuse that row instead of 409 / insert failure after admin recycle-bin delete.
 */
function br_work_submission_revive(PDO $conn, string|int $submissionId): void
{
    if (!br_work_submission_deleted_at_supported($conn)) {
        return;
    }
    $id = (string)$submissionId;
    if ($id === '') {
        return;
    }
    try {
        $upd = $conn->prepare(
            'UPDATE work_submissions SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL'
        );
        $upd->execute([$id]);

        $bin = $conn->prepare(
            "UPDATE recycle_bin_items
             SET restored_at = NOW()
             WHERE entity_type = 'work_submission'
               AND entity_id = ?
               AND restored_at IS NULL
               AND purged_at IS NULL"
        );
        $bin->execute([$id]);
    } catch (Throwable $e) {
        error_log('br_work_submission_revive: ' . $e->getMessage());
    }
}

/**
 * True when a work_submissions row is soft-deleted (in recycle bin).
 */
function br_work_submission_is_soft_deleted(array $row): bool
{
    return trim((string)($row['deleted_at'] ?? '')) !== '';
}

function br_work_submission_has_extra_request(array $s): bool
{
    $req = (float)($s['requested_extra_hours'] ?? 0) > 0;
    $reason = trim((string)($s['approval_reason'] ?? ''));
    return $req || $reason !== '';
}

/**
 * Overtime that counts toward period totals. Explicit extra-hour requests only count after admin approval (or change).
 * Rejected and pending requests contribute 0. Rows without an explicit request use stored overtime_hours (e.g. hours > 8).
 *
 * Why: Never fatal if OT approval columns are missing (older prod DBs). array_key_exists + ?? keep this safe
 * when Finbro SELECT omits or the DB lacks extra_hours_approval_status / requested_extra_hours / approval_reason.
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
