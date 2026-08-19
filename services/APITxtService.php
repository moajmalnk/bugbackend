<?php
/**
 * APITxtService — Wrapper for the APITxt WhatsApp Cloud API.
 *
 * Why this exists: BugRicer uses APITxt (not the Meta Business API directly)
 * so that we get a managed webhook + template-approval layer. This class
 * centralises all outbound calls so the rest of the codebase never sees raw
 * cURL or API keys.
 *
 * Required .env keys:
 *   APITXT_AUTH_KEY        — your APITxt authentication key
 *   APITXT_PROJECT_REF_ID  — the project_ref_id for your APITxt number
 *   APITXT_BASE_URL        — (optional) default: https://api.apitxt.com/v1
 *   APP_BASE_URL           — public base URL of BugRicer (e.g. https://bugs.bugricer.com)
 */

require_once __DIR__ . '/../config/environment.php';

class APITxtService
{
    private string $authKey;
    private string $projectRefId;
    private string $baseUrl;

    public function __construct()
    {
        $this->authKey      = Environment::get('APITXT_AUTH_KEY', '');
        $this->projectRefId = Environment::get('APITXT_PROJECT_REF_ID', '');
        $this->baseUrl      = rtrim(
            Environment::get('APITXT_BASE_URL', 'https://api.apitxt.com/v1'),
            '/'
        );
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
     * Send a plain text WhatsApp message.
     *
     * @param string $to   Phone in E.164 format (digits only, no +)
     * @param string $text Message body
     */
    public function sendText(string $to, string $text): array
    {
        return $this->post('/messages/send-text', [
            'project_ref_id' => $this->projectRefId,
            'to'             => $to,
            'text'           => ['body' => $text],
        ]);
    }

    /**
     * Send a message with up to 3 quick-reply buttons.
     *
     * @param string $to
     * @param string $header Plain-text header line
     * @param string $body   Message body
     * @param array  $buttons [['id' => string, 'title' => string], …]  (max 3)
     * @param string $footer Optional footer line
     */
    public function sendInteractiveButtons(
        string $to,
        string $header,
        string $body,
        array $buttons,
        string $footer = ''
    ): array {
        $payload = [
            'project_ref_id' => $this->projectRefId,
            'to'             => $to,
            'interactive'    => [
                'type' => 'button',
                'header' => ['type' => 'text', 'text' => $header],
                'body'   => ['text' => $body],
                'action' => [
                    'buttons' => array_map(fn($b) => [
                        'type'  => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => $b['title']],
                    ], array_slice($buttons, 0, 3)),
                ],
            ],
        ];
        if ($footer !== '') {
            $payload['interactive']['footer'] = ['text' => $footer];
        }
        return $this->post('/messages/send-interactive', $payload);
    }

    /**
     * Send a list-menu interactive message (supports up to 10 items per section).
     *
     * @param string $to
     * @param string $header
     * @param string $body
     * @param string $buttonText Label on the list-open button
     * @param array  $sections   [[
     *                   'title' => string,
     *                   'rows'  => [['id'=>string,'title'=>string,'description'=>string], …]
     *                ]]
     */
    public function sendListMenu(
        string $to,
        string $header,
        string $body,
        string $buttonText,
        array  $sections,
        string $footer = ''
    ): array {
        $payload = [
            'project_ref_id' => $this->projectRefId,
            'to'             => $to,
            'interactive'    => [
                'type'   => 'list',
                'header' => ['type' => 'text', 'text' => $header],
                'body'   => ['text' => $body],
                'action' => [
                    'button'   => $buttonText,
                    'sections' => $sections,
                ],
            ],
        ];
        if ($footer !== '') {
            $payload['interactive']['footer'] = ['text' => $footer];
        }
        return $this->post('/messages/send-interactive', $payload);
    }

    /**
     * Send an approved WhatsApp template message.
     *
     * Template: bug_update
     *   body_params : {{1}} recipient name, {{2}} ticket id, {{3}} project name,
     *                 {{4}} issue title, {{5}} status label
     *   url_buttons : url_button_0 = bugId  (dynamic suffix for the ticket deep-link)
     *
     * @param string $to
     * @param string $templateName  e.g. 'bug_update'
     * @param array  $bodyParams    Ordered list of parameter values for {{1}}…{{n}}
     * @param array  $urlButtons    Associative: ['url_button_0' => value, …]
     * @param string $language      BCP-47 language code (default 'en')
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        array  $bodyParams,
        array  $urlButtons = [],
        string $language   = 'en'
    ): array {
        $components = [];

        // Body component
        if (!empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn($v) => ['type' => 'text', 'text' => (string) $v],
                    $bodyParams
                ),
            ];
        }

        // Button components (url type with dynamic suffix)
        foreach ($urlButtons as $idx => $value) {
            $buttonIdx = (int) filter_var($idx, FILTER_SANITIZE_NUMBER_INT);
            $components[] = [
                'type'      => 'button',
                'sub_type'  => 'url',
                'index'     => $buttonIdx,
                'parameters' => [
                    ['type' => 'text', 'text' => (string) $value],
                ],
            ];
        }

        return $this->post('/messages/send-template', [
            'project_ref_id' => $this->projectRefId,
            'to'             => $to,
            'template'       => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ]);
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
     * @param string $extHint   Fallback extension if MIME lookup fails (e.g. 'ogg')
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
            'path' => 'wa_staging/' . $filename,   // relative to uploads/
            'name' => $filename,
            'mime' => $this->normaliseMime($mimeType),
        ];
    }

    /**
     * Download a media file directly into the per-bug attachments directory.
     * Call this after you have a bug ID.
     *
     * @param string $mediaUrl
     * @param string $mimeType
     * @param string $bugId
     * @param string $extHint
     * @return array{path: string, name: string, mime: string}|null
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
     * Execute a POST request to the APITxt API.
     *
     * @return array Decoded JSON response
     */
    private function post(string $endpoint, array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'APITxtService not configured'];
        }

        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'authkey: ' . $this->authKey,
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => $err];
        }

        $decoded = json_decode($raw, true) ?? [];
        $decoded['http_code'] = $code;
        return $decoded;
    }

    /**
     * Download a URL to a local file path using cURL.
     * Returns number of bytes written or false on failure.
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
            ],
        ]);

        $ok  = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $err) {
            @unlink($localPath);
            return false;
        }

        return filesize($localPath) ?: 0;
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
