<?php
/**
 * Why: Per-row DEK so a leaked assets_vault_secrets dump is useless without ASSETS_VAULT_KEK.
 * Envelope: AES-256-GCM(secret, DEK) + AES-256-GCM(DEK, KEK). Never log plaintext.
 */

require_once __DIR__ . '/../config/environment.php';

function assetVaultKek(): string
{
    if (!class_exists('Environment')) {
        Environment::load();
    } else {
        Environment::load();
    }
    $raw = (string) (getenv('ASSETS_VAULT_KEK') ?: (Environment::get('ASSETS_VAULT_KEK') ?? ''));
    $raw = trim($raw);
    if ($raw === '' || strpos($raw, 'change-me') !== false) {
        throw new RuntimeException('ASSETS_VAULT_KEK is not configured');
    }
    if (preg_match('/^[0-9a-fA-F]{64}$/', $raw)) {
        return hex2bin($raw);
    }
    return hash('sha256', $raw, true);
}

/**
 * @return array{ciphertext: string, nonce: string, dek_wrapped: string, wrap_nonce: string}
 */
function assetVaultSeal(string $plaintext): array
{
    $kek = assetVaultKek();
    $dek = random_bytes(32);
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false || $tag === '') {
        throw new RuntimeException('Failed to seal vault secret');
    }
    $wrapNonce = random_bytes(12);
    $wrapTag = '';
    $wrapped = openssl_encrypt($dek, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $wrapNonce, $wrapTag);
    if ($wrapped === false || $wrapTag === '') {
        throw new RuntimeException('Failed to wrap vault DEK');
    }
    return [
        'ciphertext' => $cipher . $tag,
        'nonce' => $nonce,
        'dek_wrapped' => $wrapped . $wrapTag,
        'wrap_nonce' => $wrapNonce,
    ];
}

function assetVaultOpen(string $ciphertext, string $nonce, string $dekWrapped, string $wrapNonce): string
{
    $kek = assetVaultKek();
    if (strlen($dekWrapped) < 16 || strlen($ciphertext) < 16) {
        throw new RuntimeException('Corrupt vault blob');
    }
    $wrapTag = substr($dekWrapped, -16);
    $wrapped = substr($dekWrapped, 0, -16);
    $dek = openssl_decrypt($wrapped, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $wrapNonce, $wrapTag);
    if ($dek === false) {
        throw new RuntimeException('Failed to unwrap vault DEK');
    }
    $tag = substr($ciphertext, -16);
    $cipher = substr($ciphertext, 0, -16);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        throw new RuntimeException('Failed to open vault secret');
    }
    return $plain;
}

function assetVaultFingerprint(string $plaintext, string $kind): string
{
    if ($kind === 'ssh_private_key') {
        $hash = substr(hash('sha256', $plaintext), 0, 16);
        return $hash;
    }
    $len = strlen($plaintext);
    $tail = $len >= 4 ? substr($plaintext, -4) : str_repeat('*', 4);
    return substr(hash('sha256', $kind . ':' . $len . ':' . $tail), 0, 16);
}
