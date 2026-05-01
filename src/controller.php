<?php

require_once __DIR__ . '/modules/bootstrap.php';
require_once __DIR__ . '/modules/kubernetes.php';
require_once __DIR__ . '/modules/record_collector.php';
require_once __DIR__ . '/modules/db_sync.php';
require_once __DIR__ . '/modules/pihole_sync.php';
require_once __DIR__ . '/modules/pihole_reconciler.php';
require_once __DIR__ . '/modules/sync_audit.php';

$config = loadConfig();
$pdo = createPdo($config);
$token = getKubernetesToken($config['token_file']);

ensureSyncAuditTables($pdo);

function runAuxSyncScript(string $scriptName): array
{
    $scriptPath = __DIR__ . '/' . $scriptName;
    if (!is_file($scriptPath)) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'output' => 'Script not found: ' . $scriptPath,
        ];
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $lines)),
    ];
}

while (true) {
    $runId = null;
    try {
        $runId = startSyncRun($pdo);
        echo "[" . date('Y-m-d H:i:s') . "] Sync starting...\n";

        $staleAfterSeconds = max($config['interval_seconds'] * 3, 120);
        $records = collectIngressRecords($config['kube_api'], $token, $config['ca_file']);
        $ingressResult = upsertIngressDnsRecords($pdo, $records, $staleAfterSeconds);
        syncManagedDomainsFromIngress($pdo, $records, $staleAfterSeconds);
        logSyncEvents($pdo, $runId, 'ingress', $ingressResult['events']);

        $piholeResult = syncPiholeDnsEntries($pdo, $config);
        logSyncEvents($pdo, $runId, 'pihole_inventory', $piholeResult['events']);
        $reconcileResult = reconcileManagedDomainsInPihole($pdo, $config);
        logSyncEvents($pdo, $runId, 'pihole_reconcile', $reconcileResult['events']);

        $unifiSyncResult = runAuxSyncScript('sync_devices_unifi.php');
        if ($unifiSyncResult['ok']) {
            echo "[" . date('Y-m-d H:i:s') . "] sync_devices_unifi.php completed\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] sync_devices_unifi.php failed (exit=" . $unifiSyncResult['exit_code'] . ")\n";
            if ($unifiSyncResult['output'] !== '') {
                echo $unifiSyncResult['output'] . "\n";
            }
        }

        $starlinkSyncResult = runAuxSyncScript('sync_starlink.php');
        if ($starlinkSyncResult['ok']) {
            echo "[" . date('Y-m-d H:i:s') . "] sync_starlink.php completed\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] sync_starlink.php failed (exit=" . $starlinkSyncResult['exit_code'] . ")\n";
            if ($starlinkSyncResult['output'] !== '') {
                echo $starlinkSyncResult['output'] . "\n";
            }
        }

        echo "[" . date('Y-m-d H:i:s') . "] Ingress processed=" . $ingressResult['processed'] . ", inserted=" . $ingressResult['inserted'] . ", updated=" . $ingressResult['updated'] . ", unchanged=" . $ingressResult['unchanged'] . ", removed=" . $ingressResult['removed'] . "\n";
        if (!empty($ingressResult['stale_candidates'])) {
            echo "[" . date('Y-m-d H:i:s') . "] Ingress stale candidates preserved by grace window: " . implode(', ', $ingressResult['stale_candidates']) . "\n";
        }
        if ($piholeResult['skipped']) {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole sync skipped (missing pihole_url or pihole_pass)\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole entries active=" . $piholeResult['active'] . ", removed=" . $piholeResult['removed'] . "\n";
        }
        if ($reconcileResult['skipped']) {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole reconcile skipped (missing pihole_url or pihole_pass)\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole reconcile inserted=" . $reconcileResult['inserted'] . ", updated=" . $reconcileResult['updated'] . ", removed=" . $reconcileResult['removed'] . "\n";
        }

        finishSyncRun($pdo, $runId, 'success', [
            'ingress_processed' => $ingressResult['processed'],
            'ingress_inserted' => $ingressResult['inserted'],
            'ingress_updated' => $ingressResult['updated'],
            'ingress_unchanged' => $ingressResult['unchanged'],
            'ingress_removed' => $ingressResult['removed'],
            'pihole_active' => $piholeResult['active'],
            'pihole_inserted' => $reconcileResult['inserted'],
            'pihole_updated' => $reconcileResult['updated'],
            'pihole_removed' => $reconcileResult['removed'],
            'pihole_skipped' => $piholeResult['skipped'] || $reconcileResult['skipped'],
            'sync_devices_unifi_ok' => $unifiSyncResult['ok'],
            'sync_devices_unifi_exit_code' => $unifiSyncResult['exit_code'],
            'sync_starlink_ok' => $starlinkSyncResult['ok'],
            'sync_starlink_exit_code' => $starlinkSyncResult['exit_code'],
        ], null);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
        if ($runId !== null) {
            finishSyncRun($pdo, $runId, 'failed', [], $e->getMessage());
        }
    }

    sleep($config['interval_seconds']);
}