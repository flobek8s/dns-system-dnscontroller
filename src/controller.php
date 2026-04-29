<?php

require_once __DIR__ . '/modules/bootstrap.php';
require_once __DIR__ . '/modules/kubernetes.php';
require_once __DIR__ . '/modules/record_collector.php';
require_once __DIR__ . '/modules/db_sync.php';
require_once __DIR__ . '/modules/pihole_sync.php';
require_once __DIR__ . '/modules/sync_audit.php';

$config = loadConfig();
$pdo = createPdo($config);
$token = getKubernetesToken($config['token_file']);

ensureSyncAuditTables($pdo);

while (true) {
    $runId = null;
    try {
        $runId = startSyncRun($pdo);
        echo "[" . date('Y-m-d H:i:s') . "] Sync starting...\n";

        $records = collectIngressRecords($config['kube_api'], $token, $config['ca_file']);
        $ingressResult = upsertIngressDnsRecords($pdo, $records);
        syncManagedDomainsFromIngress($pdo, $records);
        logSyncEvents($pdo, $runId, 'ingress', $ingressResult['events']);

        $piholeResult = syncPiholeDnsEntries($pdo, $config);
        logSyncEvents($pdo, $runId, 'pihole', $piholeResult['events']);

        echo "[" . date('Y-m-d H:i:s') . "] Synced " . $ingressResult['processed'] . " domains\n";
        if ($piholeResult['skipped']) {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole sync skipped (missing pihole_url or pihole_pass)\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Pi-hole entries active=" . $piholeResult['active'] . ", removed=" . $piholeResult['removed'] . "\n";
        }

        finishSyncRun($pdo, $runId, 'success', [
            'ingress_processed' => $ingressResult['processed'],
            'ingress_inserted' => $ingressResult['inserted'],
            'ingress_updated' => $ingressResult['updated'],
            'ingress_unchanged' => $ingressResult['unchanged'],
            'pihole_active' => $piholeResult['active'],
            'pihole_inserted' => $piholeResult['inserted'],
            'pihole_updated' => $piholeResult['updated'],
            'pihole_removed' => $piholeResult['removed'],
            'pihole_skipped' => $piholeResult['skipped'],
        ], null);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
        if ($runId !== null) {
            finishSyncRun($pdo, $runId, 'failed', [], $e->getMessage());
        }
    }

    sleep($config['interval_seconds']);
}