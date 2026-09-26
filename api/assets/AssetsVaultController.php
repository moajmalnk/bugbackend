<?php
require_once __DIR__ . '/AssetsAuth.php';
require_once __DIR__ . '/../../utils/asset_vault.php';

class AssetsVaultController extends AssetsAuth
{
    private const ENTITY_TYPES = ['server', 'hosting', 'vercel', 'domain', 'email', 'hardware', 'tool'];
    private const KINDS = ['password', 'ssh_private_key', 'api_token', 'recovery_code', 'other'];

    public function store(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $entityType = $this->enumOr((string) ($data['entity_type'] ?? ''), self::ENTITY_TYPES, '');
        $entityId = trim((string) ($data['entity_id'] ?? ''));
        $kind = $this->enumOr((string) ($data['kind'] ?? 'password'), self::KINDS, 'password');
        $label = assetNullableString($data['label'] ?? 'Secret', 120) ?: 'Secret';
        $plaintext = (string) ($data['secret'] ?? $data['plaintext'] ?? '');
        if ($entityType === '' || $entityId === '' || $plaintext === '') {
            $this->sendJsonResponse(400, 'entity_type, entity_id and secret are required');
            return;
        }
        if (!$this->entityExists($entityType, $entityId)) {
            $this->sendJsonResponse(404, 'Asset not found');
            return;
        }
        try {
            $sealed = assetVaultSeal($plaintext);
        } catch (Throwable $e) {
            $this->sendJsonResponse(503, 'Vault is not configured');
            return;
        }
        $id = Utils::generateUUID();
        $this->insertRow('assets_vault_secrets', [
            'id' => $id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'kind' => $kind,
            'label' => $label,
            'ciphertext' => $sealed['ciphertext'],
            'nonce' => $sealed['nonce'],
            'dek_wrapped' => $sealed['dek_wrapped'],
            'wrap_nonce' => $sealed['wrap_nonce'],
            'key_version' => 1,
            'fingerprint' => assetVaultFingerprint($plaintext, $kind),
            'created_by' => $this->decoded->user_id,
        ]);
        $this->logAccess($id, 'create');
        $this->sendJsonResponse(201, 'Secret stored', [
            'id' => $id,
            'fingerprint' => assetVaultFingerprint($plaintext, $kind),
            'has_secret' => true,
        ]);
    }

    public function reveal(): void
    {
        if (!$this->requireVaultReveal()) {
            return;
        }
        if ($this->revealRateLimited()) {
            $this->sendJsonResponse(429, 'Too many vault reveals. Wait a minute and try again.');
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? $_GET['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $stmt = $this->conn->prepare(
            'SELECT id, entity_type, entity_id, kind, label, ciphertext, nonce, dek_wrapped, wrap_nonce, fingerprint
             FROM assets_vault_secrets WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Secret not found');
            return;
        }
        try {
            $plain = assetVaultOpen(
                $row['ciphertext'],
                $row['nonce'],
                $row['dek_wrapped'],
                $row['wrap_nonce']
            );
        } catch (Throwable $e) {
            error_log('AssetsVaultController::reveal failed');
            $this->sendJsonResponse(500, 'Unable to open vault secret');
            return;
        }
        $this->logAccess($id, 'reveal');
        $this->sendJsonResponse(200, 'OK', [
            'id' => $row['id'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'kind' => $row['kind'],
            'label' => $row['label'],
            'fingerprint' => $row['fingerprint'],
            'secret' => $plain,
        ]);
    }

    public function rotate(): void
    {
        if (!$this->requireVaultReveal()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        $plaintext = (string) ($data['secret'] ?? $data['plaintext'] ?? '');
        if ($id === '' || $plaintext === '') {
            $this->sendJsonResponse(400, 'id and secret are required');
            return;
        }
        $stmt = $this->conn->prepare('SELECT id, kind FROM assets_vault_secrets WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Secret not found');
            return;
        }
        try {
            $sealed = assetVaultSeal($plaintext);
        } catch (Throwable $e) {
            $this->sendJsonResponse(503, 'Vault is not configured');
            return;
        }
        $fp = assetVaultFingerprint($plaintext, (string) $row['kind']);
        $upd = $this->conn->prepare(
            'UPDATE assets_vault_secrets
             SET ciphertext = ?, nonce = ?, dek_wrapped = ?, wrap_nonce = ?, fingerprint = ?, rotated_at = NOW()
             WHERE id = ?'
        );
        $upd->execute([
            $sealed['ciphertext'],
            $sealed['nonce'],
            $sealed['dek_wrapped'],
            $sealed['wrap_nonce'],
            $fp,
            $id,
        ]);
        $this->logAccess($id, 'rotate');
        $this->sendJsonResponse(200, 'Secret rotated', ['id' => $id, 'fingerprint' => $fp, 'has_secret' => true]);
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $this->logAccess($id, 'delete');
        $stmt = $this->conn->prepare('DELETE FROM assets_vault_secrets WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $this->sendJsonResponse(404, 'Secret not found');
            return;
        }
        $this->sendJsonResponse(200, 'Secret deleted');
    }

    public function listForEntity(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $entityType = $this->enumOr((string) ($_GET['entity_type'] ?? ''), self::ENTITY_TYPES, '');
        $entityId = trim((string) ($_GET['entity_id'] ?? ''));
        if ($entityType === '' || $entityId === '') {
            $this->sendJsonResponse(400, 'entity_type and entity_id are required');
            return;
        }
        $stmt = $this->conn->prepare(
            'SELECT id, entity_type, entity_id, kind, label, fingerprint, created_at, rotated_at
             FROM assets_vault_secrets
             WHERE entity_type = ? AND entity_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$entityType, $entityId]);
        $this->sendJsonResponse(200, 'OK', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function entityExists(string $type, string $id): bool
    {
        $tables = [
            'server' => 'assets_servers',
            'hosting' => 'assets_hosting',
            'vercel' => 'assets_vercel',
            'domain' => 'assets_domains',
            'email' => 'assets_emails',
            'hardware' => 'assets_hardware',
        ];
        $table = $tables[$type] ?? null;
        return $table ? (bool) $this->fetchLive($table, $id) : false;
    }

    private function revealRateLimited(): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM assets_vault_access_log
             WHERE user_id = ? AND action = 'reveal' AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)"
        );
        $stmt->execute([$this->decoded->user_id]);
        return (int) $stmt->fetchColumn() >= 10;
    }

    private function logAccess(string $secretId, string $action): void
    {
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO assets_vault_access_log (secret_id, user_id, action, ip) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$secretId, $this->decoded->user_id, $action, $this->clientRequestIp()]);
        } catch (Throwable $e) {
            error_log('assets vault access log failed');
        }
    }
}
