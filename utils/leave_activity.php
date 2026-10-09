<?php
/**
 * Leave request activity trail (who did what, and when).
 */

const BR_LEAVE_EVENTS = ['requested', 'approved', 'rejected', 'cancelled', 'granted', 'updated'];

/**
 * Why: leave_requests.reviewed_by / reviewed_at are overwritten by later admin
 * edits and cancellations, and a self-cancel records nobody, so the row alone
 * cannot answer "who approved this and when". Events are append-only.
 * Created on first use so environments without migration 130 keep working.
 */
function br_ensure_leave_events_schema(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS leave_request_events (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                leave_request_id INT UNSIGNED NOT NULL,
                event VARCHAR(24) NOT NULL,
                actor_id VARCHAR(36) NULL,
                impersonated_by VARCHAR(36) NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_leave_events_request (leave_request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        return $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_leave_events_schema: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Admin id when the JWT is an impersonation session, so the trail never
 * credits the employee with an action an admin performed as them.
 */
function br_leave_impersonator_id($decoded): ?string
{
    $adminId = isset($decoded->admin_id) ? trim((string)$decoded->admin_id) : '';
    $userId = isset($decoded->user_id) ? (string)$decoded->user_id : '';
    return $adminId !== '' && $adminId !== $userId ? $adminId : null;
}

/**
 * Best-effort: a failed audit write must never undo or block the leave action.
 *
 * @param int[] $leaveIds
 */
function br_leave_log_events(PDO $conn, array $leaveIds, string $event, ?string $actorId, ?string $note = null, ?string $impersonatedBy = null): void
{
    if ($leaveIds === [] || !in_array($event, BR_LEAVE_EVENTS, true) || !br_ensure_leave_events_schema($conn)) {
        return;
    }
    try {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null;
        $stmt = $conn->prepare(
            'INSERT INTO leave_request_events (leave_request_id, event, actor_id, impersonated_by, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($leaveIds as $id) {
            $stmt->execute([(int)$id, $event, $actorId !== '' ? $actorId : null, $impersonatedBy, $note]);
        }
    } catch (Throwable $e) {
        error_log('br_leave_log_events: ' . $e->getMessage());
    }
}

/**
 * Activity for a page of leave rows in one query (no per-row lookups).
 *
 * Why: rows created before the event log existed only carry created_at and the
 * latest reviewed_* columns; those are turned into a best-known timeline and
 * flagged `derived` so the UI never presents them as a full audit.
 *
 * @param array<int, array<string, mixed>> $rows raw leave_requests rows (selectSql shape)
 * @return array<int, list<array<string, mixed>>> keyed by leave request id
 */
function br_leave_activity_for_rows(PDO $conn, array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $ids[(int)$row['id']] = true;
    }
    $ids = array_keys($ids);
    $logged = [];
    if ($ids !== [] && br_ensure_leave_events_schema($conn)) {
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare(
                "SELECT e.leave_request_id, e.event, e.actor_id, e.note, e.created_at,
                        a.username AS actor_name, i.username AS impersonator_name
                 FROM leave_request_events e
                 LEFT JOIN users a ON a.id = e.actor_id
                 LEFT JOIN users i ON i.id = e.impersonated_by
                 WHERE e.leave_request_id IN ({$ph})
                 ORDER BY e.created_at ASC, e.id ASC"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
                $logged[(int)$e['leave_request_id']][] = [
                    'event' => (string)$e['event'],
                    'actor_id' => $e['actor_id'],
                    'actor_name' => $e['actor_name'],
                    'impersonated_by_name' => $e['impersonator_name'],
                    'note' => $e['note'],
                    'at' => (string)$e['created_at'],
                    'derived' => false,
                ];
            }
        } catch (Throwable $e) {
            error_log('br_leave_activity_for_rows: ' . $e->getMessage());
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $events = $logged[$id] ?? [];
        $isOfficial = strtolower((string)($row['leave_type_code'] ?? '')) === 'corporate';
        $reviewerId = $row['reviewed_by'] ?? null;
        $reviewerName = $row['reviewer_username'] ?? null;

        $hasCreation = false;
        foreach ($events as $e) {
            if ($e['event'] === 'requested' || $e['event'] === 'granted') {
                $hasCreation = true;
                break;
            }
        }
        if (!$hasCreation) {
            $official = $isOfficial && $reviewerId !== null && (string)$reviewerId !== (string)$row['user_id'];
            array_unshift($events, [
                'event' => $official ? 'granted' : 'requested',
                'actor_id' => $official ? $reviewerId : $row['user_id'],
                'actor_name' => $official ? $reviewerName : ($row['username'] ?? null),
                'impersonated_by_name' => null,
                'note' => null,
                'at' => (string)($row['created_at'] ?? ''),
                'derived' => true,
            ]);
        }

        $status = (string)$row['status'];
        if (count($events) === 1 && $status !== 'pending' && !($isOfficial && $status === 'approved')) {
            $selfCancel = $status === 'cancelled' && $reviewerId === null;
            $events[] = [
                'event' => $status,
                'actor_id' => $selfCancel ? $row['user_id'] : $reviewerId,
                'actor_name' => $selfCancel ? ($row['username'] ?? null) : $reviewerName,
                'impersonated_by_name' => null,
                'note' => $selfCancel ? null : ($row['admin_note'] ?? null),
                'at' => (string)($row['reviewed_at'] ?? ($row['updated_at'] ?? '')),
                'derived' => true,
            ];
        }
        $out[$id] = $events;
    }
    return $out;
}
