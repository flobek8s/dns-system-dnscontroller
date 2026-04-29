<?php

function piholeApiRequest($url, $method, array $headers, $body, $verifySsl)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('Pi-hole cURL error: ' . curl_error($ch));
    }

    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data = json_decode($response, true);

    if ($statusCode >= 400) {
        throw new Exception('Pi-hole API request failed: HTTP ' . $statusCode . ' - ' . $response);
    }

    if (!is_array($data)) {
        throw new Exception('Unexpected Pi-hole response: ' . $response);
    }

    return $data;
}

function fetchPiholeHostEntries(array $config)
{
    if ($config['pihole_url'] === '' || $config['pihole_pass'] === '') {
        return null;
    }

    $baseUrl = rtrim($config['pihole_url'], '/');

    $authData = piholeApiRequest(
        $baseUrl . '/api/auth',
        'POST',
        ['Content-Type: application/json'],
        json_encode(['password' => $config['pihole_pass']]),
        $config['pihole_verify_ssl']
    );

    $sid = $authData['session']['sid'] ?? null;
    if ($sid === null || $sid === '') {
        throw new Exception('Pi-hole authentication failed: SID missing');
    }

    $hostsData = piholeApiRequest(
        $baseUrl . '/api/config/dns/hosts',
        'GET',
        [
            'accept: application/json',
            'sid: ' . $sid
        ],
        null,
        $config['pihole_verify_ssl']
    );

    if (!isset($hostsData['config']['dns']['hosts']) || !is_array($hostsData['config']['dns']['hosts'])) {
        throw new Exception('Pi-hole response missing config.dns.hosts');
    }

    $entries = [];
    foreach ($hostsData['config']['dns']['hosts'] as $entry) {
        $parts = explode(' ', trim((string)$entry), 2);
        $ip = $parts[0] ?? null;
        $domain = $parts[1] ?? null;

        if (!empty($ip) && !empty($domain)) {
            $entries[$domain] = $ip;
        }
    }

    return $entries;
}

function syncPiholeDnsEntries(PDO $pdo, array $config)
{
    $currentEntries = fetchPiholeHostEntries($config);
    if ($currentEntries === null) {
        return [
            'skipped' => true,
            'active' => 0,
            'removed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'events' => [],
        ];
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->query('SELECT id, domain, ip, datecreated, dateupdated FROM pihole_dns_entries');
        $dbEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dbDomains = [];
        foreach ($dbEntries as $row) {
            $dbDomains[$row['domain']] = $row;
        }

        $domainsToRemove = array_diff(array_keys($dbDomains), array_keys($currentEntries));

        $events = [];
        $inserted = 0;
        $updated = 0;

        if (!empty($domainsToRemove)) {
            $nextRemovedId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM pihole_dns_removed')->fetchColumn();

            $insertHistory = $pdo->prepare('
                INSERT INTO pihole_dns_removed (id, domain, ip, datecreated, dateupdated)
                VALUES (:id, :domain, :ip, :datecreated, :dateupdated)
            ');

            foreach ($domainsToRemove as $domain) {
                $row = $dbDomains[$domain];

                $insertHistory->execute([
                    ':id' => $nextRemovedId++,
                    ':domain' => $row['domain'],
                    ':ip' => $row['ip'],
                    ':datecreated' => $row['datecreated'],
                    ':dateupdated' => $row['dateupdated']
                ]);

                $events[] = [
                    'action' => 'remove',
                    'domain' => $row['domain'],
                    'old' => ['ip' => $row['ip']],
                    'new' => null,
                ];
            }

            $placeholders = implode(',', array_fill(0, count($domainsToRemove), '?'));
            $deleteStmt = $pdo->prepare('DELETE FROM pihole_dns_entries WHERE domain IN (' . $placeholders . ')');
            $deleteStmt->execute(array_values($domainsToRemove));
        }

        $findExisting = $pdo->prepare('SELECT id, ip FROM pihole_dns_entries WHERE domain = :domain LIMIT 1');
        $insertEntry = $pdo->prepare('
            INSERT INTO pihole_dns_entries (id, domain, ip)
            VALUES (:id, :domain, :ip)
        ');
        $updateEntry = $pdo->prepare('
            UPDATE pihole_dns_entries
            SET ip = :ip, dateupdated = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $nextEntryId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM pihole_dns_entries')->fetchColumn();

        foreach ($currentEntries as $domain => $ip) {
            $findExisting->execute([':domain' => $domain]);
            $existing = $findExisting->fetch(PDO::FETCH_ASSOC);
            $existingId = $existing['id'] ?? false;

            if ($existingId !== false) {
                $updateEntry->execute([
                    ':id' => $existingId,
                    ':ip' => $ip
                ]);

                if (($existing['ip'] ?? null) !== $ip) {
                    $updated++;
                    $events[] = [
                        'action' => 'update',
                        'domain' => $domain,
                        'old' => ['ip' => $existing['ip']],
                        'new' => ['ip' => $ip],
                    ];
                }
                continue;
            }

            $insertEntry->execute([
                ':id' => $nextEntryId++,
                ':domain' => $domain,
                ':ip' => $ip
            ]);

            $inserted++;
            $events[] = [
                'action' => 'insert',
                'domain' => $domain,
                'old' => null,
                'new' => ['ip' => $ip],
            ];
        }

        $pdo->commit();

        return [
            'skipped' => false,
            'active' => count($currentEntries),
            'removed' => count($domainsToRemove),
            'inserted' => $inserted,
            'updated' => $updated,
            'events' => $events,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}