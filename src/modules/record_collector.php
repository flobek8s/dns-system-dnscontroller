<?php

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

    if (preg_match_all('/Host(?:SNI)?\(([^)]*)\)/i', $match, $functionMatches)) {
        foreach ($functionMatches[1] as $argumentList) {
            $matchedQuotedHost = false;

            if (preg_match_all('/[`\'"]([^`\'"]+)[`\'"]/', $argumentList, $hostMatches)) {
                $matchedQuotedHost = true;

                foreach ($hostMatches[1] as $host) {
                    if (shouldManageDomain($host)) {
                        $hosts[] = $host;
                    }
                }
            }

            if ($matchedQuotedHost) {
                continue;
            }

            foreach (explode(',', $argumentList) as $candidate) {
                $host = trim((string)$candidate, " \t\n\r\0\x0B`'\"");
                if (shouldManageDomain($host)) {
                    $hosts[] = $host;
                }
            }
        }
    }

    return array_values(array_unique($hosts));
}

function shouldManageDomain($domain)
{
    if (!is_string($domain)) {
        return false;
    }

    $domain = trim($domain);

    if ($domain === '' || $domain === '*' || strpos($domain, '*') !== false) {
        return false;
    }

    return (bool)preg_match('/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9-]{1,63}\.)+[a-zA-Z]{2,63}$/', $domain);
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

function selectPrimaryIp(array $ips)
{
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip !== '') {
            return $ip;
        }
    }

    return '';
}

function collectIngressRecords($kubeApi, $token, $caFile)
{
    $ingressData = kubernetesGetJson($kubeApi, $token, $caFile, '/apis/networking.k8s.io/v1/ingresses');
    $serviceData = kubernetesGetJson($kubeApi, $token, $caFile, '/api/v1/services');

    if (!isset($ingressData['items'], $serviceData['items'])) {
        throw new Exception('Invalid Kubernetes response');
    }

    $ingressRouteData = getTraefikResources($kubeApi, $token, $caFile, 'ingressroutes');
    $ingressRouteTcpData = getTraefikResources($kubeApi, $token, $caFile, 'ingressroutetcps');

    [$serviceIndex, $defaultIngressIps] = buildServiceIndex($serviceData['items']);
    $records = [];

    foreach ($ingressData['items'] as $ingress) {
        $namespace = $ingress['metadata']['namespace'] ?? 'default';
        $name = $ingress['metadata']['name'] ?? 'unknown';

        foreach (($ingress['spec']['rules'] ?? []) as $rule) {
            $domain = $rule['host'] ?? null;

            if (!shouldManageDomain($domain)) {
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
                'ip' => selectPrimaryIp($ips),
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
                    'ip' => selectPrimaryIp($ips),
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
                    'ip' => selectPrimaryIp($ips),
                    'type' => 'ingressroutetcp',
                ];
            }
        }
    }

    return $records;
}