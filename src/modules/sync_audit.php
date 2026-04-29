<?php

function generateAuditId($prefix)
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function ensureSyncAuditTables(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS dns_controller_sync_runs (
        run_id VARCHAR(40) PRIMARY KEY,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        status VARCHAR(16) NOT NULL,
        ingress_processed INT NOT NULL DEFAULT 0,
        ingress_inserted INT NOT NULL DEFAULT 0,
        ingress_updated INT NOT NULL DEFAULT 0,
        ingress_unchanged INT NOT NULL DEFAULT 0,
        ingress_removed INT NOT NULL DEFAULT 0,
        pihole_active INT NOT NULL DEFAULT 0,
        pihole_inserted INT NOT NULL DEFAULT 0,
        pihole_updated INT NOT NULL DEFAULT 0,
        pihole_removed INT NOT NULL DEFAULT 0,
        pihole_skipped TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NULL
    )');

    try {
        $pdo->exec('ALTER TABLE dns_controller_sync_runs ADD COLUMN ingress_removed INT NOT NULL DEFAULT 0');
    } catch (Exception $ignored) {
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS dns_controller_sync_events (
        event_id VARCHAR(40) PRIMARY KEY,
        run_id VARCHAR(40) NOT NULL,
        process_name VARCHAR(32) NOT NULL,
        action VARCHAR(32) NOT NULL,
        domain VARCHAR(255) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS dns_controller_managed_domains (
        domain VARCHAR(255) PRIMARY KEY,
        ingress_type VARCHAR(32) NOT NULL,
        target_ip VARCHAR(255) NULL,
        service VARCHAR(255) NULL,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        active TINYINT(1) NOT NULL DEFAULT 1
    )');
}

function startSyncRun(PDO $pdo)
{
    $runId = generateAuditId('run');

    $stmt = $pdo->prepare('INSERT INTO dns_controller_sync_runs (run_id, status) VALUES (:run_id, :status)');
    $stmt->execute([
        ':run_id' => $runId,
        ':status' => 'running'
    ]);

    return $runId;
}

function finishSyncRun(PDO $pdo, $runId, $status, array $summary, $message)
{
    $stmt = $pdo->prepare('UPDATE dns_controller_sync_runs
        SET completed_at = NOW(),
            status = :status,
            ingress_processed = :ingress_processed,
            ingress_inserted = :ingress_inserted,
            ingress_updated = :ingress_updated,
            ingress_unchanged = :ingress_unchanged,
            ingress_removed = :ingress_removed,
            pihole_active = :pihole_active,
            pihole_inserted = :pihole_inserted,
            pihole_updated = :pihole_updated,
            pihole_removed = :pihole_removed,
            pihole_skipped = :pihole_skipped,
            message = :message
        WHERE run_id = :run_id');

    $stmt->execute([
        ':status' => $status,
        ':ingress_processed' => (int)($summary['ingress_processed'] ?? 0),
        ':ingress_inserted' => (int)($summary['ingress_inserted'] ?? 0),
        ':ingress_updated' => (int)($summary['ingress_updated'] ?? 0),
        ':ingress_unchanged' => (int)($summary['ingress_unchanged'] ?? 0),
        ':ingress_removed' => (int)($summary['ingress_removed'] ?? 0),
        ':pihole_active' => (int)($summary['pihole_active'] ?? 0),
        ':pihole_inserted' => (int)($summary['pihole_inserted'] ?? 0),
        ':pihole_updated' => (int)($summary['pihole_updated'] ?? 0),
        ':pihole_removed' => (int)($summary['pihole_removed'] ?? 0),
        ':pihole_skipped' => !empty($summary['pihole_skipped']) ? 1 : 0,
        ':message' => $message,
        ':run_id' => $runId,
    ]);
}

function logSyncEvents(PDO $pdo, $runId, $processName, array $events)
{
    if (empty($events)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO dns_controller_sync_events
        (event_id, run_id, process_name, action, domain, old_value, new_value)
        VALUES (:event_id, :run_id, :process_name, :action, :domain, :old_value, :new_value)');

    foreach ($events as $event) {
        $stmt->execute([
            ':event_id' => generateAuditId('evt'),
            ':run_id' => $runId,
            ':process_name' => $processName,
            ':action' => $event['action'] ?? 'unknown',
            ':domain' => $event['domain'] ?? null,
            ':old_value' => isset($event['old']) ? json_encode($event['old']) : null,
            ':new_value' => isset($event['new']) ? json_encode($event['new']) : null,
        ]);
    }
}

function syncManagedDomainsFromIngress(PDO $pdo, array $records, $staleAfterSeconds = 0)
{
    $staleAfterSeconds = max(0, (int)$staleAfterSeconds);

    $upsert = $pdo->prepare('INSERT INTO dns_controller_managed_domains
        (domain, ingress_type, target_ip, service, last_seen, active)
        VALUES (:domain, :ingress_type, :target_ip, :service, NOW(), 1)
        ON DUPLICATE KEY UPDATE
            ingress_type = VALUES(ingress_type),
            target_ip = VALUES(target_ip),
            service = VALUES(service),
            last_seen = NOW(),
            active = 1');

    $domains = [];
    foreach ($records as $record) {
        $domains[] = $record['domain'];
        $upsert->execute([
            ':domain' => $record['domain'],
            ':ingress_type' => $record['type'],
            ':target_ip' => $record['ip'],
            ':service' => $record['service'],
        ]);
    }

    if (empty($domains)) {
        if ($staleAfterSeconds <= 0) {
            $pdo->exec('UPDATE dns_controller_managed_domains SET active = 0 WHERE active = 1');
            return;
        }

        $pdo->exec('UPDATE dns_controller_managed_domains
            SET active = 0
            WHERE active = 1 AND last_seen < DATE_SUB(NOW(), INTERVAL ' . $staleAfterSeconds . ' SECOND)');
        return;
    }

    $placeholders = implode(',', array_fill(0, count($domains), '?'));
    $sql = 'UPDATE dns_controller_managed_domains
        SET active = 0
        WHERE active = 1 AND domain NOT IN (' . $placeholders . ')';

    if ($staleAfterSeconds > 0) {
        $sql .= ' AND last_seen < DATE_SUB(NOW(), INTERVAL ' . $staleAfterSeconds . ' SECOND)';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($domains);
}