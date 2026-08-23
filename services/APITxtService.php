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
 *   APP_BASE_URL       — frontend base URL (e.g. https://bugs.bugricer.com) for ticket links
 *   API / webhook host — https://bugbackend.bugricer.com (never the SPA host for webhooks)
 */

require_once __DIR__ . '/../config/environment.php';

class APITxtService
{
    private string $authKey;
    private string $projectRefId;
    private string $waNumber;

    /** Send free-form WhatsApp messages (non-template) */
    private const SEND_WA_MESSAGE_URL = 'https://apitxt.com/api/sendWAMessage';

    /** Send approved WhatsApp templates */
    private const SEND_WA_TEMPLATE_URL = 'https://apitxt.com/api/sendWA';
    /** Legacy APITxt chat endpoint (GET query params) */
    private const LEGACY_CHAT_URL = 'https://apitxt.com/api/whatsapp_chat';

    public function __construct()
    {
        // 1. Try the Environment helper (.env parser)
        if (class_exists('Environment')) {
            $this->authKey  = Environment::get('APITXT_AUTH_KEY', '');
            $this->projectRefId = Environment::get('APITXT_PROJECT_REF_ID', '');
            $this->waNumber = Environment::get('APITXT_WA_NUMBER', '');
        } else {
            $this->authKey  = '';
            $this->projectRefId = '';
            $this->waNumber = '';
        }

        // 2. Fall back to $_ENV / $_SERVER / getenv()
        if ($this->authKey === '') {
            $this->authKey = $_ENV['APITXT_AUTH_KEY'] ?? $_SERVER['APITXT_AUTH_KEY'] ?? getenv('APITXT_AUTH_KEY') ?: '';
        }
        if ($this->projectRefId === '') {
            $this->projectRefId = $_ENV['APITXT_PROJECT_REF_ID'] ?? $_SERVER['APITXT_PROJECT_REF_ID'] ?? getenv('APITXT_PROJECT_REF_ID') ?: '';
        }
        if ($this->waNumber === '') {
            $this->waNumber = $_ENV['APITXT_WA_NUMBER'] ?? $_SERVER['APITXT_WA_NUMBER'] ?? getenv('APITXT_WA_NUMBER') ?: '';
        }

        // 3. Last resort: parse .env file directly
        if ($this->authKey === '' || ($this->projectRefId === '' && $this->waNumber === '')) {
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
                    if ($k === 'APITXT_WA_NUMBER' && $this->waNumber === '') $this->waNumber = $v;
                }
                if ($this->authKey !== '' && ($this->projectRefId !== '' || $this->waNumber !== '')) break;
            }
        }
    }

    /** Whether the service has been configured via .env */
    public function isConfigured(): bool
    {
        return $this->authKey !== '' && ($this->projectRefId !== '' || $this->waNumber !== '');
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
            error_log('[APITxtService] Not configured — missing authKey and/or sender config');
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $cleanPhone = preg_replace('/\D+/', '', (string)$to);

        $payload = [
            'authkey' => $this->authKey,
            'project_ref_id' => $this->projectRefId,
            'to' => $cleanPhone,
            'phone' => $cleanPhone,
            'mobile' => $cleanPhone,
            'type' => 'text',
            'message' => $text,
            // Keep both forms for APITxt compatibility across account modes.
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
        $postResult = [
            'success' => false,
            'raw' => $response,
            'http_code' => $httpCode,
            'error' => $curlErr ?: null,
        ];
        if (is_array($decoded)) {
            $decoded['http_code'] = $httpCode;
            $postResult = $decoded;
        }

        $statusField = $postResult['status'] ?? null;
        $statusCodeFromBody = is_numeric($statusField) ? (int) $statusField : null;
        $statusStringFromBody = is_string($statusField) ? strtolower(trim($statusField)) : null;

        $bodySignalsFailure = (
            (($postResult['success'] ?? null) === false)
            || ($statusStringFromBody === 'error')
            || ($statusCodeFromBody !== null && $statusCodeFromBody !== 200)
        );

        $isPostSuccess = $httpCode >= 200
            && $httpCode < 300
            && !$bodySignalsFailure;

        if ($isPostSuccess) {
            return $postResult;
        }

        // Compatibility fallback for accounts still wired to legacy GET chat API.
        if ($this->waNumber === '') {
            error_log('[APITxtService] sendText POST failed and APITXT_WA_NUMBER missing, cannot fallback to legacy endpoint');
            return $postResult;
        }

        $legacy = $this->get(self::LEGACY_CHAT_URL, [
            'authkey' => $this->authKey,
            'wa_number' => $this->waNumber,
            'mobile' => $cleanPhone,
            'body_type' => 'text',
            'meta' => $text,
        ]);
        $legacy['transport'] = 'legacy_get_chat';
        $legacy['post_attempt_http_code'] = $httpCode;
        return $legacy;
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
                // Keep menu simple for users: "1. Project Name" only.
                $rowTitle = (string) ($row['title'] ?? '');
                $lines[] = "{$idx}. {$rowTitle}";
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
     * Resolve inbound WhatsApp media into staging.
     *
     * Why: Webhooks must finish in a few seconds. Long sleep/retry loops caused
     * Hostinger/APITxt to kill the request — user saw silence on photos/voice.
     *
     * @return array{path: string, name: string, mime: string}|null
     */
    public function downloadAndStoreMediaToStaging(
        string $mediaUrl,
        string $mimeType,
        string $phone,
        string $extHint = 'bin',
        ?string $mediaId = null,
        ?string $wamid = null
    ): ?array {
        $resolvedUrl = $this->normaliseApiTxtMediaUrl(trim($mediaUrl));
        $resolvedMime = $mimeType;
        $wamid = $this->extractWamid($wamid ?: $mediaId ?: $resolvedUrl);

        // 1) Fast path: APITxt wa-media by wamid (one JSON probe, then download).
        if ($wamid !== null) {
            $apitxt = $this->resolveApiTxtMedia($wamid);
            if ($apitxt !== null) {
                if (!empty($apitxt['url'])) {
                    $resolvedUrl = $apitxt['url'];
                }
                if (!empty($apitxt['mime'])) {
                    $resolvedMime = $apitxt['mime'];
                }
                if (!empty($apitxt['filename']) && $extHint === 'bin') {
                    $pathExt = pathinfo($apitxt['filename'], PATHINFO_EXTENSION);
                    if (is_string($pathExt) && $pathExt !== '') {
                        $extHint = strtolower($pathExt);
                    }
                }
            }
            if ($resolvedUrl === '') {
                $resolvedUrl = $this->buildApiTxtMediaUrl($wamid, false);
            }
        }

        // 2) Meta Cloud fallback when we only have a numeric media object id.
        if ($resolvedUrl === '' && $mediaId && !$this->looksLikeWamid($mediaId)) {
            $resolved = $this->resolveMetaMediaUrl($mediaId);
            if ($resolved !== null) {
                $resolvedUrl = $resolved['url'];
                if (!empty($resolved['mime'])) {
                    $resolvedMime = $resolved['mime'];
                }
            }
        }

        if ($resolvedUrl === '') {
            error_log('[APITxtService] Staging download skipped — empty media URL / unresolved wamid+media id');
            return null;
        }

        $stagingDir = __DIR__ . '/../uploads/wa_staging/';
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $ext      = $this->mimeToExtension($resolvedMime, $extHint);
        $filename = 'wa_' . $phone . '_' . uniqid() . '.' . $ext;
        $fullPath = $stagingDir . $filename;

        $bearer = $this->urlNeedsMetaBearer($resolvedUrl) ? $this->cloudAccessToken() : '';
        $bytes = $this->downloadUrl($resolvedUrl, $fullPath, $bearer);
        if ($bytes === false && $wamid !== null) {
            // Docs: rare race — wait briefly once, then retry redirect URL.
            usleep(800000);
            $retryUrl = $this->buildApiTxtMediaUrl($wamid, false);
            if ($retryUrl !== '') {
                $bytes = $this->downloadUrl($retryUrl, $fullPath, '');
            }
        }
        if ($bytes === false) {
            $this->persistMediaDownloadDebug([
                'ok' => false,
                'at' => date('c'),
                'wamid' => $wamid ? mb_substr($wamid, 0, 80) : null,
                'mediaId' => $mediaId ? mb_substr($mediaId, 0, 80) : null,
                'triedUrl' => mb_substr($resolvedUrl, 0, 180),
                'mime' => $resolvedMime,
            ]);
            return null;
        }

        $this->persistMediaDownloadDebug([
            'ok' => true,
            'at' => date('c'),
            'wamid' => $wamid ? mb_substr($wamid, 0, 80) : null,
            'bytes' => $bytes,
            'mime' => $this->normaliseMime($resolvedMime),
        ]);

        return [
            'path' => 'wa_staging/' . $filename,
            'name' => $filename,
            'mime' => $this->normaliseMime($resolvedMime),
        ];
    }

    /** @param array<string,mixed> $info */
    private function persistMediaDownloadDebug(array $info): void
    {
        $dir = __DIR__ . '/../uploads/wa_staging/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . 'last_media_download.json',
            json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Replace YOUR_AUTH_KEY placeholder in APITxt media_url templates.
     */
    private function normaliseApiTxtMediaUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($this->authKey !== '' && str_contains($url, 'YOUR_AUTH_KEY')) {
            $url = str_replace('YOUR_AUTH_KEY', $this->authKey, $url);
        }
        return $url;
    }

    private function looksLikeWamid(string $id): bool
    {
        return (bool) preg_match('/^wamid\./i', trim($id));
    }

    private function extractWamid(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if ($this->looksLikeWamid($value)) {
            return $value;
        }
        // From a full media_url path segment.
        if (preg_match('#/(wamid\.[^/?#\s]+)#i', $value, $m)) {
            return $m[1];
        }
        return null;
    }

    private function buildApiTxtMediaUrl(string $wamid, bool $asJson = false): string
    {
        if ($this->authKey === '' || $wamid === '') {
            return '';
        }
        // Path form from APITxt docs — do not encode authkey= segment separators.
        $url = 'https://apitxt.com/api/wa-media/authkey='
            . $this->authKey
            . '/'
            . $wamid;
        if ($asJson) {
            $url .= '?format=json';
        }
        return $url;
    }

    /**
     * @return array{url?: string, mime?: string, filename?: string}|null
     */
    private function resolveApiTxtMedia(string $wamid): ?array
    {
        $jsonUrl = $this->buildApiTxtMediaUrl($wamid, true);
        if ($jsonUrl === '') {
            return null;
        }

        // One quick probe only — webhook must not sleep/retry for many seconds.
        $ch = curl_init($jsonUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $this->browserHeadersForApiTxt(true),
            CURLOPT_USERAGENT      => $this->browserUserAgent(),
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        error_log(
            '[APITxtService] wa-media json [' . $code . '] wamid='
            . mb_substr($wamid, 0, 48)
            . ($err ? " err={$err}" : '')
            . ' body=' . mb_substr((string) $raw, 0, 180)
        );

        $this->persistMediaDownloadDebug([
            'phase' => 'wa-media-json',
            'at' => date('c'),
            'http' => $code,
            'wamid' => mb_substr($wamid, 0, 80),
            'body' => mb_substr((string) $raw, 0, 400),
            'err' => $err ?: null,
        ]);

        if ($raw === false) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            // Non-JSON success — use redirect download URL.
            if ($code >= 200 && $code < 300) {
                return ['url' => $this->buildApiTxtMediaUrl($wamid, false)];
            }
            return null;
        }

        if ($code >= 200 && $code < 300) {
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
            $url = (string) ($data['url'] ?? '');
            if ($url === '') {
                return ['url' => $this->buildApiTxtMediaUrl($wamid, false)];
            }
            return [
                'url' => $url,
                'mime' => (string) ($data['mime_type'] ?? $data['mime'] ?? ''),
                'filename' => (string) ($data['filename'] ?? ''),
            ];
        }

        // 404/410 — return redirect URL anyway; download may still work once synced.
        if (in_array($code, [404, 410], true)) {
            return ['url' => $this->buildApiTxtMediaUrl($wamid, false)];
        }

        return null;
    }

    /** Headers APITxt WAF expects (same pattern as sendWAMessage). */
    private function browserHeadersForApiTxt(bool $wantJson = false): array
    {
        $accept = $wantJson
            ? 'application/json, text/plain, */*'
            : 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
        return [
            'Accept: ' . $accept,
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Connection: keep-alive',
            'Origin: https://apitxt.com',
            'Referer: https://apitxt.com/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Sec-CH-UA: "Not/A)Brand";v="99", "Google Chrome";v="126", "Chromium";v="126"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "macOS"',
        ];
    }

    private function browserUserAgent(): string
    {
        return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    }

    private function urlNeedsMetaBearer(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        return str_contains($host, 'facebook.com')
            || str_contains($host, 'fbcdn.net')
            || str_contains($host, 'whatsapp.net')
            || str_contains($host, 'fbsbx.com');
    }

    private function isApiTxtHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        return str_contains($host, 'apitxt.com');
    }

    /**
     * Write raw bytes (e.g. webhook base64 media) into staging.
     *
     * @return array{path: string, name: string, mime: string}|null
     */
    public function storeRawMediaToStaging(
        string $binary,
        string $mimeType,
        string $phone,
        string $extHint = 'bin'
    ): ?array {
        if ($binary === '') {
            return null;
        }

        $stagingDir = __DIR__ . '/../uploads/wa_staging/';
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $ext      = $this->mimeToExtension($mimeType, $extHint);
        $filename = 'wa_' . $phone . '_' . uniqid() . '.' . $ext;
        $fullPath = $stagingDir . $filename;

        if (file_put_contents($fullPath, $binary) === false) {
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
            // Treat non-2xx non-JSON responses as failures, not success.
            return [
                'success' => $code >= 200 && $code < 300,
                'raw' => $raw,
                'http_code' => $code,
            ];
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

            error_log("[APITxtService] GET retry(full headers) {$url} [{$code2}]: {$raw2}" . ($err2 ? " cURL err: {$err2}" : ''));

            $decoded2 = json_decode($raw2, true);
            if (!is_array($decoded2)) {
                return [
                    'success' => $code2 >= 200 && $code2 < 300,
                    'raw' => $raw2,
                    'http_code' => $code2,
                ];
            }
            $decoded2['http_code'] = $code2;
            return $decoded2;
        }

        return $decoded;
    }

    /**
     * Resolve Meta Cloud API media id → temporary download URL.
     * Requires WHATSAPP_CLOUD_ACCESS_TOKEN (or META_WA_ACCESS_TOKEN) in .env.
     *
     * @return array{url: string, mime: string}|null
     */
    private function resolveMetaMediaUrl(string $mediaId): ?array
    {
        $token = $this->cloudAccessToken();
        if ($token === '' || $mediaId === '') {
            error_log('[APITxtService] Cannot resolve media id — missing WHATSAPP_CLOUD_ACCESS_TOKEN');
            return null;
        }

        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($mediaId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT      => 'BugRicer-WA-Bot/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        error_log("[APITxtService] Meta media resolve [{$code}] id={$mediaId} " . ($err ?: mb_substr((string) $raw, 0, 200)));

        if ($raw === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['url'])) {
            return null;
        }

        return [
            'url'  => (string) $decoded['url'],
            'mime' => (string) ($decoded['mime_type'] ?? ''),
        ];
    }

    /** Optional Meta/WhatsApp Cloud token for inbound media download. */
    private function cloudAccessToken(): string
    {
        $keys = [
            'WHATSAPP_CLOUD_ACCESS_TOKEN',
            'META_WA_ACCESS_TOKEN',
            'APITXT_WA_ACCESS_TOKEN',
        ];
        foreach ($keys as $key) {
            $val = '';
            if (class_exists('Environment')) {
                $val = (string) Environment::get($key, '');
            }
            if ($val === '') {
                $val = (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: '');
            }
            if ($val !== '') {
                return $val;
            }
        }

        // Last resort: read from backend/.env directly.
        $envPath = __DIR__ . '/../.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if (in_array($k, $keys, true)) {
                    $v = trim($v, " \t\n\r\0\x0B\"'");
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Download a URL to a local file path.
     * Returns bytes written, or false on failure.
     *
     * Why: APITxt wa-media returns 302 → S3. Following that redirect while still
     * sending Origin/Referer for apitxt.com causes S3 403 — resolve Location first,
     * then download the CDN URL with clean headers.
     */
    private function downloadUrl(string $url, string $localPath, string $bearerToken = ''): int|false
    {
        $finalUrl = $url;
        if ($this->isApiTxtHost($url)) {
            $resolved = $this->resolveRedirectLocation($url, $this->browserHeadersForApiTxt(false));
            if (is_string($resolved) && $resolved !== '') {
                $finalUrl = $resolved;
            }
        }

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            return false;
        }

        $isApiTxt = $this->isApiTxtHost($finalUrl);
        $headers = $isApiTxt
            ? $this->browserHeadersForApiTxt(false)
            : [
                'Accept: */*',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
            ];
        // Never send apitxt authkey / Origin to S3 or Meta CDNs (causes 403).
        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        $ch = curl_init($finalUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $this->browserUserAgent(),
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($err || $code < 200 || $code >= 300) {
            error_log(
                "[APITxtService] Media download failed http={$code} err={$err} url="
                . mb_substr($finalUrl, 0, 120)
            );
            @unlink($localPath);
            return false;
        }

        $size = filesize($localPath);
        if ($size === false || $size <= 0) {
            @unlink($localPath);
            return false;
        }

        // Guard against HTML/JSON error bodies saved as "files".
        $head = (string) file_get_contents($localPath, false, null, 0, 64);
        if (preg_match('/^\s*(\{|<|<!DOCTYPE)/i', $head)) {
            error_log('[APITxtService] Media download looked like HTML/JSON error body — discarding');
            @unlink($localPath);
            return false;
        }

        return $size;
    }

    /**
     * Follow one redirect hop without writing a body (for apitxt → CDN).
     * Uses GET (not HEAD) — some wa-media hosts reject HEAD with 405.
     */
    private function resolveRedirectLocation(string $url, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $this->browserUserAgent(),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false && $err) {
            error_log("[APITxtService] wa-media redirect probe failed err={$err}");
            return null;
        }

        if (is_string($redirect) && $redirect !== '' && preg_match('#^https?://#i', $redirect)) {
            return $redirect;
        }
        if (is_string($raw) && preg_match('/^Location:\s*(\S+)/im', $raw, $m)) {
            $loc = trim($m[1]);
            if ($loc !== '' && preg_match('#^https?://#i', $loc)) {
                return $loc;
            }
        }
        // Direct 200 body on apitxt host — caller can download original URL.
        if ($code >= 200 && $code < 300) {
            return $url;
        }
        error_log(
            "[APITxtService] wa-media redirect unresolved http={$code} url="
            . mb_substr($url, 0, 100)
        );
        return null;
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
