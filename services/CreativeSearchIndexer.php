<?php
/**
 * Why: BugCreative global search must match title/hook/creator AND words inside
 * uploaded posters (OCR) and PDFs. Gemini extracts media text; metadata is always
 * baked into search_text so LIKE / FULLTEXT stay useful without re-hitting the API.
 */
require_once __DIR__ . '/GeminiService.php';

class CreativeSearchIndexer
{
    private const MAX_INLINE_BYTES = 12 * 1024 * 1024;
    private const IMAGE_EXT = ['webp', 'jpg', 'jpeg', 'png', 'gif'];
    /** Why: Separates metadata blob from OCR/PDF body so metadata-only reindexes keep media text. */
    private const BODY_SEP = "\n---OCR---\n";
    private const OCR_PROMPT = <<<'PROMPT'
Extract every readable word and short phrase visible in this creative asset (poster, thumbnail, or PDF page).
Include headlines, subheads, bullet points, CTAs, logos text, dates, URLs, hashtags, and fine print.
Return plain text only — no markdown, no commentary. If there is no readable text, return an empty string.
PROMPT;

    private PDO $conn;
    private GeminiService $gemini;
    private static ?bool $schemaReady = null;
    private static ?bool $fulltextReady = null;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->gemini = new GeminiService();
    }

    public function schemaReady(): bool
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM creative_assets LIKE 'search_text'");
            self::$schemaReady = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$schemaReady = false;
        }
        return self::$schemaReady;
    }

    public function ensureSchema(): void
    {
        if ($this->schemaReady()) {
            return;
        }
        $migration = realpath(__DIR__ . '/../migrations/142_creative_assets_full_search.sql');
        if (!$migration || !is_readable($migration)) {
            return;
        }
        try {
            $sql = file_get_contents($migration);
            if ($sql === false || trim($sql) === '') {
                return;
            }
            // Why: Prepared SET/@var blocks must run as whole statements; split on semicolons carefully.
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) {
                if ($statement === '') {
                    continue;
                }
                $this->conn->exec($statement);
            }
            self::$schemaReady = null;
            self::$fulltextReady = null;
        } catch (Throwable $e) {
            error_log('CreativeSearchIndexer::ensureSchema: ' . $e->getMessage());
        }
    }

    public function geminiConfigured(): bool
    {
        return $this->gemini->isConfigured();
    }

    public function fulltextReady(): bool
    {
        if (self::$fulltextReady !== null) {
            return self::$fulltextReady;
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'creative_assets'
                   AND INDEX_NAME = 'ft_creative_assets_search'
                 LIMIT 1"
            );
            $stmt->execute();
            self::$fulltextReady = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            self::$fulltextReady = false;
        }
        return self::$fulltextReady;
    }

    /**
     * Absolute path under backend/ for a stored relative upload path.
     */
    public function resolveUploadPath(?string $relative): ?string
    {
        $relative = trim((string)$relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (!str_starts_with($relative, 'uploads/creative/')) {
            return null;
        }
        $abs = realpath(__DIR__ . '/../' . $relative);
        if ($abs === false || !is_file($abs)) {
            // Why: realpath fails when the file was just written on some hosts; try join directly.
            $candidate = __DIR__ . '/../' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
            return null;
        }
        return $abs;
    }

    /**
     * @param array<string, mixed> $row creative_assets row (+ optional joins)
     */
    public function contentHash(array $row): string
    {
        $parts = [
            (string)($row['title'] ?? ''),
            (string)($row['hook_content'] ?? ''),
            (string)($row['material_type'] ?? ''),
            (string)($row['platform'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['drive_link'] ?? ''),
            (string)($row['uploaded_file_path'] ?? ''),
            (string)($row['preview_thumbnail_url'] ?? ''),
            (string)($row['folder_id'] ?? ''),
            (string)($row['project_id'] ?? ''),
            (string)($row['creator_name'] ?? ''),
            (string)($row['project_name'] ?? ''),
            (string)($row['folder_name'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function rowHasExtractableMedia(array $row): bool
    {
        foreach (['uploaded_file_path', 'preview_thumbnail_url'] as $key) {
            $rel = trim((string)($row[$key] ?? ''));
            if ($rel === '') {
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (in_array($ext, array_merge(self::IMAGE_EXT, ['pdf']), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function needsReindex(array $row): bool
    {
        if (!$this->schemaReady()) {
            return false;
        }
        $hash = $this->contentHash($row);
        $existing = (string)($row['search_index_hash'] ?? '');
        if ($existing === '' || !hash_equals($existing, $hash)) {
            return true;
        }
        // Why: Metadata may be indexed while OCR for local image/PDF has never run.
        if ($this->rowHasExtractableMedia($row) && !str_contains((string)($row['search_text'] ?? ''), self::BODY_SEP)) {
            return true;
        }
        return false;
    }

    /**
     * Index one asset. Returns true when search_text was written.
     *
     * @param array<string, mixed>|null $row preloaded row; fetched when null
     */
    public function indexAsset(string $assetId, ?array $row = null, bool $extractMedia = true): bool
    {
        $this->ensureSchema();
        if (!$this->schemaReady()) {
            return false;
        }

        if ($row === null) {
            $row = $this->fetchAssetRow($assetId);
        }
        if (!$row) {
            return false;
        }

        $hash = $this->contentHash($row);
        if (
            !$extractMedia
            && (string)($row['search_index_hash'] ?? '') !== ''
            && hash_equals((string)$row['search_index_hash'], $hash)
        ) {
            return true;
        }

        $meta = $this->buildMetadataBlob($row);
        $mediaText = '';
        $ocrAttempted = false;
        if ($extractMedia) {
            $mediaText = $this->extractMediaText($row);
            // Why: Persist ---OCR--- even when empty so we do not re-hit Gemini forever.
            $ocrAttempted = true;
        } else {
            // Why: Keep prior OCR body when only metadata changed and caller skipped media.
            $stored = (string)($row['search_text'] ?? '');
            if (str_contains($stored, self::BODY_SEP)) {
                $mediaText = $this->extractStoredOcr($stored);
                $ocrAttempted = true;
            }
        }

        $blob = $ocrAttempted
            ? ($meta . self::BODY_SEP . $mediaText)
            : $meta;
        if (mb_strlen($blob) > 60000) {
            $blob = mb_substr($blob, 0, 60000);
        }

        $stmt = $this->conn->prepare(
            'UPDATE creative_assets
             SET search_text = ?, search_indexed_at = NOW(), search_index_hash = ?
             WHERE id = ?'
        );
        $stmt->execute([$blob !== '' ? $blob : null, $hash, $assetId]);
        return true;
    }

    /**
     * Index up to $limit stale / never-indexed assets (for backfill / search warm-up).
     *
     * @return array{indexed: int, skipped: int, errors: int}
     */
    public function indexPending(int $limit = 5, bool $extractMedia = true): array
    {
        $this->ensureSchema();
        if (!$this->schemaReady()) {
            return ['indexed' => 0, 'skipped' => 0, 'errors' => 0];
        }
        $limit = max(1, min(25, $limit));

        $sql = "SELECT a.*,
                       u.username AS creator_name,
                       p.name AS project_name,
                       f.name AS folder_name
                FROM creative_assets a
                LEFT JOIN users u ON u.id = a.creator_id
                LEFT JOIN projects p ON p.id = a.project_id
                LEFT JOIN creative_folders f ON f.id = a.folder_id
                WHERE a.search_indexed_at IS NULL
                   OR a.search_index_hash IS NULL
                   OR a.search_index_hash = ''
                   OR (
                        (
                          (a.uploaded_file_path IS NOT NULL AND a.uploaded_file_path != '')
                          OR (a.preview_thumbnail_url IS NOT NULL AND a.preview_thumbnail_url != '')
                        )
                        AND (a.search_text IS NULL OR a.search_text NOT LIKE '%---OCR---%')
                   )
                ORDER BY a.updated_at DESC
                LIMIT {$limit}";
        try {
            $rows = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('CreativeSearchIndexer::indexPending: ' . $e->getMessage());
            return ['indexed' => 0, 'skipped' => 0, 'errors' => 1];
        }

        $indexed = 0;
        $errors = 0;
        foreach ($rows as $row) {
            try {
                if ($this->indexAsset((string)$row['id'], $row, $extractMedia)) {
                    $indexed++;
                }
            } catch (Throwable $e) {
                $errors++;
                error_log('CreativeSearchIndexer::indexPending asset ' . ($row['id'] ?? '') . ': ' . $e->getMessage());
                // Why: Still mark metadata-only so a broken file does not block the queue forever.
                try {
                    $this->indexAsset((string)$row['id'], $row, false);
                } catch (Throwable $inner) {
                    // ignore
                }
            }
        }
        return ['indexed' => $indexed, 'skipped' => 0, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildMetadataBlob(array $row): string
    {
        $chunks = [
            (string)($row['title'] ?? ''),
            (string)($row['hook_content'] ?? ''),
            (string)($row['material_type'] ?? ''),
            (string)($row['platform'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['creator_name'] ?? ''),
            (string)($row['project_name'] ?? ''),
            (string)($row['folder_name'] ?? ''),
            (string)($row['admin_feedback'] ?? ''),
            (string)($row['drive_link'] ?? ''),
        ];
        foreach (['uploaded_file_path', 'preview_thumbnail_url'] as $key) {
            $path = (string)($row[$key] ?? '');
            if ($path !== '') {
                $chunks[] = basename($path);
                $chunks[] = preg_replace('/[_\-.]+/', ' ', pathinfo($path, PATHINFO_FILENAME)) ?: '';
            }
        }
        $chunks = array_values(array_filter(array_map(static function ($v) {
            return trim(preg_replace('/\s+/', ' ', (string)$v) ?? '');
        }, $chunks)));
        return implode("\n", $chunks);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractMediaText(array $row): string
    {
        if (!$this->gemini->isConfigured()) {
            return '';
        }

        $paths = [];
        foreach (['uploaded_file_path', 'preview_thumbnail_url'] as $key) {
            $rel = (string)($row[$key] ?? '');
            if ($rel === '') {
                continue;
            }
            $abs = $this->resolveUploadPath($rel);
            if ($abs === null) {
                continue;
            }
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, array_merge(self::IMAGE_EXT, ['pdf']), true)) {
                continue;
            }
            $paths[$abs] = $ext;
        }
        if (count($paths) === 0) {
            return '';
        }

        $inline = [];
        $total = 0;
        foreach ($paths as $abs => $ext) {
            $size = @filesize($abs);
            if ($size === false || $size <= 0 || $size > self::MAX_INLINE_BYTES) {
                continue;
            }
            if ($total + $size > self::MAX_INLINE_BYTES) {
                break;
            }
            $bytes = @file_get_contents($abs);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $inline[] = [
                'mime' => $this->mimeForExt($ext),
                'data' => base64_encode($bytes),
            ];
            $total += $size;
        }
        if (count($inline) === 0) {
            return '';
        }

        try {
            $raw = $this->gemini->generateWithInlineFiles(self::OCR_PROMPT, $inline, [
                'temperature' => 0.05,
                'maxOutputTokens' => 4096,
            ]);
            $clean = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
            return $clean;
        } catch (Throwable $e) {
            error_log('CreativeSearchIndexer::extractMediaText: ' . $e->getMessage());
            return '';
        }
    }

    private function mimeForExt(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function extractStoredOcr(string $searchText): string
    {
        $pos = strpos($searchText, self::BODY_SEP);
        if ($pos === false) {
            return '';
        }
        return trim(substr($searchText, $pos + strlen(self::BODY_SEP)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAssetRow(string $assetId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT a.*,
                    u.username AS creator_name,
                    p.name AS project_name,
                    f.name AS folder_name
             FROM creative_assets a
             LEFT JOIN users u ON u.id = a.creator_id
             LEFT JOIN projects p ON p.id = a.project_id
             LEFT JOIN creative_folders f ON f.id = a.folder_id
             WHERE a.id = ?
             LIMIT 1"
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
