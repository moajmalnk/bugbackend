<?php
require_once __DIR__ . '/AssetsAuth.php';
require_once __DIR__ . '/../../utils/asset_renewals.php';

class AssetsRenewalsController extends AssetsAuth
{
    public function listUpcoming(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $days = (int) ($_GET['days'] ?? 30);
        if ($days < 1) {
            $days = 30;
        }
        if ($days > 120) {
            $days = 120;
        }
        if (!$this->tableReady('assets_domains')) {
            $this->sendJsonResponse(200, 'OK', ['items' => [], 'total' => 0]);
            return;
        }
        $rows = assetRenewalExpiringRows($this->conn);
        $today = new DateTime('today', new DateTimeZone('Asia/Kolkata'));
        $items = [];
        foreach ($rows as $row) {
            $exp = (string) ($row['expires_at'] ?? '');
            if ($exp === '') {
                continue;
            }
            $expDt = DateTime::createFromFormat('Y-m-d', substr($exp, 0, 10), new DateTimeZone('Asia/Kolkata'));
            if (!$expDt) {
                continue;
            }
            $diff = (int) $today->diff($expDt)->format('%r%a');
            if ($diff < 0 || $diff > $days) {
                continue;
            }
            $item = $row;
            $item['days_left'] = $diff;
            if (!$this->canFinance()) {
                $item = assetStripFinanceKeys($item);
            }
            $items[] = $item;
        }
        usort($items, function ($a, $b) {
            return ((int) $a['days_left']) <=> ((int) $b['days_left']);
        });
        $this->sendJsonResponse(200, 'OK', ['items' => $items, 'total' => count($items)]);
    }
}
