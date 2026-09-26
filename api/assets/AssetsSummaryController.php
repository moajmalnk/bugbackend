<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsSummaryController extends AssetsAuth
{
    public function summary(): void
    {
        if (!$this->requireView()) {
            return;
        }
        if (!$this->tableReady('assets_domains')) {
            $this->sendJsonResponse(200, 'OK', $this->emptySummary());
            return;
        }

        $live = 'deleted_at IS NULL';
        $soon = date('Y-m-d', strtotime('+30 days'));
        $today = date('Y-m-d');

        $counts = [
            'clients' => $this->scalar("SELECT COUNT(*) FROM clients WHERE {$live} AND commercial_status IN ('lead','active')"),
            'domains' => $this->scalar("SELECT COUNT(*) FROM assets_domains WHERE {$live} AND status = 'active'"),
            'subdomains' => $this->scalar("SELECT COUNT(*) FROM assets_subdomains WHERE {$live} AND status = 'active'"),
            'mailboxes' => $this->scalar("SELECT COUNT(*) FROM assets_emails WHERE {$live} AND status = 'active'"),
            'servers' => $this->scalar("SELECT COUNT(*) FROM assets_servers WHERE {$live} AND status = 'active'"),
            'hosting' => $this->scalar("SELECT COUNT(*) FROM assets_hosting WHERE {$live} AND status = 'active'"),
            'vercel' => $this->scalar("SELECT COUNT(*) FROM assets_vercel WHERE {$live} AND status = 'active'"),
            'hardware' => $this->scalar("SELECT COUNT(*) FROM assets_hardware WHERE {$live}"),
            'renewals_30' => $this->renewalsDueCount($today, $soon),
        ];

        if ($this->canFinance()) {
            $counts['margin_total'] = $this->marginTotal();
        }

        $this->sendJsonResponse(200, 'OK', $counts);
    }

    public function clientGraph(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $clientId = trim((string) ($_GET['client_id'] ?? $_GET['id'] ?? ''));
        if ($clientId === '') {
            $this->sendJsonResponse(400, 'client_id is required');
            return;
        }
        if (!$this->tableReady('assets_domains')) {
            $this->sendJsonResponse(200, 'OK', $this->emptyClientGraph($clientId));
            return;
        }

        $cstmt = $this->conn->prepare(
            'SELECT id, client_code, corporate_name, commercial_status FROM clients WHERE id = ? AND deleted_at IS NULL'
        );
        $cstmt->execute([$clientId]);
        $client = $cstmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            $this->sendJsonResponse(404, 'Client not found');
            return;
        }

        $dstmt = $this->conn->prepare(
            "SELECT d.*, p.name AS project_name
             FROM assets_domains d
             LEFT JOIN projects p ON p.id = d.project_id
             WHERE d.client_id = ? AND d.deleted_at IS NULL
             ORDER BY d.fqdn ASC"
        );
        $dstmt->execute([$clientId]);
        $domains = $this->mapFinance($dstmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $domains = $this->attachHasSecret('domain', $domains);
        $this->attachDomainChildren($domains);

        $estmt = $this->conn->prepare(
            "SELECT e.id, e.address, e.provider, e.status, e.expires_at, e.storage_quota_mb,
                    e.domain_id, d.fqdn AS domain_fqdn
             FROM assets_emails e
             JOIN assets_domains d ON d.id = e.domain_id AND d.deleted_at IS NULL
             WHERE d.client_id = ? AND e.deleted_at IS NULL
             ORDER BY e.address ASC"
        );
        $estmt->execute([$clientId]);
        $emails = $estmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $nodes = $this->nodesForClient($clientId);

        $subCount = 0;
        foreach ($domains as $d) {
            $subCount += count($d['subdomains'] ?? []);
        }

        $payload = [
            'client' => $client,
            'counts' => [
                'domains' => count($domains),
                'subdomains' => $subCount,
                'mailboxes' => count($emails),
                'servers' => $this->countNodes($nodes, 'server'),
                'hosting' => $this->countNodes($nodes, 'hosting'),
                'vercel' => $this->countNodes($nodes, 'vercel'),
            ],
            'domains' => $domains,
            'emails' => $emails,
            'nodes' => $nodes,
        ];
        $this->sendJsonResponse(200, 'OK', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nodesForClient(string $clientId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT ln.id AS link_id, ln.node_kind, ln.node_id, ln.role, ln.project_id
             FROM assets_client_nodes ln
             WHERE ln.client_id = ?
             ORDER BY ln.created_at DESC"
        );
        $stmt->execute([$clientId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($links as $link) {
            $table = $this->nodeTable((string) $link['node_kind']);
            if ($table === null) {
                continue;
            }
            $row = $this->fetchLive($table, (string) $link['node_id']);
            if (!$row) {
                continue;
            }
            $row = $this->maybeStripFinance($row);
            $row['node_kind'] = $link['node_kind'];
            $row['link_id'] = $link['link_id'];
            $row['link_role'] = $link['role'];
            $out[] = $row;
        }
        return $out;
    }

    private function nodeTable(string $kind): ?string
    {
        if ($kind === 'server') {
            return 'assets_servers';
        }
        if ($kind === 'hosting') {
            return 'assets_hosting';
        }
        if ($kind === 'vercel') {
            return 'assets_vercel';
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function countNodes(array $nodes, string $kind): int
    {
        $n = 0;
        foreach ($nodes as $node) {
            if (($node['node_kind'] ?? '') === $kind) {
                $n++;
            }
        }
        return $n;
    }

    private function emptySummary(): array
    {
        return [
            'clients' => 0,
            'domains' => 0,
            'subdomains' => 0,
            'mailboxes' => 0,
            'servers' => 0,
            'hosting' => 0,
            'vercel' => 0,
            'hardware' => 0,
            'renewals_30' => 0,
        ];
    }

    private function emptyClientGraph(string $clientId): array
    {
        return [
            'client' => ['id' => $clientId],
            'counts' => [
                'domains' => 0,
                'subdomains' => 0,
                'mailboxes' => 0,
                'servers' => 0,
                'hosting' => 0,
                'vercel' => 0,
            ],
            'domains' => [],
            'emails' => [],
            'nodes' => [],
        ];
    }

    /**
     * Attach subdomain + mailbox lists under each domain for client inventory views.
     *
     * @param list<array<string, mixed>> $domains
     */
    private function attachDomainChildren(array &$domains): void
    {
        if ($domains === []) {
            return;
        }
        $ids = [];
        foreach ($domains as $d) {
            if (!empty($d['id'])) {
                $ids[] = (string) $d['id'];
            }
        }
        if ($ids === []) {
            return;
        }

        $subsByDomain = [];
        $mailsByDomain = [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($this->tableReady('assets_subdomains')) {
            $subs = $this->conn->prepare(
                "SELECT id, domain_id, host, fqdn, purpose, record_type, target_kind, status
                 FROM assets_subdomains
                 WHERE deleted_at IS NULL AND domain_id IN ({$placeholders})
                 ORDER BY fqdn ASC"
            );
            $subs->execute($ids);
            foreach ($subs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $did = (string) ($row['domain_id'] ?? '');
                if ($did === '') {
                    continue;
                }
                if (!isset($subsByDomain[$did])) {
                    $subsByDomain[$did] = [];
                }
                $subsByDomain[$did][] = $row;
            }
        }

        if ($this->tableReady('assets_emails')) {
            $mails = $this->conn->prepare(
                "SELECT id, domain_id, address, provider, status, expires_at, storage_quota_mb
                 FROM assets_emails
                 WHERE deleted_at IS NULL AND domain_id IN ({$placeholders})
                 ORDER BY address ASC"
            );
            $mails->execute($ids);
            foreach ($mails->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $did = (string) ($row['domain_id'] ?? '');
                if ($did === '') {
                    continue;
                }
                if (!isset($mailsByDomain[$did])) {
                    $mailsByDomain[$did] = [];
                }
                $mailsByDomain[$did][] = $row;
            }
        }

        foreach ($domains as $i => $d) {
            $id = (string) ($d['id'] ?? '');
            if (array_key_exists('nameservers', $d) && is_string($d['nameservers']) && $d['nameservers'] !== '') {
                $decoded = json_decode($d['nameservers'], true);
                $domains[$i]['nameservers'] = is_array($decoded) ? $decoded : [];
            } elseif (!isset($d['nameservers']) || !is_array($d['nameservers'])) {
                $domains[$i]['nameservers'] = [];
            }
            $domains[$i]['subdomains'] = $subsByDomain[$id] ?? [];
            $domains[$i]['emails'] = $mailsByDomain[$id] ?? [];
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): int
    {
        try {
            $stmt = $params === [] ? $this->conn->query($sql) : $this->conn->prepare($sql);
            if ($params !== []) {
                $stmt->execute($params);
            }
            return $stmt ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function renewalsDueCount(string $today, string $soon): int
    {
        try {
            $sql = "
                SELECT (
                  (SELECT COUNT(*) FROM assets_domains WHERE deleted_at IS NULL AND expires_at BETWEEN ? AND ?)
                + (SELECT COUNT(*) FROM assets_ssl_certs WHERE deleted_at IS NULL AND expires_at BETWEEN ? AND ?)
                + (SELECT COUNT(*) FROM assets_servers WHERE deleted_at IS NULL AND expires_at BETWEEN ? AND ?)
                + (SELECT COUNT(*) FROM assets_hosting WHERE deleted_at IS NULL AND expires_at BETWEEN ? AND ?)
                + (SELECT COUNT(*) FROM assets_vercel WHERE deleted_at IS NULL AND expires_at BETWEEN ? AND ?)
                + (SELECT COUNT(*) FROM assets_hardware WHERE deleted_at IS NULL AND warranty_expires_at BETWEEN ? AND ?)
                ) AS cnt
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$today, $soon, $today, $soon, $today, $soon, $today, $soon, $today, $soon, $today, $soon]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function marginTotal(): float
    {
        try {
            $sql = "
                SELECT
                  IFNULL((SELECT SUM(margin_amount) FROM assets_domains WHERE deleted_at IS NULL), 0)
                + IFNULL((SELECT SUM(margin_amount) FROM assets_servers WHERE deleted_at IS NULL), 0)
                + IFNULL((SELECT SUM(margin_amount) FROM assets_hosting WHERE deleted_at IS NULL), 0)
                + IFNULL((SELECT SUM(margin_amount) FROM assets_vercel WHERE deleted_at IS NULL), 0)
                AS total
            ";
            $stmt = $this->conn->query($sql);
            return $stmt ? (float) $stmt->fetchColumn() : 0.0;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
