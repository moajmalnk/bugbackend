<?php
require_once __DIR__ . '/../BaseAPI.php';

class ClientController extends BaseAPI
{
    private static $CLIENT_FIELDS = [
        'corporate_name',
        'website',
        'market_industry',
        'gst_tax_id',
        'commercial_status',
        'primary_contact_name',
        'position',
        'hq_location',
        'direct_email',
        'direct_phone',
        'birthday',
        'date_of_joining',
        'date_of_ending',
        'referral_source',
        'notes',
    ];

    private function ensureSchema(): void
    {
        try {
            $check = $this->conn->query("SHOW TABLES LIKE 'clients'");
            if (!$check || $check->rowCount() === 0) {
                $migration = realpath(__DIR__ . '/../../migrations/027_clients.sql');
                if ($migration && is_readable($migration)) {
                    $sql = file_get_contents($migration);
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                        if ($statement !== '') {
                            $this->conn->exec($statement);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ClientController::ensureSchema: ' . $e->getMessage());
        }
    }

    private function migrateLegacyClientData(): void
    {
        try {
            $colCheck = $this->conn->query("SHOW COLUMNS FROM projects LIKE 'client_name'");
            if (!$colCheck || $colCheck->rowCount() === 0) {
                return;
            }

            $stmt = $this->conn->query(
                "SELECT DISTINCT TRIM(client_name) AS client_name
                 FROM projects
                 WHERE client_name IS NOT NULL AND TRIM(client_name) <> ''
                   AND (client_id IS NULL OR client_id = '')"
            );
            $names = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            if (empty($names)) {
                return;
            }

            foreach ($names as $name) {
                $existing = $this->conn->prepare(
                    "SELECT id FROM clients WHERE LOWER(TRIM(corporate_name)) = LOWER(TRIM(?)) LIMIT 1"
                );
                $existing->execute([$name]);
                $clientId = $existing->fetchColumn();

                if (!$clientId) {
                    $sample = $this->conn->prepare(
                        "SELECT client_location, client_contact_name, client_email, client_phone, client_account_status
                         FROM projects
                         WHERE TRIM(client_name) = TRIM(?)
                         ORDER BY updated_at DESC
                         LIMIT 1"
                    );
                    $sample->execute([$name]);
                    $row = $sample->fetch(PDO::FETCH_ASSOC) ?: [];

                    $commercialStatus = 'lead';
                    if (!empty($row['client_account_status'])) {
                        $commercialStatus = $row['client_account_status'] === 'inactive' ? 'inactive' : 'active';
                    }

                    $clientId = Utils::generateUUID();
                    $insert = $this->conn->prepare(
                        "INSERT INTO clients (
                            id, corporate_name, hq_location, primary_contact_name,
                            direct_email, direct_phone, commercial_status
                         ) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insert->execute([
                        $clientId,
                        $name,
                        $row['client_location'] ?? null,
                        $row['client_contact_name'] ?? null,
                        $row['client_email'] ?? null,
                        $row['client_phone'] ?? null,
                        $commercialStatus,
                    ]);
                }

                $update = $this->conn->prepare(
                    "UPDATE projects SET client_id = ?
                     WHERE TRIM(client_name) = TRIM(?)
                       AND (client_id IS NULL OR client_id = '')"
                );
                $update->execute([$clientId, $name]);
            }
        } catch (Exception $e) {
            error_log('ClientController::migrateLegacyClientData: ' . $e->getMessage());
        }
    }

    private function requireAdmin($decoded): void
    {
        if (strtolower(trim((string) ($decoded->role ?? ''))) !== 'admin') {
            $this->sendJsonResponse(403, 'Only admins can manage clients');
            exit;
        }
    }

    private function normalizeDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $value;
    }

    private function getClientAttachments(string $clientId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, client_id, file_name, file_path, file_type, uploaded_by, created_at
             FROM client_attachments WHERE client_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getLinkedProjects(string $clientId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, name, status, created_at, updated_at
             FROM projects WHERE client_id = ?
             ORDER BY updated_at DESC"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function enrichClientRecord(array &$client): void
    {
        $clientId = $client['id'] ?? '';
        if ($clientId === '') {
            return;
        }

        $countStmt = $this->conn->prepare(
            "SELECT
                COUNT(*) AS project_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_project_count
             FROM projects WHERE client_id = ?"
        );
        $countStmt->execute([$clientId]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $client['project_count'] = (int) ($counts['project_count'] ?? 0);
        $client['active_project_count'] = (int) ($counts['active_project_count'] ?? 0);
    }

    public function getClients(): void
    {
        try {
            $this->validateToken();
            $this->ensureSchema();
            $this->migrateLegacyClientData();

            $query = "SELECT c.*,
                (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS project_count,
                (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND p.status = 'active') AS active_project_count
                FROM clients c
                ORDER BY c.corporate_name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->sendJsonResponse(200, 'Clients retrieved successfully', $clients);
        } catch (Exception $e) {
            error_log('ClientController::getClients: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function getClient(string $id): void
    {
        try {
            $this->validateToken();
            $this->ensureSchema();

            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$client) {
                $this->sendJsonResponse(404, 'Client not found');
                return;
            }

            $this->enrichClientRecord($client);
            $client['attachments'] = $this->getClientAttachments($id);
            $client['projects'] = $this->getLinkedProjects($id);

            $this->sendJsonResponse(200, 'Client retrieved successfully', $client);
        } catch (Exception $e) {
            error_log('ClientController::getClient: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function createClient(array $data, $decoded): void
    {
        try {
            $this->requireAdmin($decoded);
            $this->ensureSchema();

            $corporateName = trim((string) ($data['corporate_name'] ?? ''));
            if ($corporateName === '') {
                $this->sendJsonResponse(400, 'Corporate name is required');
                return;
            }

            $id = Utils::generateUUID();
            $columns = ['id', 'created_by'];
            $placeholders = ['?', '?'];
            $values = [$id, $decoded->user_id];

            foreach (self::$CLIENT_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $columns[] = $field;
                    $placeholders[] = '?';
                    $values[] = $field === 'corporate_name'
                        ? trim((string) $data[$field])
                        : $this->normalizeDate($data[$field]);
                }
            }

            if (!in_array('corporate_name', $columns, true)) {
                $columns[] = 'corporate_name';
                $placeholders[] = '?';
                $values[] = $corporateName;
            }

            $query = 'INSERT INTO clients (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);

            $fetch = $this->conn->prepare('SELECT * FROM clients WHERE id = ?');
            $fetch->execute([$id]);
            $client = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->enrichClientRecord($client);
            $client['attachments'] = [];
            $client['projects'] = [];

            $this->sendJsonResponse(201, 'Client created successfully', $client);
        } catch (Exception $e) {
            error_log('ClientController::createClient: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function updateClient(string $id, array $data, $decoded): void
    {
        try {
            $this->requireAdmin($decoded);
            $this->ensureSchema();

            $check = $this->conn->prepare('SELECT id FROM clients WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                $this->sendJsonResponse(404, 'Client not found');
                return;
            }

            $updateFields = [];
            $values = [];

            foreach (self::$CLIENT_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $updateFields[] = "$field = ?";
                    $values[] = $field === 'corporate_name'
                        ? trim((string) $data[$field])
                        : $this->normalizeDate($data[$field]);
                }
            }

            if (empty($updateFields)) {
                $this->sendJsonResponse(400, 'No fields to update');
                return;
            }

            $values[] = $id;
            $query = 'UPDATE clients SET ' . implode(', ', $updateFields) . ', updated_at = CURRENT_TIMESTAMP() WHERE id = ?';
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);

            $fetch = $this->conn->prepare('SELECT * FROM clients WHERE id = ?');
            $fetch->execute([$id]);
            $client = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->enrichClientRecord($client);
            $client['attachments'] = $this->getClientAttachments($id);
            $client['projects'] = $this->getLinkedProjects($id);

            $this->sendJsonResponse(200, 'Client updated successfully', $client);
        } catch (Exception $e) {
            error_log('ClientController::updateClient: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }

    public function deleteClient(string $id, bool $force, $decoded): void
    {
        try {
            $this->requireAdmin($decoded);
            $this->ensureSchema();

            $check = $this->conn->prepare('SELECT id FROM clients WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                $this->sendJsonResponse(404, 'Client not found');
                return;
            }

            $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM projects WHERE client_id = ?');
            $countStmt->execute([$id]);
            $projectCount = (int) $countStmt->fetchColumn();

            if ($projectCount > 0 && !$force) {
                $this->sendJsonResponse(409, 'Cannot delete client with linked projects', [
                    'project_count' => $projectCount,
                    'canForceDelete' => true,
                ]);
                return;
            }

            $this->conn->beginTransaction();

            if ($force && $projectCount > 0) {
                $unlink = $this->conn->prepare('UPDATE projects SET client_id = NULL WHERE client_id = ?');
                $unlink->execute([$id]);
            }

            $delete = $this->conn->prepare('DELETE FROM clients WHERE id = ?');
            $delete->execute([$id]);

            $this->conn->commit();
            $this->sendJsonResponse(200, 'Client deleted successfully');
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('ClientController::deleteClient: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}
