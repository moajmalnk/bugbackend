<?php
/**
 * Why: Billing fields are stored generated (margin) — never trust client-sent margin_amount.
 * Shared sanitizers keep FQDN/IP/IMEI within DB column limits.
 */

/**
 * @return list<string>
 */
function assetBillingWritableColumns(): array
{
    return [
        'vendor',
        'vendor_account',
        'billing_cycle',
        'currency',
        'vendor_cost',
        'client_charge',
        'invoice_status',
        'auto_renew',
        'purchased_at',
        'expires_at',
        'status',
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function assetExtractBilling(array $data, bool $includeStatus = true): array
{
    $out = [];
    $cycles = ['monthly', 'yearly', 'biennial', 'one_time'];
    $invoices = ['not_billed', 'invoiced', 'paid', 'waived'];

    if (array_key_exists('vendor', $data)) {
        $out['vendor'] = assetNullableString($data['vendor'], 100);
    }
    if (array_key_exists('vendor_account', $data)) {
        $out['vendor_account'] = assetNullableString($data['vendor_account'], 150);
    }
    if (array_key_exists('billing_cycle', $data)) {
        $cycle = strtolower(trim((string) $data['billing_cycle']));
        $out['billing_cycle'] = in_array($cycle, $cycles, true) ? $cycle : 'yearly';
    }
    if (array_key_exists('currency', $data)) {
        $cur = strtoupper(preg_replace('/[^A-Z]/', '', (string) $data['currency']));
        $out['currency'] = $cur !== '' ? substr($cur, 0, 3) : 'INR';
    }
    if (array_key_exists('vendor_cost', $data)) {
        $out['vendor_cost'] = assetMoney($data['vendor_cost']);
    }
    if (array_key_exists('client_charge', $data)) {
        $out['client_charge'] = assetMoney($data['client_charge']);
    }
    if (array_key_exists('invoice_status', $data)) {
        $inv = strtolower(trim((string) $data['invoice_status']));
        $out['invoice_status'] = in_array($inv, $invoices, true) ? $inv : 'not_billed';
    }
    if (array_key_exists('auto_renew', $data)) {
        $out['auto_renew'] = assetBool($data['auto_renew']) ? 1 : 0;
    }
    if (array_key_exists('purchased_at', $data)) {
        $out['purchased_at'] = assetDateOrNull($data['purchased_at']);
    }
    if (array_key_exists('expires_at', $data)) {
        $out['expires_at'] = assetDateOrNull($data['expires_at']);
    }
    if ($includeStatus && array_key_exists('status', $data)) {
        $out['status'] = assetNullableString($data['status'], 32);
    }
    unset($out['margin_amount']);
    return $out;
}

/**
 * @param mixed $value
 */
function assetMoney($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return number_format((float) $value, 2, '.', '');
}

/**
 * @param mixed $value
 */
function assetBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    $v = strtolower(trim((string) $value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @param mixed $value
 */
function assetDateOrNull($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $raw = trim((string) $value);
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if ($dt && $dt->format('Y-m-d') === $raw) {
        return $raw;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * @param mixed $value
 */
function assetNullableString($value, int $max): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return substr($s, 0, $max);
}

function assetSanitizeFqdn(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = strtolower(trim($raw));
    $s = preg_replace('#^https?://#', '', $s);
    $s = preg_replace('#/.*$#', '', $s);
    $s = rtrim($s, '.');
    $s = preg_replace('/\s+/', '', $s);
    if ($s === '' || strlen($s) > 255) {
        return null;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$|^[a-z0-9]$/', $s)) {
        return null;
    }
    return $s;
}

function assetSanitizeHost(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = strtolower(trim($raw));
    if ($s === '@' || $s === '') {
        return '@';
    }
    $s = preg_replace('/\s+/', '', $s);
    if (strlen($s) > 255 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $s)) {
        return null;
    }
    return $s;
}

function assetBuildFqdn(string $host, string $apex): string
{
    if ($host === '@' || $host === '') {
        return $apex;
    }
    return $host . '.' . $apex;
}

function assetSanitizeIpv4(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $s = trim($raw);
    return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $s : null;
}

function assetSanitizeIpv6(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $s = trim($raw);
    return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $s : null;
}

function assetClampImei(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $digits = preg_replace('/\D/', '', (string) $raw);
    if ($digits === '') {
        return null;
    }
    return substr($digits, 0, 15);
}

function assetStripFinanceKeys(array $row): array
{
    unset($row['vendor_cost'], $row['client_charge'], $row['margin_amount'], $row['vendor_account']);
    return $row;
}

/**
 * Why: Sequential codes (CLT-0001 / HW-0001) stay human-readable beside UUID PKs.
 */
function assetNextSequentialCode(PDO $conn, string $table, string $column, string $prefix, int $pad = 4): string
{
    $like = $prefix . '%';
    $stmt = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING({$column}, ?) AS UNSIGNED)) FROM `{$table}` WHERE {$column} LIKE ?"
    );
    $stmt->execute([strlen($prefix) + 1, $like]);
    $max = (int) $stmt->fetchColumn();
    return $prefix . str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT);
}

function assetNextClientCode(PDO $conn): string
{
    return assetNextSequentialCode($conn, 'clients', 'client_code', 'CLT-');
}

function assetNextHardwareTag(PDO $conn): string
{
    return assetNextSequentialCode($conn, 'assets_hardware', 'asset_tag', 'HW-');
}
