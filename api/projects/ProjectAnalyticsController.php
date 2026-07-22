<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectAnalyticsController extends BaseAPI
{
    public function getAnalytics($projectId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $projectId = trim((string) $projectId);

            if ($projectId === '' || !$this->utils->isValidUUID($projectId)) {
                $this->sendJsonResponse(400, 'Invalid project ID format');
                return;
            }

            if (!$this->userCanViewProject($decoded, $projectId)) {
                $this->sendJsonResponse(403, 'Access denied to this project');
                return;
            }

            $projCols = [];
            $projColRes = $this->conn->query('SHOW COLUMNS FROM projects');
            if ($projColRes) {
                while ($r = $projColRes->fetch(PDO::FETCH_ASSOC)) {
                    $projCols[] = $r['Field'];
                }
            }
            $hasProjectStatus = in_array('status', $projCols, true);
            $hasIsActive = in_array('is_active', $projCols, true);

            $projectSelect = 'p.id, p.name, p.created_at';
            if ($hasProjectStatus) {
                $projectSelect .= ', p.status';
            }
            if ($hasIsActive) {
                $projectSelect .= ', p.is_active';
            }

            $projectStmt = $this->conn->prepare("SELECT $projectSelect FROM projects p WHERE p.id = ? LIMIT 1");
            $projectStmt->execute([$projectId]);
            $projectRow = $projectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$projectRow) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $formatDuration = function ($seconds) {
                $seconds = max(0, (int) $seconds);
                if ($seconds < 60) {
                    return $seconds . 's';
                }
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);
                $mins = intdiv($seconds % 3600, 60);
                $parts = [];
                if ($days > 0) {
                    $parts[] = $days . 'd';
                }
                if ($hours > 0) {
                    $parts[] = $hours . 'h';
                }
                if ($mins > 0 || empty($parts)) {
                    $parts[] = $mins . 'm';
                }
                return implode(' ', $parts);
            };

            $parseTs = function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                $ts = strtotime((string) $value);
                return $ts === false ? null : $ts;
            };

            $buildTimeline = function (array $events, $currentStatus, $nowTs) use ($parseTs, $formatDuration) {
                usort($events, function ($a, $b) {
                    $ta = $a['at_ts'] ?? 0;
                    $tb = $b['at_ts'] ?? 0;
                    if ($ta === $tb) {
                        return ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0);
                    }
                    return $ta <=> $tb;
                });

                $timeline = [];
                $count = count($events);
                for ($i = 0; $i < $count; $i++) {
                    $enteredAt = $events[$i]['at'] ?? null;
                    $enteredTs = $events[$i]['at_ts'] ?? null;
                    $status = $events[$i]['status'] ?? null;
                    if (!$status || $enteredTs === null) {
                        continue;
                    }

                    $exitedAt = null;
                    $exitedTs = null;
                    $isCurrent = ($i === $count - 1);
                    if (!$isCurrent) {
                        $exitedAt = $events[$i + 1]['at'] ?? null;
                        $exitedTs = $events[$i + 1]['at_ts'] ?? null;
                    } else {
                        $exitedTs = $nowTs;
                    }

                    $duration = ($exitedTs !== null && $enteredTs !== null)
                        ? max(0, $exitedTs - $enteredTs)
                        : null;

                    $timeline[] = [
                        'status' => $status,
                        'from_status' => $events[$i]['from_status'] ?? null,
                        'entered_at' => $enteredAt,
                        'exited_at' => $isCurrent ? null : $exitedAt,
                        'duration_seconds' => $duration,
                        'duration_label' => $duration !== null ? $formatDuration($duration) : null,
                        'is_current' => $isCurrent,
                        'source' => $events[$i]['source'] ?? 'activity',
                    ];
                }

                if (empty($timeline) && $currentStatus) {
                    return [[
                        'status' => $currentStatus,
                        'from_status' => null,
                        'entered_at' => null,
                        'exited_at' => null,
                        'duration_seconds' => null,
                        'duration_label' => null,
                        'is_current' => true,
                        'source' => 'current',
                    ]];
                }

                return $timeline;
            };

            $extractStatusEventsFromActivities = function (array $activities, $entityId, $fallbackStatus, $raisedAt, $updatedAt) use ($parseTs) {
                $events = [];
                $seq = 0;
                $raisedTs = $parseTs($raisedAt);
                if ($raisedTs !== null) {
                    $events[] = [
                        'status' => 'pending',
                        'from_status' => null,
                        'at' => $raisedAt,
                        'at_ts' => $raisedTs,
                        'seq' => $seq++,
                        'source' => 'raised',
                    ];
                }

                $lastStatus = 'pending';
                foreach ($activities as $activity) {
                    $relatedId = (string) ($activity['related_id'] ?? '');
                    if ($relatedId !== (string) $entityId) {
                        continue;
                    }

                    $meta = [];
                    if (!empty($activity['metadata'])) {
                        $decodedMeta = json_decode($activity['metadata'], true);
                        if (is_array($decodedMeta)) {
                            $meta = $decodedMeta;
                        }
                    }

                    $type = (string) ($activity['activity_type'] ?? '');
                    $at = $activity['created_at'] ?? null;
                    $atTs = $parseTs($at);
                    if ($atTs === null) {
                        continue;
                    }

                    $toStatus = null;
                    $fromStatus = null;

                    if ($type === 'bug_status_changed' || !empty($meta['from']) || !empty($meta['to'])) {
                        $fromStatus = isset($meta['from']) ? (string) $meta['from'] : null;
                        $toStatus = isset($meta['to']) ? (string) $meta['to'] : null;
                    }

                    if (!$toStatus && isset($meta['status']) && is_string($meta['status'])) {
                        $toStatus = (string) $meta['status'];
                        $fromStatus = $lastStatus;
                    }

                    if ($type === 'bug_fixed' || $type === 'fix_created') {
                        $toStatus = 'fixed';
                        $fromStatus = $fromStatus ?: $lastStatus;
                    }

                    if ($type === 'bug_created' || $type === 'bug_reported' || $type === 'update_created') {
                        $toStatus = $toStatus ?: 'pending';
                        $fromStatus = null;
                    }

                    if (!$toStatus || $toStatus === $lastStatus) {
                        continue;
                    }

                    $events[] = [
                        'status' => $toStatus,
                        'from_status' => $fromStatus ?: $lastStatus,
                        'at' => $at,
                        'at_ts' => $atTs,
                        'seq' => $seq++,
                        'source' => $type ?: 'activity',
                    ];
                    $lastStatus = $toStatus;
                }

                $current = $fallbackStatus ?: $lastStatus;
                if ($current && $current !== $lastStatus) {
                    $updatedTs = $parseTs($updatedAt) ?: $raisedTs;
                    if ($updatedTs !== null) {
                        $events[] = [
                            'status' => $current,
                            'from_status' => $lastStatus,
                            'at' => $updatedAt ?: $raisedAt,
                            'at_ts' => $updatedTs,
                            'seq' => $seq++,
                            'source' => 'inferred',
                        ];
                    }
                }

                $deduped = [];
                foreach ($events as $event) {
                    $prev = end($deduped);
                    if ($prev && ($prev['status'] ?? null) === ($event['status'] ?? null)) {
                        continue;
                    }
                    $deduped[] = $event;
                }

                return $deduped;
            };

            $computeDurations = function (array $timeline, $raisedAt, $currentStatus) use ($parseTs) {
                $nowTs = time();
                $raisedTs = $parseTs($raisedAt);
                $fixedAt = null;
                $fixedTs = null;
                $inProgressTs = null;

                foreach ($timeline as $step) {
                    $status = strtolower((string) ($step['status'] ?? ''));
                    $enteredTs = $parseTs($step['entered_at'] ?? null);
                    if ($status === 'in_progress' && $inProgressTs === null) {
                        $inProgressTs = $enteredTs;
                    }
                    if (in_array($status, ['fixed', 'approved', 'completed', 'declined', 'rejected'], true)) {
                        $fixedAt = $step['entered_at'] ?? null;
                        $fixedTs = $enteredTs;
                    }
                }

                $isClosed = in_array(strtolower((string) $currentStatus), ['fixed', 'approved', 'completed', 'declined', 'rejected'], true);
                $endTs = $fixedTs ?: ($isClosed ? $parseTs($timeline[count($timeline) - 1]['entered_at'] ?? null) : $nowTs);

                $riseSeconds = ($raisedTs !== null && $endTs !== null) ? max(0, $endTs - $raisedTs) : null;
                $fixSeconds = null;
                if ($fixedTs !== null) {
                    $startFix = $inProgressTs ?: $raisedTs;
                    if ($startFix !== null) {
                        $fixSeconds = max(0, $fixedTs - $startFix);
                    }
                }

                return [
                    'raised_at' => $raisedAt,
                    'resolved_at' => $fixedAt,
                    'rise_duration_seconds' => $riseSeconds,
                    'fix_duration_seconds' => $fixSeconds,
                    'is_open' => !$isClosed,
                ];
            };

            $bugCols = [];
            $bugColRes = $this->conn->query('SHOW COLUMNS FROM bugs');
            if ($bugColRes) {
                while ($r = $bugColRes->fetch(PDO::FETCH_ASSOC)) {
                    $bugCols[] = $r['Field'];
                }
            }
            $hasFixedBy = in_array('fixed_by', $bugCols, true);

            $bugSelect = 'b.id, b.title, b.status, b.priority, b.created_at, b.updated_at, b.project_id, b.reported_by, b.updated_by';
            if ($hasFixedBy) {
                $bugSelect .= ', b.fixed_by';
            }

            $bugsStmt = $this->conn->prepare(
                "SELECT $bugSelect,
                        reporter.username AS reported_by_name,
                        fixer.username AS fixed_by_name
                 FROM bugs b
                 LEFT JOIN users reporter ON reporter.id = b.reported_by
                 LEFT JOIN users fixer ON fixer.id = " . ($hasFixedBy ? 'b.fixed_by' : 'b.updated_by') . "
                 WHERE b.project_id = ?
                 ORDER BY b.created_at DESC
                 LIMIT 1000"
            );
            $bugsStmt->execute([$projectId]);
            $bugRows = $bugsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $updCols = [];
            $updColRes = $this->conn->query('SHOW COLUMNS FROM updates');
            if ($updColRes) {
                while ($r = $updColRes->fetch(PDO::FETCH_ASSOC)) {
                    $updCols[] = $r['Field'];
                }
            }
            $hasApprovedAt = in_array('approved_at', $updCols, true);
            $hasDeclinedAt = in_array('declined_at', $updCols, true);
            $hasCompletedAt = in_array('completed_at', $updCols, true);

            $updSelect = 'u.id, u.title, u.type, u.status, u.created_at, u.updated_at, u.project_id, u.created_by';
            if ($hasApprovedAt) {
                $updSelect .= ', u.approved_at';
            }
            if ($hasDeclinedAt) {
                $updSelect .= ', u.declined_at';
            }
            if ($hasCompletedAt) {
                $updSelect .= ', u.completed_at';
            }

            $updStmt = $this->conn->prepare(
                "SELECT $updSelect, creator.username AS created_by_name
                 FROM updates u
                 LEFT JOIN users creator ON creator.id = u.created_by
                 WHERE u.project_id = ?
                 ORDER BY u.created_at DESC
                 LIMIT 500"
            );
            $updStmt->execute([$projectId]);
            $updateRows = $updStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $entityIds = [];
            foreach ($bugRows as $b) {
                $entityIds[(string) $b['id']] = true;
            }
            foreach ($updateRows as $u) {
                $entityIds[(string) $u['id']] = true;
            }
            $entityIdList = array_keys($entityIds);

            $activitiesByEntity = [];
            if (!empty($entityIdList)) {
                foreach (array_chunk($entityIdList, 100) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $actStmt = $this->conn->prepare(
                        "SELECT related_id, activity_type, metadata, created_at, user_id
                         FROM project_activities
                         WHERE related_id IN ($placeholders)
                           AND activity_type IN (
                             'bug_created','bug_reported','bug_updated','bug_fixed','bug_status_changed',
                             'update_created','update_updated','fix_created','fix_updated'
                           )
                         ORDER BY created_at ASC
                         LIMIT 5000"
                    );
                    $actStmt->execute($chunk);
                    foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $act) {
                        $rid = (string) ($act['related_id'] ?? '');
                        if ($rid === '') {
                            continue;
                        }
                        if (!isset($activitiesByEntity[$rid])) {
                            $activitiesByEntity[$rid] = [];
                        }
                        $activitiesByEntity[$rid][] = $act;
                    }
                }
            }

            $nowTs = time();
            $bugs = [];
            $fixes = [];
            $updates = [];
            $openCount = 0;
            $riseSum = 0;
            $riseCount = 0;
            $fixSum = 0;
            $fixCount = 0;

            foreach ($bugRows as $bug) {
                $bugId = (string) $bug['id'];
                $status = (string) ($bug['status'] ?? 'pending');

                $events = $extractStatusEventsFromActivities(
                    $activitiesByEntity[$bugId] ?? [],
                    $bugId,
                    $status,
                    $bug['created_at'] ?? null,
                    $bug['updated_at'] ?? null
                );
                $timeline = $buildTimeline($events, $status, $nowTs);
                $durations = $computeDurations($timeline, $bug['created_at'] ?? null, $status);

                $item = [
                    'id' => $bugId,
                    'title' => $bug['title'],
                    'status' => $status,
                    'priority' => $bug['priority'] ?? null,
                    'kind' => 'bug',
                    'raised_at' => $durations['raised_at'],
                    'resolved_at' => $durations['resolved_at'],
                    'rise_duration_seconds' => $durations['rise_duration_seconds'],
                    'rise_duration_label' => $durations['rise_duration_seconds'] !== null
                        ? $formatDuration($durations['rise_duration_seconds']) : null,
                    'fix_duration_seconds' => $durations['fix_duration_seconds'],
                    'fix_duration_label' => $durations['fix_duration_seconds'] !== null
                        ? $formatDuration($durations['fix_duration_seconds']) : null,
                    'is_open' => $durations['is_open'],
                    'reported_by_name' => $bug['reported_by_name'] ?? null,
                    'fixed_by_name' => $status === 'fixed' ? ($bug['fixed_by_name'] ?? null) : null,
                    'status_timeline' => $timeline,
                ];

                $bugs[] = $item;
                if ($durations['is_open']) {
                    $openCount++;
                }
                if ($durations['rise_duration_seconds'] !== null) {
                    $riseSum += $durations['rise_duration_seconds'];
                    $riseCount++;
                }

                if ($status === 'fixed') {
                    $fixes[] = $item;
                    if ($durations['fix_duration_seconds'] !== null) {
                        $fixSum += $durations['fix_duration_seconds'];
                        $fixCount++;
                    }
                }
            }

            foreach ($updateRows as $update) {
                $updId = (string) $update['id'];
                $status = (string) ($update['status'] ?? 'pending');
                $events = $extractStatusEventsFromActivities(
                    $activitiesByEntity[$updId] ?? [],
                    $updId,
                    $status,
                    $update['created_at'] ?? null,
                    $update['updated_at'] ?? null
                );

                $seq = count($events);
                if ($hasApprovedAt && !empty($update['approved_at'])) {
                    $events[] = [
                        'status' => 'approved',
                        'from_status' => 'pending',
                        'at' => $update['approved_at'],
                        'at_ts' => $parseTs($update['approved_at']),
                        'seq' => $seq++,
                        'source' => 'approved_at',
                    ];
                }
                if ($hasDeclinedAt && !empty($update['declined_at'])) {
                    $events[] = [
                        'status' => 'declined',
                        'from_status' => 'pending',
                        'at' => $update['declined_at'],
                        'at_ts' => $parseTs($update['declined_at']),
                        'seq' => $seq++,
                        'source' => 'declined_at',
                    ];
                }
                if ($hasCompletedAt && !empty($update['completed_at'])) {
                    $events[] = [
                        'status' => 'completed',
                        'from_status' => $status === 'approved' ? 'approved' : 'pending',
                        'at' => $update['completed_at'],
                        'at_ts' => $parseTs($update['completed_at']),
                        'seq' => $seq++,
                        'source' => 'completed_at',
                    ];
                }

                usort($events, function ($a, $b) {
                    return ($a['at_ts'] ?? 0) <=> ($b['at_ts'] ?? 0);
                });
                $deduped = [];
                foreach ($events as $event) {
                    $prev = end($deduped);
                    if ($prev && ($prev['status'] ?? null) === ($event['status'] ?? null)) {
                        continue;
                    }
                    $deduped[] = $event;
                }

                $timeline = $buildTimeline($deduped, $status, $nowTs);
                $durations = $computeDurations($timeline, $update['created_at'] ?? null, $status);

                $updates[] = [
                    'id' => $updId,
                    'title' => $update['title'],
                    'status' => $status,
                    'type' => $update['type'] ?? null,
                    'kind' => 'update',
                    'raised_at' => $durations['raised_at'],
                    'resolved_at' => $durations['resolved_at'],
                    'rise_duration_seconds' => $durations['rise_duration_seconds'],
                    'rise_duration_label' => $durations['rise_duration_seconds'] !== null
                        ? $formatDuration($durations['rise_duration_seconds']) : null,
                    'fix_duration_seconds' => $durations['fix_duration_seconds'],
                    'fix_duration_label' => $durations['fix_duration_seconds'] !== null
                        ? $formatDuration($durations['fix_duration_seconds']) : null,
                    'is_open' => $durations['is_open'],
                    'created_by_name' => $update['created_by_name'] ?? null,
                    'status_timeline' => $timeline,
                ];
            }

            $memberCountStmt = $this->conn->prepare(
                'SELECT COUNT(*) AS total FROM project_members WHERE project_id = ?'
            );
            $memberCountStmt->execute([$projectId]);
            $memberCount = (int) ($memberCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $this->sendJsonResponse(200, 'Project analytics retrieved successfully', [
                'project' => [
                    'id' => (string) $projectRow['id'],
                    'name' => $projectRow['name'],
                    'status' => $projectRow['status'] ?? (($hasIsActive && isset($projectRow['is_active']))
                        ? ((int) $projectRow['is_active'] === 1 ? 'active' : 'inactive')
                        : null),
                    'is_active' => isset($projectRow['is_active']) ? (int) $projectRow['is_active'] : null,
                    'created_at' => $projectRow['created_at'] ?? null,
                    'member_count' => $memberCount,
                    'counts' => [
                        'bugs' => count($bugs),
                        'fixes' => count($fixes),
                        'updates' => count($updates),
                        'open' => $openCount,
                    ],
                ],
                'summary' => [
                    'bugs' => count($bugs),
                    'fixes' => count($fixes),
                    'updates' => count($updates),
                    'open' => $openCount,
                    'members' => $memberCount,
                    'avg_rise_duration_seconds' => $riseCount > 0 ? (int) round($riseSum / $riseCount) : null,
                    'avg_rise_duration_label' => $riseCount > 0 ? $formatDuration((int) round($riseSum / $riseCount)) : null,
                    'avg_fix_duration_seconds' => $fixCount > 0 ? (int) round($fixSum / $fixCount) : null,
                    'avg_fix_duration_label' => $fixCount > 0 ? $formatDuration((int) round($fixSum / $fixCount)) : null,
                ],
                'bugs' => $bugs,
                'fixes' => $fixes,
                'updates' => $updates,
            ]);
        } catch (PDOException $e) {
            error_log('Database error in getProjectAnalytics: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Database error occurred');
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'token') ? 401 : 500;
            $this->sendJsonResponse($status, $e->getMessage());
        }
    }

    private function userCanViewProject($decoded, string $projectId): bool
    {
        $userId = $decoded->user_id ?? null;
        if (!$userId) {
            return false;
        }

        $userRole = strtolower(trim($decoded->role ?? ''));
        if ($userRole === 'admin') {
            return true;
        }

        $stmt = $this->conn->prepare(
            'SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        return (bool) $stmt->fetch();
    }
}
