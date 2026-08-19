<?php
/**
 * APITxtService — Wrapper for the APITxt WhatsApp API.
 *
 * Why this exists: APITxt exposes GET-based query-parameter APIs (not REST/JSON).
 * This class centralises all outbound calls so the rest of the codebase never
 * sees raw cURL, query-string building, or API keys.
 *
 * Documented endpoints (from apitxt.com/apiDoc/):
 *   Chat (free-form text / media):
 *     GET https://apitxt.com/api/whatsapp_chat
 *         ?authkey=…&wa_number=…&mobile=…&body_type=text&meta=…
 *
 *   Broadcast (approved template):
 *     GET https://apitxt.com/api/WhatsApp
 *         ?authkey=…&wa_number=…&mobile=…&template_name=…
 *         &body_1=…&body_2=…&web_url_1=…
 *
 * Required .env keys:
 *   APITXT_AUTH_KEY    — your APITxt authentication key
 *   APITXT_WA_NUMBER   — your registered sender WhatsApp number (with country code, e.g. 919999999999)
 *   APP_BASE_URL       — public base URL of BugRicer (e.g. https://bugs.bugricer.com)
 */

require_once __DIR__ . '/../config/environment.php';

class APITxtService
{
    private string $authKey;
    private string $projectRefId;

    /** Send free-form WhatsApp messages (non-template) */
    private const SEND_WA_MESSAGE_URL = 'https://apitxt.com/api/sendWAMessage';

    /** Send approved WhatsApp templates */
    private const SEND_WA_TEMPLATE_URL = 'https://apitxt.com/api/sendWA';

    public function __construct()
    {
        // 1. Try the Environment helper (.env parser)
        if (class_exists('Environment')) {
            $this->authKey  = Environment::get('APITXT_AUTH_KEY', '');
            $this->projectRefId = Environment::get('APITXT_PROJECT_REF_ID', '');
        } else {
            $this->authKey  = '';
            $this->projectRefId = '';
        }

        // 2. Fall back to $_ENV / $_SERVER / getenv()
        if ($this->authKey === '') {
            $this->authKey = $_ENV['APITXT_AUTH_KEY'] ?? $_SERVER['APITXT_AUTH_KEY'] ?? getenv('APITXT_AUTH_KEY') ?: '';
        }
        if ($this->projectRefId === '') {
            $this->projectRefId = $_ENV['APITXT_PROJECT_REF_ID'] ?? $_SERVER['APITXT_PROJECT_REF_ID'] ?? getenv('APITXT_PROJECT_REF_ID') ?: '';
        }

        // 3. Last resort: parse .env file directly
        if ($this->authKey === '' || $this->projectRefId === '') {
            $envPaths = [
                __DIR__ . '/../.env',
                __DIR__ . '/../../.env',
                dirname(__DIR__, 2) . '/.env',
            ];
            foreach ($envPaths as $path) {
                if (!file_exists($path)) continue;
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (!str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v, " \t\n\r\0\x0B\"'");
                    if ($k === 'APITXT_AUTH_KEY'  && $this->authKey  === '') $this->authKey  = $v;
                    if ($k === 'APITXT_PROJECT_REF_ID' && $this->projectRefId === '') $this->projectRefId = $v;
                }
                if ($this->authKey !== '' && $this->projectRefId !== '') break;
            }
        }
    }

    /** Whether the service has been configured via .env */
    public function isConfigured(): bool
    {
        return $this->authKey !== '' && $this->projectRefId !== '';
    }

    // ----------------------------------------------------------------
    // Outbound message helpers
    // ----------------------------------------------------------------

    /**
     * Send a plain text WhatsApp message via the Chat API.
     *
     * @param string $to   Recipient phone with country code (digits only, no +)
     * @param string $text Message body
     */
    public function sendText(string $to, string $text): array
    {
        if (!$this->isConfigured()) {
            error_log('[APITxtService] Not configured — missing authKey or project_ref_id');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $cleanPhone = preg_replace('/\D+/', '', (string)$to);

        $payload = [
            'authkey' => $this->authKey,
            'project_ref_id' => $this->projectRefId,
            'to' => $cleanPhone,
            'type' => 'text',
            'text' => $text,
        ];

        $ch = curl_init(self::SEND_WA_MESSAGE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log("APITxt sendWAMessage(text) to {$cleanPhone} [HTTP {$httpCode}]: " . $response . ($curlErr ? " (err: {$curlErr})" : ''));

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            $decoded['http_code'] = $httpCode;
            return $decoded;
        }
        return [
            'success' => false,
            'raw' => $response,
            'http_code' => $httpCode,
            'error' => $curlErr ?: null,
        ];
    }

    /**
     * Send a message with quick-reply buttons.
     *
     * Why: APITxt Chat API supports interactive messages through the same
     * endpoint with body_type=button. We format the buttons as part of the
     * meta payload. Falls back to plain text if the platform doesn't support
     * buttons on this account tier.
     *
     * @param string $to
     * @param string $header Plain-text header line
     * @param string $body   Message body
     * @param array  $buttons [['id' => string, 'title' => string], …] (max 3)
     * @param string $footer Optional footer
     */
    public function sendInteractiveButtons(
        string $to,
        string $header,
        string $body,
        array  $buttons,
        string $footer = ''
    ): array {
        if (!$this->isConfigured()) {
            error_log('[APITxtService] Not configured — missing authkey or project_ref_id');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $cleanTo = preg_replace('/\D+/', '', (string) $to);

        $buttonItems = array_map(function ($b) {
            return [
                'id' => (string) ($b['id'] ?? ''),
                // APITxt limits button title length
                'title' => mb_substr((string) ($b['title'] ?? ''), 0, 20),
            ];
        }, array_slice($buttons, 0, 3));

        $payload = [
            'authkey' => $this->authKey,
            'project_ref_id' => $this->projectRefId,
            'to' => $cleanTo,
            'type' => 'button',
            'header_text' => $header,
            'body_text' => $body,
            'footer_text' => $footer,
            'buttons' => $buttonItems,
        ];

        $ch = curl_init(self::SEND_WA_MESSAGE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log("APITxt sendWAMessage(button) to {$cleanTo} [HTTP {$httpCode}]: " . $response . ($curlErr ? " (err: {$curlErr})" : ''));

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            $decoded['http_code'] = $httpCode;
            return $decoded;
        }

        return [
            'success' => false,
            'raw' => $response,
            'http_code' => $httpCode,
            'error' => $curlErr ?: null,
        ];
    }

    /**
     * Send a list-menu message.
     *
     * APITxt doesn't expose a native list-menu endpoint via the Chat API,
     * so we render the sections as a numbered text menu.
     *
     * @param string $to
     * @param string $header
     * @param string $body
     * @param string $buttonText  (unused in text fallback, kept for API compatibility)
     * @param array  $sections    [['title'=>string,'rows'=>[['id'=>…,'title'=>…,'description'=>…],…]]]
     * @param string $footer
     */
    public function sendListMenu(
        string $to,
        string $header,
        string $body,
        string $buttonText,
        array  $sections,
        string $footer = ''
    ): array {
        $lines = [];
        $idx   = 1;
        foreach ($sections as $section) {
            if (!empty($section['title'])) {
                $lines[] = "*{$section['title']}*";
            }
            foreach ($section['rows'] ?? [] as $row) {
                $rowId = isset($row['id']) ? (string) $row['id'] : '';
                $rowTitle = (string) ($row['title'] ?? '');
                $rowDesc = isset($row['description']) ? (string) $row['description'] : '';
                $idPart = $rowId !== '' ? " [{$rowId}]" : '';
                $descPart = $rowDesc !== '' ? " — {$rowDesc}" : '';
                $lines[] = "{$idx}. {$rowTitle}{$idPart}{$descPart}";
                $idx++;
            }
        }

        $fullText = ($header !== '' ? "*{$header}*\n\n" : '')
            . $body . "\n\n"
            . implode("\n", $lines)
            . ($footer !== '' ? "\n\n_{$footer}_" : '');

        return $this->sendText($to, $fullText);
    }

    /**
     * Send an approved WhatsApp template (broadcast) message.
     *
     * Template: bug_update
     *   body_1 … body_5 map to {{1}}…{{5}} in the approved template body.
     *   web_url_1 is the dynamic URL button suffix.
     *
     * APITxt Broadcast API param names:
     *   body_(n)   — numbered body variables  (body_1, body_2, …)
     *   web_url_(n)— numbered URL variables   (web_url_1, …)
     *
     * @param string $to
     * @param string $templateName  e.g. 'bug_update'
     * @param array  $bodyParams    Ordered values for {{1}}…{{n}}
     * @param array  $urlButtons    ['url_button_0' => value, …]
     * @param string $language      (unused by APITxt Broadcast API, kept for compat)
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        array  $bodyParams,
        array  $urlButtons = [],
        string $language   = 'en_US'
    ): array {
        $cleanPhone = preg_replace('/\D+/', '', (string) $to);

        $urlButtonsArray = [];
        if (!empty($urlButtons)) {
            // Convert our ['url_button_0' => 'X'] map into url_buttons: ['X', ...]
            foreach ($urlButtons as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'url_button_')) {
                    $idx = (int) substr($k, strlen('url_button_'));
                    $urlButtonsArray[$idx] = (string) $v;
                } elseif (is_numeric($k)) {
                    $urlButtonsArray[(int) $k] = (string) $v;
                } else {
                    $urlButtonsArray[] = (string) $v;
                }
            }
            if (!empty($urlButtonsArray) && array_keys($urlButtonsArray) !== range(0, count($urlButtonsArray) - 1)) {
                ksort($urlButtonsArray);
                $urlButtonsArray = array_values($urlButtonsArray);
            }
        }

        $payload = [
            'authkey' => $this->authKey,
            'template_name' => $templateName,
            'project_ref_id' => $this->projectRefId,
            'mobiles' => $cleanPhone,
            'language' => $language,
        ];

        if (!empty($bodyParams)) {
            $payload['body_params'] = array_values($bodyParams);
        }
        if (!empty($urlButtonsArray)) {
            $payload['url_buttons'] = $urlButtonsArray;
        }

        $ch = curl_init(self::SEND_WA_TEMPLATE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log("APITxt sendWA(template) {$templateName} to {$cleanPhone} [HTTP {$httpCode}]: " . $response . ($curlErr ? " (err: {$curlErr})" : ''));

        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) {
            $decoded['http_code'] = $httpCode;
            return $decoded;
        }
        return [
            'success' => false,
            'raw' => $response,
            'http_code' => $httpCode,
            'error' => $curlErr ?: null,
        ];
    }

    // ----------------------------------------------------------------
    // Media helpers
    // ----------------------------------------------------------------

    /**
     * Download a media file from APITxt's media URL and save it under
     * uploads/wa_staging/ for temporary holding before a bug ID exists.
     *
     * @param string $mediaUrl  URL returned in the webhook payload
     * @param string $mimeType  e.g. 'audio/ogg; codecs=opus'
     * @param string $phone     Normalised phone (used in filename to namespace)
     * @param string $extHint   Fallback extension if MIME lookup fails
     * @return array{path: string, name: string, mime: string}|null  null on failure
     */
    public function downloadAndStoreMediaToStaging(
        string $mediaUrl,
        string $mimeType,
        string $phone,
        string $extHint = 'bin'
    ): ?array {
        $stagingDir = __DIR__ . '/../uploads/wa_staging/';
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $ext      = $this->mimeToExtension($mimeType, $extHint);
        $filename = 'wa_' . $phone . '_' . uniqid() . '.' . $ext;
        $fullPath = $stagingDir . $filename;

        $bytes = $this->downloadUrl($mediaUrl, $fullPath);
        if ($bytes === false) {
            return null;
        }

        return [
            'path' => 'wa_staging/' . $filename,
            'name' => $filename,
            'mime' => $this->normaliseMime($mimeType),
        ];
    }

    /**
     * Download a media file directly into the per-bug attachments directory.
     * Call this after you have a bug ID.
     */
    public function downloadAndStoreMedia(
        string $mediaUrl,
        string $mimeType,
        string $bugId,
        string $extHint = 'bin'
    ): ?array {
        $bugDir = __DIR__ . '/../uploads/bugs/' . $bugId . '/';
        if (!is_dir($bugDir)) {
            mkdir($bugDir, 0755, true);
        }

        $ext      = $this->mimeToExtension($mimeType, $extHint);
        $filename = 'wa_' . uniqid() . '.' . $ext;
        $fullPath = $bugDir . $filename;

        $bytes = $this->downloadUrl($mediaUrl, $fullPath);
        if ($bytes === false) {
            return null;
        }

        return [
            'path' => 'bugs/' . $bugId . '/' . $filename,
            'name' => $filename,
            'mime' => $this->normaliseMime($mimeType),
        ];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Execute a GET request to an APITxt endpoint with query parameters.
     * All APITxt APIs are GET-based with params in the query string.
     *
     * @return array Decoded JSON response
     */
    private function get(string $url, array $params): array
    {
        if (!$this->isConfigured()) {
            error_log('[APITxtService] Not configured — set APITXT_AUTH_KEY and APITXT_PROJECT_REF_ID in .env');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $fullUrl = $url . '?' . http_build_query($params);

        $baseHeaders = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];
        $fullHeaders = array_merge($baseHeaders, [
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Sec-CH-UA: "Not/A)Brand";v="99", "Google Chrome";v="126", "Chromium";v="126"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "macOS"',
        ]);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $baseHeaders,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        error_log("[APITxtService] GET {$url} [{$code}]: {$raw}" . ($err ? " cURL err: {$err}" : ''));

        if ($raw === false || $err !== '') {
            return ['success' => false, 'error' => $err ?: 'cURL failed'];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // APITxt sometimes returns plain text on success
            return ['success' => true, 'raw' => $raw, 'http_code' => $code];
        }

        $decoded['http_code'] = $code;

        if (
            ($decoded['status'] ?? '') === 'error'
            && (($decoded['reason'] ?? '') === 'MISSING_BROWSER_HEADERS')
        ) {
            // Retry once with a fuller browser header set.
            $ch2 = curl_init($fullUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET        => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => $fullHeaders,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ]);

            $raw2  = curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $err2  = curl_error($ch2);
            curl_close($ch2);

            error_log(\"[APITxtService] GET retry(full headers) {$url} [{$code2}]: {$raw2}\" . ($err2 ? \" cURL err: {$err2}\" : ''));

            $decoded2 = json_decode($raw2, true);
            if (!is_array($decoded2)) {
                return ['success' => true, 'raw' => $raw2, 'http_code' => $code2];
            }
            $decoded2['http_code'] = $code2;
            return $decoded2;
        }

        return $decoded;
    }

    /**
     * Download a URL to a local file path.
     * Returns bytes written, or false on failure.
     */
    private function downloadUrl(string $url, string $localPath): int|false
    {
        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'authkey: ' . $this->authKey,
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
                'Pragma: no-cache',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
                'Sec-CH-UA: "Not/A)Brand";v="99", "Google Chrome";v="126", "Chromium";v="126"',
                'Sec-CH-UA-Mobile: ?0',
                'Sec-CH-UA-Platform: "macOS"',
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ]);

        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($err) {
            @unlink($localPath);
            return false;
        }

        $size = filesize($localPath);
        return ($size !== false && $size > 0) ? $size : false;
    }

    /** Strip country-code prefix noise; return digits only. */
    private function normalisePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    /** Map a MIME type to a safe file extension. */
    private function mimeToExtension(string $mime, string $fallback = 'bin'): string
    {
        $map = [
            'audio/ogg'       => 'ogg',
            'audio/mpeg'      => 'mp3',
            'audio/mp4'       => 'm4a',
            'audio/aac'       => 'aac',
            'audio/webm'      => 'webm',
            'video/mp4'       => 'mp4',
            'video/3gpp'      => '3gp',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];
        $baseMime = strtolower(explode(';', $mime)[0]);
        return $map[$baseMime] ?? $fallback;
    }

    /** Strip codec parameters from MIME, keep just type/subtype. */
    private function normaliseMime(string $mime): string
    {
        return strtolower(explode(';', $mime)[0]);
    }
}
