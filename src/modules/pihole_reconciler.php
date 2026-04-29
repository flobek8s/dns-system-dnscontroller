<?php

function createPiholeSession(array $config)
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

    return [
        'base_url' => $baseUrl,
        'sid' => $sid,
        'verify_ssl' => $config['pihole_verify_ssl'],
    ];
}

function piholeConfigValueRequest(array $session, $method, $element, $value)
{
    $encodedValue = urlencode($value);

    return piholeApiRequest(
        $session['base_url'] . '/api/config/' . trim($element, '/') . '/' . $encodedValue,
        $method,
        [
            'accept: application/json',
            'sid: ' . $session['sid']
        ],
        null,
        $session['verify_ssl']
    );
}

function getManagedDomainsForReconciliation(PDO $pdo)
{
    $activeStmt = $pdo->query('SELECT domain, ingress_type, target_ip, service, active FROM dns_controller_managed_domains WHERE active = 1');
    $activeDomains = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    $inactiveStmt = $pdo->query('SELECT domain, ingress_type, target_ip, service, active FROM dns_controller_managed_domains WHERE active = 0');
    $inactiveDomains = $inactiveStmt->fetchAll(PDO::FETCH_ASSOC);

    return [$activeDomains, $inactiveDomains];
}

function reconcileManagedDomainsInPihole(PDO $pdo, array $config)
{
    $session = createPiholeSession($config);
    if ($session === null) {
        return [
            'skipped' => true,
            'inserted' => 0,
            'updated' => 0,
            'removed' => 0,
            'events' => [],
        ];
    }

    $currentEntries = fetchPiholeHostEntries($config);
    if ($currentEntries === null) {
        return [
            'skipped' => true,
            'inserted' => 0,
            'updated' => 0,
            'removed' => 0,
            'events' => [],
        ];
    }

    [$activeDomains, $inactiveDomains] = getManagedDomainsForReconciliation($pdo);

    $inserted = 0;
    $updated = 0;
    $removed = 0;
    $events = [];

    foreach ($activeDomains as $managedDomain) {
        $domain = $managedDomain['domain'];
        $targetIp = trim((string)($managedDomain['target_ip'] ?? ''));

        if (!shouldManageDomain($domain) || $targetIp === '') {
            continue;
        }

        $existingIp = $currentEntries[$domain] ?? null;
        $newValue = $targetIp . ' ' . $domain;

        if ($existingIp === null) {
            piholeConfigValueRequest($session, 'PUT', 'dns/hosts', $newValue);
            $currentEntries[$domain] = $targetIp;
            $inserted++;
            $events[] = [
                'action' => 'insert',
                'domain' => $domain,
                'old' => null,
                'new' => [
                    'ip' => $targetIp,
                    'type' => $managedDomain['ingress_type'],
                    'service' => $managedDomain['service'],
                ],
            ];
            continue;
        }

        if ($existingIp !== $targetIp) {
            piholeConfigValueRequest($session, 'DELETE', 'dns/hosts', $existingIp . ' ' . $domain);
            piholeConfigValueRequest($session, 'PUT', 'dns/hosts', $newValue);
            $currentEntries[$domain] = $targetIp;
            $updated++;
            $events[] = [
                'action' => 'update',
                'domain' => $domain,
                'old' => ['ip' => $existingIp],
                'new' => [
                    'ip' => $targetIp,
                    'type' => $managedDomain['ingress_type'],
                    'service' => $managedDomain['service'],
                ],
            ];
        }
    }

    foreach ($inactiveDomains as $managedDomain) {
        $domain = $managedDomain['domain'];

        if (!shouldManageDomain($domain)) {
            continue;
        }

        $existingIp = $currentEntries[$domain] ?? null;

        if ($existingIp === null) {
            continue;
        }

        piholeConfigValueRequest($session, 'DELETE', 'dns/hosts', $existingIp . ' ' . $domain);
        unset($currentEntries[$domain]);
        $removed++;
        $events[] = [
            'action' => 'delete',
            'domain' => $domain,
            'old' => [
                'ip' => $existingIp,
                'type' => $managedDomain['ingress_type'],
                'service' => $managedDomain['service'],
            ],
            'new' => null,
        ];
    }

    return [
        'skipped' => false,
        'inserted' => $inserted,
        'updated' => $updated,
        'removed' => $removed,
        'events' => $events,
    ];
}