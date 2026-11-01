<?php

require_once __DIR__ . '/../config/gemini.php';

class GeminiService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = GEMINI_API_KEY;
        $this->model = GEMINI_MODEL;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param array<int, array{role: string, text: string}> $turns 'user' or 'model' roles
     * @param array<string, mixed> $generationConfig
     * @return string Raw text from the model (may be JSON)
     * @throws Exception
     */
    public function generateFromTurns(array $turns, array $generationConfig = []): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('Gemini API is not configured (set GEMINI_API_KEY)');
        }

        $contents = [];
        foreach ($turns as $t) {
            $role = $t['role'] === 'model' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string)$t['text']]],
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => array_merge([
                'temperature' => 0.4,
                'maxOutputTokens' => 8192,
            ], $generationConfig),
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model)
            . ':generateContent';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            // Key in a header, not the query string, so it never lands in proxy/access logs.
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 90,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($errno) {
            throw new Exception('Gemini request failed: ' . $err);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new Exception('Gemini invalid response');
        }

        if ($code >= 400) {
            $msg = $json['error']['message'] ?? $raw ?? 'HTTP ' . $code;
            throw new Exception('Gemini API error: ' . $msg);
        }

        $text = '';
        if (!empty($json['candidates'][0]['content']['parts'])) {
            foreach ($json['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        if ($text === '') {
            throw new Exception('Empty response from Gemini');
        }

        return $text;
    }

    /**
     * Why: BugCreative search indexes OCR / PDF body text from uploaded media.
     * Inline base64 keeps the pipeline self-contained (no Files API upload step).
     *
     * @param array<int, array{mime: string, data: string}> $inlineFiles base64 payloads
     * @param array<string, mixed> $generationConfig
     * @throws Exception
     */
    public function generateWithInlineFiles(
        string $prompt,
        array $inlineFiles,
        array $generationConfig = []
    ): string {
        if (!$this->isConfigured()) {
            throw new Exception('Gemini API is not configured (set GEMINI_API_KEY)');
        }
        if (count($inlineFiles) === 0) {
            throw new Exception('At least one inline file is required');
        }

        $parts = [['text' => $prompt]];
        foreach ($inlineFiles as $file) {
            $mime = trim((string)($file['mime'] ?? ''));
            $data = (string)($file['data'] ?? '');
            if ($mime === '' || $data === '') {
                continue;
            }
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => $data,
                ],
            ];
        }
        if (count($parts) < 2) {
            throw new Exception('No valid inline files provided');
        }

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => array_merge([
                'temperature' => 0.1,
                'maxOutputTokens' => 8192,
            ], $generationConfig),
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model)
            . ':generateContent';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode($body),
            // Why: OCR / PDF extract can take longer than copy generation.
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($errno) {
            throw new Exception('Gemini request failed: ' . $err);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new Exception('Gemini invalid response');
        }

        if ($code >= 400) {
            $msg = $json['error']['message'] ?? $raw ?? 'HTTP ' . $code;
            throw new Exception('Gemini API error: ' . $msg);
        }

        $text = '';
        if (!empty($json['candidates'][0]['content']['parts'])) {
            foreach ($json['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        if ($text === '') {
            throw new Exception('Empty response from Gemini');
        }

        return $text;
    }
}
