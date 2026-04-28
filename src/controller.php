<?php

// -----------------------------
// CONFIG (use env in real setup if you want later)
// -----------------------------
define('DB_HOST', $_ENV['mysql_host']);
define('DB_USER', $_ENV['mysql_user']);
define('DB_PASS', $_ENV['mysql_pass']);
define('DB_NAME', $_ENV['mysql_db']);

// Poll Once an hour
$interval = 3600;
// Every 60 Seconds
// $interval - 60;

// Kubernetes in-cluster auth
$tokenFile = "/var/run/secrets/kubernetes.io/serviceaccount/token";
$caFile = "/var/run/secrets/kubernetes.io/serviceaccount/ca.crt";
$kubeApi = "https://kubernetes.default.svc";

// -----------------------------
// DB CONNECT
// -----------------------------
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// -----------------------------
// GET K8S TOKEN
// -----------------------------
$token = file_get_contents($tokenFile);

function kubernetesGetJson($kubeApi, $token, $caFile, $path)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $kubeApi . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token"
        ],
        CURLOPT_CAINFO => $caFile,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data = json_decode($response, true);

    if ($statusCode === 404) {
        return null;
    }

    if ($statusCode >= 400) {
        $message = is_array($data) ? ($data['message'] ?? $response) : $response;
        throw new Exception("Kubernetes API request failed for $path: HTTP $statusCode - $message");
    }

    if (!is_array($data)) {
        throw new Exception("Invalid Kubernetes response for $path");
    }

    return $data;
}

function getLoadBalancerAddresses(array $resource)
{
    $addresses = [];

    foreach (($resource['status']['loadBalancer']['ingress'] ?? []) as $ingress) {
        if (!empty($ingress['ip'])) {
            $addresses[] = $ingress['ip'];
        } elseif (!empty($ingress['hostname'])) {
            $addresses[] = $ingress['hostname'];
        }
    }

    return array_values(array_unique($addresses));
}

function parseMatchHosts($match)
{
    if (!is_string($match) || $match === '') {
        return [];
    }

    $hosts = [];

    if (preg_match_all('/Host(?:SNI)?\(([^)]*)\)/', $match, $functionMatches)) {
        foreach ($functionMatches[1] as $argumentList) {
            if (preg_match_all('/`([^`]+)`/', $argumentList, $hostMatches)) {
                foreach ($hostMatches[1] as $host) {
                    $hosts[] = $host;
                }
            }
        }
    }

    return array_values(array_unique($hosts));
}

function buildServiceIndex(array $serviceItems)
{
    $index = [];
    $traefikLoadBalancerIps = [];
    $allLoadBalancerIps = [];

    foreach ($serviceItems as $service) {
        $namespace = $service['metadata']['namespace'] ?? 'default';
        $name = $service['metadata']['name'] ?? null;

        if ($name === null) {
            continue;
        }

        $loadBalancerIps = getLoadBalancerAddresses($service);
        $externalIps = $service['spec']['externalIPs'] ?? [];
        $allIps = array_values(array_unique(array_merge($loadBalancerIps, $externalIps)));

        if (!isset($index[$namespace])) {
            $index[$namespace] = [];
        }

        $index[$namespace][$name] = [
            'type' => $service['spec']['type'] ?? null,
            'ips' => $allIps,
        ];

        if (($service['spec']['type'] ?? null) === 'LoadBalancer') {
            $allLoadBalancerIps = array_values(array_unique(array_merge($allLoadBalancerIps, $allIps)));

            $labels = $service['metadata']['labels'] ?? [];
            $isTraefikService = ($labels['app.kubernetes.io/name'] ?? '') === 'traefik'
                || ($labels['app'] ?? '') === 'traefik'
                || stripos($name, 'traefik') !== false;

            if ($isTraefikService) {
                $traefikLoadBalancerIps = array_values(array_unique(array_merge($traefikLoadBalancerIps, $allIps)));
            }
        }
    }

    return [$index, !empty($traefikLoadBalancerIps) ? $traefikLoadBalancerIps : $allLoadBalancerIps];
}

function resolveRecordIps(array $resource, array $routeServices, $namespace, array $serviceIndex, array $defaultIngressIps)
{
    $ips = getLoadBalancerAddresses($resource);

    if (!empty($ips)) {
        return $ips;
    }

    foreach ($routeServices as $serviceName) {
        $serviceIps = $serviceIndex[$namespace][$serviceName]['ips'] ?? [];
        if (!empty($serviceIps)) {
            $ips = array_values(array_unique(array_merge($ips, $serviceIps)));
        }
    }

    if (!empty($ips)) {
        return $ips;
    }

    return $defaultIngressIps;
}

function getTraefikResources($kubeApi, $token, $caFile, $resource)
{
    $data = kubernetesGetJson($kubeApi, $token, $caFile, "/apis/traefik.io/v1alpha1/$resource");

    if ($data !== null) {
        return $data;
    }

    return kubernetesGetJson($kubeApi, $token, $caFile, "/apis/traefik.containo.us/v1alpha1/$resource");
}

// -----------------------------
// MAIN LOOP (POLLING MODE)
// -----------------------------
while (true) {

    try {

        echo "[" . date('Y-m-d H:i:s') . "] Sync starting...\n";

        $ingressData = kubernetesGetJson($kubeApi, $token, $caFile, "/apis/networking.k8s.io/v1/ingresses");
        $serviceData = kubernetesGetJson($kubeApi, $token, $caFile, "/api/v1/services");

        if (!isset($ingressData['items'], $serviceData['items'])) {
            throw new Exception("Invalid Kubernetes response");
        }

        $ingressRouteData = getTraefikResources($kubeApi, $token, $caFile, "ingressroutes");
        $ingressRouteTcpData = getTraefikResources($kubeApi, $token, $caFile, "ingressroutetcps");

        [$serviceIndex, $defaultIngressIps] = buildServiceIndex($serviceData['items']);
        $records = [];

        foreach ($ingressData['items'] as $ingress) {
            $namespace = $ingress['metadata']['namespace'] ?? 'default';
            $name = $ingress['metadata']['name'] ?? 'unknown';

            foreach (($ingress['spec']['rules'] ?? []) as $rule) {
                $domain = $rule['host'] ?? null;

                if ($domain === null || $domain === '') {
                    continue;
                }

                $serviceNames = [];
                foreach (($rule['http']['paths'] ?? []) as $path) {
                    $serviceName = $path['backend']['service']['name'] ?? null;
                    if ($serviceName !== null && $serviceName !== '') {
                        $serviceNames[] = $serviceName;
                    }
                }

                $serviceNames = array_values(array_unique($serviceNames));
                $ips = resolveRecordIps($ingress, $serviceNames, $namespace, $serviceIndex, $defaultIngressIps);

                $records[$domain] = [
                    'namespace' => $namespace,
                    'ingress_name' => $name,
                    'domain' => $domain,
                    'service' => implode(', ', $serviceNames),
                    'ip' => implode(', ', $ips),
                    'type' => 'ingress',
                ];
            }
        }

        foreach (($ingressRouteData['items'] ?? []) as $ingressRoute) {
            $namespace = $ingressRoute['metadata']['namespace'] ?? 'default';
            $name = $ingressRoute['metadata']['name'] ?? 'unknown';

            foreach (($ingressRoute['spec']['routes'] ?? []) as $route) {
                $domains = parseMatchHosts($route['match'] ?? '');
                $serviceNames = [];

                foreach (($route['services'] ?? []) as $service) {
                    $serviceName = $service['name'] ?? null;
                    if ($serviceName !== null && $serviceName !== '') {
                        $serviceNames[] = $serviceName;
                    }
                }

                $serviceNames = array_values(array_unique($serviceNames));
                $ips = resolveRecordIps($ingressRoute, $serviceNames, $namespace, $serviceIndex, $defaultIngressIps);

                foreach ($domains as $domain) {
                    $records[$domain] = [
                        'namespace' => $namespace,
                        'ingress_name' => $name,
                        'domain' => $domain,
                        'service' => implode(', ', $serviceNames),
                        'ip' => implode(', ', $ips),
                        'type' => 'ingressroute',
                    ];
                }
            }
        }

        foreach (($ingressRouteTcpData['items'] ?? []) as $ingressRouteTcp) {
            $namespace = $ingressRouteTcp['metadata']['namespace'] ?? 'default';
            $name = $ingressRouteTcp['metadata']['name'] ?? 'unknown';

            foreach (($ingressRouteTcp['spec']['routes'] ?? []) as $route) {
                $domains = parseMatchHosts($route['match'] ?? '');
                $serviceNames = [];

                foreach (($route['services'] ?? []) as $service) {
                    $serviceName = $service['name'] ?? null;
                    if ($serviceName !== null && $serviceName !== '') {
                        $serviceNames[] = $serviceName;
                    }
                }

                $serviceNames = array_values(array_unique($serviceNames));
                $ips = resolveRecordIps($ingressRouteTcp, $serviceNames, $namespace, $serviceIndex, $defaultIngressIps);

                foreach ($domains as $domain) {
                    $records[$domain] = [
                        'namespace' => $namespace,
                        'ingress_name' => $name,
                        'domain' => $domain,
                        'service' => implode(', ', $serviceNames),
                        'ip' => implode(', ', $ips),
                        'type' => 'ingressroutetcp',
                    ];
                }
            }
        }

        // -----------------------------
        // UPSERT HOSTS
        // -----------------------------
        $findExisting = $pdo->prepare("SELECT id FROM k8s_ingress_dns WHERE domain = :domain LIMIT 1");
        $insertEntry = $pdo->prepare("
            INSERT INTO k8s_ingress_dns (id, namespace, ingress_name, domain, service, ip, type, last_seen)
            VALUES (:id, :namespace, :ingress, :domain, :service, :ip, :type, NOW())
        ");
        $updateEntry = $pdo->prepare("
            UPDATE k8s_ingress_dns
            SET namespace = :namespace,
                ingress_name = :ingress,
                service = :service,
                ip = :ip,
                type = :type,
                last_seen = NOW()
            WHERE id = :id
        ");
        $nextEntryId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM k8s_ingress_dns")->fetchColumn();

        $count = 0;

        foreach ($records as $record) {
            $findExisting->execute([
                ':domain' => $record['domain']
            ]);
            $existingId = $findExisting->fetchColumn();

            if ($existingId !== false) {
                $updateEntry->execute([
                    ':id' => $existingId,
                    ':namespace' => $record['namespace'],
                    ':ingress' => $record['ingress_name'],
                    ':service' => $record['service'],
                    ':ip' => $record['ip'],
                    ':type' => $record['type']
                ]);
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
            }

            $count++;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Synced $count domains\n";

    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}