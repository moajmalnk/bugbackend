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
            . ':generateContent?key=' . rawurlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 90,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
