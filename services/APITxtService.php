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
    private string $waNumber;

    /** Base URL for chat (free-form) messages */
    private const CHAT_URL = 'https://apitxt.com/api/whatsapp_chat';

    /** Base URL for broadcast (template) messages */
    private const BROADCAST_URL = 'https://apitxt.com/api/WhatsApp';

    public function __construct()
    {
        // 1. Try the Environment helper (.env parser)
        if (class_exists('Environment')) {
            $this->authKey  = Environment::get('APITXT_AUTH_KEY', '');
            $this->waNumber = Environment::get('APITXT_WA_NUMBER', '');
        } else {
            $this->authKey  = '';
            $this->waNumber = '';
        }

        // 2. Fall back to $_ENV / $_SERVER / getenv()
        if ($this->authKey === '') {
            $this->authKey = $_ENV['APITXT_AUTH_KEY'] ?? $_SERVER['APITXT_AUTH_KEY'] ?? getenv('APITXT_AUTH_KEY') ?: '';
        }
        if ($this->waNumber === '') {
            $this->waNumber = $_ENV['APITXT_WA_NUMBER'] ?? $_SERVER['APITXT_WA_NUMBER'] ?? getenv('APITXT_WA_NUMBER') ?: '';
        }

        // APITxt expects wa_number/mobile as digits-only.
        $this->waNumber = preg_replace('/\D+/', '', (string)$this->waNumber);

        // 3. Last resort: parse .env file directly
        if ($this->authKey === '' || $this->waNumber === '') {
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
                    if ($k === 'APITXT_WA_NUMBER' && $this->waNumber === '') $this->waNumber = $v;
                }
                if ($this->authKey !== '' && $this->waNumber !== '') break;
            }
        }
    }

    /** Whether the service has been configured via .env */
    public function isConfigured(): bool
    {
        return $this->authKey !== '' && $this->waNumber !== '';
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
            error_log('[APITxtService] Not configured — missing authKey or waNumber');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $cleanPhone = preg_replace('/\D+/', '', (string)$to);

        // Build query safely to preserve emojis/spaces/newlines.
        // Note: APITxt docs use `mobile`, but we also send `phone` for
        // compatibility with some gateway configurations.
        $params = [
            'authkey'   => $this->authKey,
            'wa_number' => $this->waNumber,
            'body_type' => 'text',
            'mobile'    => $cleanPhone,
            'phone'     => $cleanPhone,
            'meta'      => $text,
        ];

        $url = self::CHAT_URL . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET        => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
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
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log("APITxt sendText to {$cleanPhone} [HTTP {$httpCode}]: " . $response . ($curlErr ? " (err: {$curlErr})" : ''));

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : ['success' => false, 'raw' => $response, 'http_code' => $httpCode];
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
        // Build a text fallback that clearly shows the options when the
        // interactive button format is unsupported by the account tier.
        $optionLines = implode("\n", array_map(
            fn($b, $i) => ($i + 1) . '. ' . $b['title'],
            array_slice($buttons, 0, 3),
            range(0, 2)
        ));

        $fullText = ($header !== '' ? "*{$header}*\n\n" : '')
            . $body
            . "\n\n{$optionLines}"
            . ($footer !== '' ? "\n\n_{$footer}_" : '');

        return $this->sendText($to, $fullText);
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
                $lines[] = "{$idx}. {$row['title']}" . (!empty($row['description']) ? " — {$row['description']}" : '');
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
        string $language   = 'en'
    ): array {
        $mobile = $this->normalisePhone($to);

        $params = [
            'authkey'       => $this->authKey,
            'wa_number'     => $this->waNumber,
            'mobile'        => $mobile,
            'template_name' => $templateName,
        ];

        // Map body_params to body_1, body_2, …
        foreach (array_values($bodyParams) as $i => $value) {
            $params['body_' . ($i + 1)] = (string) $value;
        }

        // Map url_button_0 → web_url_1, url_button_1 → web_url_2, …
        foreach ($urlButtons as $key => $value) {
            // Extract trailing integer from key (e.g. 'url_button_0' → 0)
            preg_match('/(\d+)$/', $key, $m);
            $n = isset($m[1]) ? ((int)$m[1] + 1) : 1;
            $params['web_url_' . $n] = (string) $value;
        }

        return $this->get(self::BROADCAST_URL, $params);
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
            error_log('[APITxtService] Not configured — set APITXT_AUTH_KEY and APITXT_WA_NUMBER in .env');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $fullUrl = $url . '?' . http_build_query($params);
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
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
