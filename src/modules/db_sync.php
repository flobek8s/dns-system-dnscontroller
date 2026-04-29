<?php

function upsertIngressDnsRecords(PDO $pdo, array $records, $staleAfterSeconds = 0)
{
    $existingStmt = $pdo->query('SELECT id, namespace, ingress_name, domain, service, ip, type, last_seen FROM k8s_ingress_dns');
    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingByDomain = [];
    foreach ($existingRows as $row) {
        $existingByDomain[$row['domain']] = $row;
    }

    $findExisting = $pdo->prepare('SELECT id, namespace, ingress_name, domain, service, ip, type FROM k8s_ingress_dns WHERE domain = :domain LIMIT 1');
    $insertEntry = $pdo->prepare('
        INSERT INTO k8s_ingress_dns (id, namespace, ingress_name, domain, service, ip, type, last_seen)
        VALUES (:id, :namespace, :ingress, :domain, :service, :ip, :type, NOW())
    ');
    $updateEntry = $pdo->prepare('
        UPDATE k8s_ingress_dns
        SET namespace = :namespace,
            ingress_name = :ingress,
            service = :service,
            ip = :ip,
            type = :type,
            last_seen = NOW()
        WHERE id = :id
    ');
    $deleteEntry = $pdo->prepare('DELETE FROM k8s_ingress_dns WHERE id = :id');
    $nextEntryId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM k8s_ingress_dns')->fetchColumn();

    $processed = 0;
    $inserted = 0;
    $updated = 0;
    $unchanged = 0;
    $removed = 0;
    $staleCandidates = [];
    $events = [];
    $seenDomains = [];
    $staleAfterSeconds = max(0, (int)$staleAfterSeconds);
    $staleCutoff = $staleAfterSeconds > 0 ? (time() - $staleAfterSeconds) : time();

    foreach ($records as $record) {
        $seenDomains[$record['domain']] = true;

        $findExisting->execute([
            ':domain' => $record['domain']
        ]);
        $existing = $findExisting->fetch(PDO::FETCH_ASSOC);
        $existingId = $existing['id'] ?? false;

        if ($existingId !== false) {
            $previous = [
                'namespace' => $existing['namespace'],
                'ingress_name' => $existing['ingress_name'],
                'domain' => $existing['domain'],
                'service' => $existing['service'],
                'ip' => $existing['ip'],
                'type' => $existing['type'],
            ];

            $updateEntry->execute([
                ':id' => $existingId,
                ':namespace' => $record['namespace'],
                ':ingress' => $record['ingress_name'],
                ':service' => $record['service'],
                ':ip' => $record['ip'],
                ':type' => $record['type']
            ]);

            if ($previous !== [
                'namespace' => $record['namespace'],
                'ingress_name' => $record['ingress_name'],
                'domain' => $record['domain'],
                'service' => $record['service'],
                'ip' => $record['ip'],
                'type' => $record['type'],
            ]) {
                $updated++;
                $events[] = [
                    'action' => 'update',
                    'domain' => $record['domain'],
                    'old' => $previous,
                    'new' => $record,
                ];
            } else {
                $unchanged++;
            }
        } else {
            $insertEntry->execute([
                ':id' => $nextEntryId++,
                ':namespace' => $record['namespace'],
                ':ingress' => $record['ingress_name'],
                ':domain' => $record['domain'],
                ':service' => $record['service'],
                ':ip' => $record['ip'],
                ':type' => $record['type']
            ]);

            $inserted++;
            $events[] = [
                'action' => 'insert',
                'domain' => $record['domain'],
                'old' => null,
                'new' => $record,
            ];
        }

        $processed++;
    }

    foreach ($existingByDomain as $domain => $existing) {
        if (isset($seenDomains[$domain])) {
            continue;
        }

        $lastSeen = isset($existing['last_seen']) ? strtotime((string)$existing['last_seen']) : false;
        if ($lastSeen !== false && $lastSeen > $staleCutoff) {
            $staleCandidates[] = $domain;
            continue;
        }

        $deleteEntry->execute([
            ':id' => $existing['id']
        ]);

        $removed++;
        $events[] = [
            'action' => 'delete',
            'domain' => $domain,
            'old' => [
                'namespace' => $existing['namespace'],
                'ingress_name' => $existing['ingress_name'],
                'domain' => $existing['domain'],
                'service' => $existing['service'],
                'ip' => $existing['ip'],
                'type' => $existing['type'],
            ],
            'new' => null,
        ];
    }

    return [
        'processed' => $processed,
        'inserted' => $inserted,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'removed' => $removed,
        'stale_candidates' => $staleCandidates,
        'events' => $events,
    ];
}