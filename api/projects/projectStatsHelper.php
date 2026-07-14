<?php

/**
 * Attach members, bug_stats, and member_stats to each project in one batch.
 */
function attachProjectListStats(PDO $conn, array &$projects): void
{
    if (count($projects) === 0) {
        return;
    }

    $projectIds = array_column($projects, 'id');
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

    $membersByProject = [];
    $membersDetailByProject = [];
    $memberStatsByProject = [];
    $memberStmt = $conn->prepare(
        "SELECT pm.project_id, pm.user_id, pm.role, u.username, u.email
         FROM project_members pm
         LEFT JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id IN ($placeholders)"
    );
    $memberStmt->execute($projectIds);
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (string) $row['project_id'];
        if (!isset($membersByProject[$pid])) {
            $membersByProject[$pid] = [];
            $membersDetailByProject[$pid] = [];
            $memberStatsByProject[$pid] = [
                'total' => 0,
                'developers' => 0,
                'testers' => 0,
            ];
        }
        $membersByProject[$pid][] = $row['user_id'];
        $membersDetailByProject[$pid][] = [
            'user_id' => $row['user_id'],
            'role' => $row['role'],
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
        ];
        $memberStatsByProject[$pid]['total']++;
        if ($row['role'] === 'developer') {
            $memberStatsByProject[$pid]['developers']++;
        }
        if ($row['role'] === 'tester') {
            $memberStatsByProject[$pid]['testers']++;
        }
    }

    $bugStatsByProject = [];
    $bugStmt = $conn->prepare(
        "SELECT project_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('pending', 'in_progress') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'fixed' THEN 1 ELSE 0 END) AS fixed_count
         FROM bugs
         WHERE project_id IN ($placeholders)
         GROUP BY project_id"
    );
    $bugStmt->execute($projectIds);
    foreach ($bugStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (string) $row['project_id'];
        $bugStatsByProject[$pid] = [
            'total' => (int) $row['total'],
            'open' => (int) $row['open_count'],
            'fixed' => (int) $row['fixed_count'],
        ];
    }

    $defaultBug = ['total' => 0, 'open' => 0, 'fixed' => 0];
    $defaultMember = ['total' => 0, 'developers' => 0, 'testers' => 0];

    foreach ($projects as &$project) {
        $pid = (string) $project['id'];
        $project['members'] = $membersByProject[$pid] ?? [];
        $project['members_detail'] = $membersDetailByProject[$pid] ?? [];
        $project['bug_stats'] = $bugStatsByProject[$pid] ?? $defaultBug;
        $project['member_stats'] = $memberStatsByProject[$pid] ?? $defaultMember;
    }
    unset($project);
}

/**
 * Build stats maps for optional dedicated stats endpoint.
 */
function buildProjectStatsBundle(PDO $conn, array $projectIds, int $user_id, bool $is_admin): array
{
    $stats = [
        'bugs' => [],
        'members' => [],
        'memberships' => [],
    ];

    if (count($projectIds) === 0) {
        return $stats;
    }

    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

    $bugStmt = $conn->prepare(
        "SELECT project_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('pending', 'in_progress') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'fixed' THEN 1 ELSE 0 END) AS fixed_count
         FROM bugs
         WHERE project_id IN ($placeholders)
         GROUP BY project_id"
    );
    $bugStmt->execute($projectIds);
    foreach ($bugStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (string) $row['project_id'];
        $stats['bugs'][$pid] = [
            'total' => (int) $row['total'],
            'open' => (int) $row['open_count'],
            'fixed' => (int) $row['fixed_count'],
        ];
    }

    $memberStmt = $conn->prepare(
        "SELECT project_id,
                COUNT(*) AS total,
                SUM(CASE WHEN role = 'developer' THEN 1 ELSE 0 END) AS developers,
                SUM(CASE WHEN role = 'tester' THEN 1 ELSE 0 END) AS testers
         FROM project_members
         WHERE project_id IN ($placeholders)
         GROUP BY project_id"
    );
    $memberStmt->execute($projectIds);
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (string) $row['project_id'];
        $stats['members'][$pid] = [
            'total' => (int) $row['total'],
            'developers' => (int) $row['developers'],
            'testers' => (int) $row['testers'],
        ];
    }

    if ($is_admin) {
        foreach ($projectIds as $pid) {
            $stats['memberships'][(string) $pid] = true;
        }
    } else {
        $membershipStmt = $conn->prepare(
            "SELECT project_id
             FROM project_members
             WHERE user_id = ?
               AND project_id IN ($placeholders)"
        );
        $membershipParams = array_merge([$user_id], $projectIds);
        $membershipStmt->execute($membershipParams);
        foreach ($membershipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats['memberships'][(string) $row['project_id']] = true;
        }
    }

    return $stats;
}
