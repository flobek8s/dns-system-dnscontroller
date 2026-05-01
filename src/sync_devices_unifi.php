<?php
require_once 'vendor/autoload.php';

use UniFi_API\Client;

function env_or_default(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function parse_wireguard_endpoint(?string $config): array
{
    if (!$config) {
        return ['host' => '', 'port' => null];
    }

    if (!preg_match('/^Endpoint\s*=\s*(.+)$/mi', $config, $matches)) {
        return ['host' => '', 'port' => null];
    }

    $parts = parse_url('wg://' . trim($matches[1]));

    return [
        'host' => $parts['host'] ?? '',
        'port' => isset($parts['port']) ? (int) $parts['port'] : null,
    ];
}

function mac_suffix(string $mac): string
{
    $parts = explode(':', strtolower($mac));
    if (count($parts) < 2) {
        return $mac;
    }
    return $parts[count($parts) - 2] . ':' . $parts[count($parts) - 1];
}

function normalize_mac(string $mac): string
{
    return strtolower(trim($mac));
}

try {
    $site           = env_or_default('UNIFI_SITE', 'default');
    $controller_url = $_ENV['unifi_url'];
    $api_key        = $_ENV['unifi_api'];
    $lookback_hours = (int) env_or_default('UNIFI_LOOKBACK_HOURS', (string) (24 * 30));
    $db_host        = $_ENV['mysql_host'];
    $db_name        = $_ENV['mysql_db'];
    $db_user        = $_ENV['mysql_user'];
    $db_pass        = $_ENV['mysql_pass'];
    $sync_id        = date('YmdHis') . '-' . bin2hex(random_bytes(4));

    if ($api_key === '') {
        throw new RuntimeException('UNIFI_API_KEY is required.');
    }

    $client = new Client('', '', $controller_url, $site);
    $client->set_api_key($api_key);

    $networks     = (array) $client->list_networkconf();
    $vpn_networks = array_values(array_filter($networks, function ($net) {
        return isset($net->purpose) && $net->purpose === 'vpn-client';
    }));

    $vpn_by_id = [];
    foreach ($vpn_networks as $net) {
        $vpn_by_id[$net->_id] = $net;
    }
    $vpn_network_ids = array_keys($vpn_by_id);

    $traffic_routes = (array) $client->custom_api_request('/v2/api/site/' . $site . '/trafficroutes');
    $vpn_routes     = array_values(array_filter($traffic_routes, function ($route) use ($vpn_network_ids) {
        return isset($route->network_id)
            && in_array($route->network_id, $vpn_network_ids, true)
            && ($route->enabled ?? false);
    }));

    $vpn_target_map = [];
    foreach ($vpn_routes as $route) {
        $network_id = $route->network_id ?? '';
        $vpn_name   = $vpn_by_id[$network_id]->name ?? '';
        $endpoint   = parse_wireguard_endpoint($vpn_by_id[$network_id]->wireguard_client_configuration_file ?? '');

        foreach ((array) ($route->target_devices ?? []) as $device) {
            $mac = normalize_mac((string) ($device->client_mac ?? $device->mac ?? ''));
            if ($mac === '') {
                continue;
            }

            $vpn_target_map[$mac] = [
                'vpn_name'       => $vpn_name,
                'route_desc'     => $route->description ?? '',
                'endpoint_host'  => $endpoint['host'],
                'endpoint_port'  => $endpoint['port'],
            ];
        }
    }

    $online_clients      = (array) $client->list_clients();
    $history_clients     = (array) $client->list_clients_history(true, true, $lookback_hours);
    $history_clients_all = (array) $client->list_clients_history(true, true, 0);
    $known_users         = (array) $client->list_users();
    $allusers_30d        = (array) $client->stat_allusers($lookback_hours);
    $allusers_1y         = (array) $client->stat_allusers(24 * 365);

    $identity_by_mac = [];
    $add_identity = function ($c) use (&$identity_by_mac): void {
        $mac = normalize_mac((string) ($c->mac ?? ''));
        if ($mac === '') {
            return;
        }

        $name = trim((string) ($c->name ?? ''));
        $hostname = trim((string) ($c->hostname ?? ''));

        if (!isset($identity_by_mac[$mac])) {
            $identity_by_mac[$mac] = ['name' => '', 'hostname' => ''];
        }
        if ($name !== '' && $identity_by_mac[$mac]['name'] === '') {
            $identity_by_mac[$mac]['name'] = $name;
        }
        if ($hostname !== '' && $identity_by_mac[$mac]['hostname'] === '') {
            $identity_by_mac[$mac]['hostname'] = $hostname;
        }
    };

    foreach ([$online_clients, $history_clients, $history_clients_all, $known_users, $allusers_30d, $allusers_1y] as $group) {
        foreach ($group as $c) {
            $add_identity($c);
        }
    }

    $fingerprint_sources = [0 => true];
    $collect_source = function ($c) use (&$fingerprint_sources): void {
        if (isset($c->fingerprint_source) && is_numeric($c->fingerprint_source)) {
            $fingerprint_sources[(int) $c->fingerprint_source] = true;
        }
    };
    foreach ([$online_clients, $history_clients, $known_users, $allusers_30d, $allusers_1y] as $group) {
        foreach ($group as $c) {
            $collect_source($c);
        }
    }

    $fingerprint_name_map = [];
    foreach (array_keys($fingerprint_sources) as $source_id) {
        try {
            $records = (array) $client->list_fingerprint_devices((int) $source_id);
            foreach ($records as $record) {
                foreach ((array) $record as $dev_id => $details) {
                    if (!is_numeric($dev_id) || !is_object($details)) {
                        continue;
                    }
                    $name = trim((string) ($details->name ?? ''));
                    if ($name !== '') {
                        $fingerprint_name_map[(int) $source_id][(int) $dev_id] = $name;
                    }
                }
            }
        } catch (Exception $e) {
        }
    }

    $resolve_fingerprint_name = function ($c) use ($fingerprint_name_map): string {
        $source = isset($c->fingerprint_source) && is_numeric($c->fingerprint_source) ? (int) $c->fingerprint_source : 0;
        $dev_id = isset($c->dev_id) && is_numeric($c->dev_id) ? (int) $c->dev_id : 0;
        if ($dev_id > 0 && isset($fingerprint_name_map[$source][$dev_id])) {
            return $fingerprint_name_map[$source][$dev_id];
        }
        if ($dev_id > 0 && isset($fingerprint_name_map[0][$dev_id])) {
            return $fingerprint_name_map[0][$dev_id];
        }
        return '';
    };

    $rows_by_mac = [];

    foreach ($history_clients as $c) {
        $mac = normalize_mac((string) ($c->mac ?? ''));
        if ($mac === '') {
            continue;
        }

        $fallback_name = $identity_by_mac[$mac]['name'] ?? '';
        $fallback_hostname = $identity_by_mac[$mac]['hostname'] ?? '';
        $fingerprint_name = $resolve_fingerprint_name($c);
        $synthetic_name = ($fallback_name === '' && $fallback_hostname === '' && $fingerprint_name !== '')
            ? $fingerprint_name . ' ' . mac_suffix($mac)
            : '';

        $rows_by_mac[$mac] = [
            'mac'            => strtoupper($c->mac ?? $mac),
            'display_name'   => ($c->name ?? '') !== '' ? $c->name : (($c->hostname ?? '') !== '' ? $c->hostname : ($fallback_name !== '' ? $fallback_name : $synthetic_name)),
            'hostname'       => ($c->hostname ?? '') !== '' ? $c->hostname : $fallback_hostname,
            'ip'             => $c->ip ?? $c->last_ip ?? '',
            'network_name'   => $c->network ?? $c->last_connection_network_name ?? '',
            'link_type'      => ($c->is_wired ?? false) ? 'wired' : 'wireless',
            'status'         => 'offline',
            'source'         => 'history',
            'is_vpn'         => isset($vpn_target_map[$mac]) ? 1 : 0,
            'vpn_name'       => $vpn_target_map[$mac]['vpn_name'] ?? '',
            'vpn_route_desc' => $vpn_target_map[$mac]['route_desc'] ?? '',
            'vpn_endpoint_host' => $vpn_target_map[$mac]['endpoint_host'] ?? '',
            'vpn_endpoint_port' => $vpn_target_map[$mac]['endpoint_port'] ?? null,
            'first_seen_unix' => isset($c->first_seen) ? (int) $c->first_seen : null,
            'last_seen_unix'  => isset($c->last_seen) ? (int) $c->last_seen : null,
        ];
    }

    foreach ($online_clients as $c) {
        $mac = normalize_mac((string) ($c->mac ?? ''));
        if ($mac === '') {
            continue;
        }

        $fallback_name = $identity_by_mac[$mac]['name'] ?? '';
        $fallback_hostname = $identity_by_mac[$mac]['hostname'] ?? '';
        $fingerprint_name = $resolve_fingerprint_name($c);
        $synthetic_name = ($fallback_name === '' && $fallback_hostname === '' && $fingerprint_name !== '')
            ? $fingerprint_name . ' ' . mac_suffix($mac)
            : '';

        $existing_first = $rows_by_mac[$mac]['first_seen_unix'] ?? null;
        $current_first  = isset($c->first_seen) ? (int) $c->first_seen : null;
        $merged_first   = $existing_first && $current_first ? min($existing_first, $current_first) : ($existing_first ?: $current_first);

        $rows_by_mac[$mac] = [
            'mac'            => strtoupper($c->mac ?? $mac),
            'display_name'   => ($c->name ?? '') !== '' ? $c->name : (($c->hostname ?? '') !== '' ? $c->hostname : ($fallback_name !== '' ? $fallback_name : $synthetic_name)),
            'hostname'       => ($c->hostname ?? '') !== '' ? $c->hostname : $fallback_hostname,
            'ip'             => $c->ip ?? $c->last_ip ?? '',
            'network_name'   => $c->network ?? $c->last_connection_network_name ?? '',
            'link_type'      => ($c->is_wired ?? false) ? 'wired' : 'wireless',
            'status'         => 'online',
            'source'         => isset($rows_by_mac[$mac]) ? 'online+history' : 'online',
            'is_vpn'         => isset($vpn_target_map[$mac]) ? 1 : 0,
            'vpn_name'       => $vpn_target_map[$mac]['vpn_name'] ?? '',
            'vpn_route_desc' => $vpn_target_map[$mac]['route_desc'] ?? '',
            'vpn_endpoint_host' => $vpn_target_map[$mac]['endpoint_host'] ?? '',
            'vpn_endpoint_port' => $vpn_target_map[$mac]['endpoint_port'] ?? null,
            'first_seen_unix' => $merged_first,
            'last_seen_unix'  => isset($c->last_seen) ? (int) $c->last_seen : null,
        ];
    }

    foreach ($vpn_target_map as $mac => $meta) {
        if (!isset($rows_by_mac[$mac])) {
            $fallback_name = $identity_by_mac[$mac]['name'] ?? '';
            $fallback_hostname = $identity_by_mac[$mac]['hostname'] ?? '';
            $rows_by_mac[$mac] = [
                'mac'            => strtoupper($mac),
                'display_name'   => $fallback_name,
                'hostname'       => $fallback_hostname,
                'ip'             => '',
                'network_name'   => '',
                'link_type'      => '',
                'status'         => 'offline',
                'source'         => 'vpn-assignment',
                'is_vpn'         => 1,
                'vpn_name'       => $meta['vpn_name'],
                'vpn_route_desc' => $meta['route_desc'],
                'vpn_endpoint_host' => $meta['endpoint_host'],
                'vpn_endpoint_port' => $meta['endpoint_port'],
                'first_seen_unix' => null,
                'last_seen_unix'  => null,
            ];
        }
    }

    // Last-resort name enrichment for rows still missing both display_name and hostname.
    $missing_identity_macs = [];
    foreach ($rows_by_mac as $mac => $row) {
        if (trim((string) $row['display_name']) === '' && trim((string) $row['hostname']) === '') {
            $missing_identity_macs[] = $mac;
        }
    }

    foreach (array_slice($missing_identity_macs, 0, 100) as $mac) {
        try {
            $detail = (array) $client->stat_client($mac);
            if (empty($detail[0])) {
                continue;
            }

            $d = $detail[0];
            $detail_name = trim((string) ($d->name ?? ''));
            $detail_hostname = trim((string) ($d->hostname ?? ''));

            if ($detail_name !== '' || $detail_hostname !== '') {
                if ($rows_by_mac[$mac]['display_name'] === '') {
                    $rows_by_mac[$mac]['display_name'] = $detail_name !== '' ? $detail_name : $detail_hostname;
                }
                if ($rows_by_mac[$mac]['hostname'] === '') {
                    $rows_by_mac[$mac]['hostname'] = $detail_hostname;
                }
                $rows_by_mac[$mac]['source'] = $rows_by_mac[$mac]['source'] . '+stat_client';
            } else {
                $fp_name = $resolve_fingerprint_name($d);
                if ($fp_name !== '') {
                    $rows_by_mac[$mac]['display_name'] = $fp_name . ' ' . mac_suffix($mac);
                    $rows_by_mac[$mac]['source'] = $rows_by_mac[$mac]['source'] . '+fingerprint';
                }
            }
        } catch (Exception $e) {
        }
    }

    $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $existing_state_rows = $pdo->query('SELECT mac, display_name, hostname, ip, network_name, link_type, status, source, is_vpn, vpn_name, vpn_route_desc, vpn_endpoint_host, vpn_endpoint_port, first_seen_unix, last_seen_unix FROM unifi_device_state')->fetchAll();
    $existing_by_mac = [];
    foreach ($existing_state_rows as $existing_row) {
        $existing_by_mac[normalize_mac($existing_row['mac'])] = $existing_row;
    }

    $sql = 'INSERT INTO unifi_device_state
        (mac, display_name, hostname, ip, network_name, link_type, status, source, is_vpn, vpn_name, vpn_route_desc, vpn_endpoint_host, vpn_endpoint_port, first_seen_unix, last_seen_unix, first_seen_at, last_seen_at)
        VALUES
        (:mac, :display_name, :hostname, :ip, :network_name, :link_type, :status, :source, :is_vpn, :vpn_name, :vpn_route_desc, :vpn_endpoint_host, :vpn_endpoint_port, :first_seen_unix, :last_seen_unix, FROM_UNIXTIME(:first_seen_unix), FROM_UNIXTIME(:last_seen_unix))
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            hostname = VALUES(hostname),
            ip = VALUES(ip),
            network_name = VALUES(network_name),
            link_type = VALUES(link_type),
            status = VALUES(status),
            source = VALUES(source),
            is_vpn = VALUES(is_vpn),
            vpn_name = VALUES(vpn_name),
            vpn_route_desc = VALUES(vpn_route_desc),
            vpn_endpoint_host = VALUES(vpn_endpoint_host),
            vpn_endpoint_port = VALUES(vpn_endpoint_port),
            first_seen_unix = VALUES(first_seen_unix),
            last_seen_unix = VALUES(last_seen_unix),
            first_seen_at = VALUES(first_seen_at),
            last_seen_at = VALUES(last_seen_at),
            updated_at = CURRENT_TIMESTAMP';

    $history_requires_manual_id_stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_device_history' AND COLUMN_NAME = 'id' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'");
    $history_requires_manual_id = (int) $history_requires_manual_id_stmt->fetchColumn() > 0;
    $next_history_id = $history_requires_manual_id ? (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM unifi_device_history')->fetchColumn() : null;

    $history_sql = $history_requires_manual_id
        ? 'INSERT INTO unifi_device_history
        (id, sync_id, event_type, mac, display_name, hostname, ip, network_name, link_type, status, source, is_vpn, vpn_name, vpn_route_desc, vpn_endpoint_host, vpn_endpoint_port, first_seen_unix, last_seen_unix)
        VALUES
        (:id, :sync_id, :event_type, :mac, :display_name, :hostname, :ip, :network_name, :link_type, :status, :source, :is_vpn, :vpn_name, :vpn_route_desc, :vpn_endpoint_host, :vpn_endpoint_port, :first_seen_unix, :last_seen_unix)'
        : 'INSERT INTO unifi_device_history
        (sync_id, event_type, mac, display_name, hostname, ip, network_name, link_type, status, source, is_vpn, vpn_name, vpn_route_desc, vpn_endpoint_host, vpn_endpoint_port, first_seen_unix, last_seen_unix)
        VALUES
        (:sync_id, :event_type, :mac, :display_name, :hostname, :ip, :network_name, :link_type, :status, :source, :is_vpn, :vpn_name, :vpn_route_desc, :vpn_endpoint_host, :vpn_endpoint_port, :first_seen_unix, :last_seen_unix)';

    $stmt = $pdo->prepare($sql);
    $history_stmt = $pdo->prepare($history_sql);
    $mark_missing_stmt = $pdo->prepare('UPDATE unifi_device_state SET status = :status, source = :source, updated_at = CURRENT_TIMESTAMP WHERE mac = :mac');

    $processed = 0;
    $history_seen = 0;
    $history_missing = 0;
    $current_macs = [];

    $pdo->beginTransaction();
    foreach ($rows_by_mac as $row) {
        $current_macs[normalize_mac($row['mac'])] = true;

        $stmt->execute([
            ':mac' => $row['mac'],
            ':display_name' => $row['display_name'],
            ':hostname' => $row['hostname'],
            ':ip' => $row['ip'],
            ':network_name' => $row['network_name'],
            ':link_type' => $row['link_type'],
            ':status' => $row['status'],
            ':source' => $row['source'],
            ':is_vpn' => $row['is_vpn'],
            ':vpn_name' => $row['vpn_name'],
            ':vpn_route_desc' => $row['vpn_route_desc'],
            ':vpn_endpoint_host' => $row['vpn_endpoint_host'],
            ':vpn_endpoint_port' => $row['vpn_endpoint_port'],
            ':first_seen_unix' => $row['first_seen_unix'],
            ':last_seen_unix' => $row['last_seen_unix'],
        ]);

        $history_params = [
            ':sync_id' => $sync_id,
            ':event_type' => 'seen',
            ':mac' => $row['mac'],
            ':display_name' => $row['display_name'],
            ':hostname' => $row['hostname'],
            ':ip' => $row['ip'],
            ':network_name' => $row['network_name'],
            ':link_type' => $row['link_type'],
            ':status' => $row['status'],
            ':source' => $row['source'],
            ':is_vpn' => $row['is_vpn'],
            ':vpn_name' => $row['vpn_name'],
            ':vpn_route_desc' => $row['vpn_route_desc'],
            ':vpn_endpoint_host' => $row['vpn_endpoint_host'],
            ':vpn_endpoint_port' => $row['vpn_endpoint_port'],
            ':first_seen_unix' => $row['first_seen_unix'],
            ':last_seen_unix' => $row['last_seen_unix'],
        ];
        if ($history_requires_manual_id) {
            $history_params[':id'] = $next_history_id++;
        }
        $history_stmt->execute($history_params);

        $history_seen++;
        $processed++;
    }

    foreach ($existing_by_mac as $mac_key => $old) {
        if (isset($current_macs[$mac_key])) {
            continue;
        }

        $old_status = strtolower((string) ($old['status'] ?? ''));
        if ($old_status !== 'missing') {
            $mark_missing_stmt->execute([
                ':status' => 'missing',
                ':source' => 'missing-from-unifi',
                ':mac' => $old['mac'],
            ]);

            $missing_history_params = [
                ':sync_id' => $sync_id,
                ':event_type' => 'missing',
                ':mac' => $old['mac'],
                ':display_name' => $old['display_name'] ?? '',
                ':hostname' => $old['hostname'] ?? '',
                ':ip' => $old['ip'] ?? '',
                ':network_name' => $old['network_name'] ?? '',
                ':link_type' => $old['link_type'] ?? '',
                ':status' => 'missing',
                ':source' => 'missing-from-unifi',
                ':is_vpn' => (int) ($old['is_vpn'] ?? 0),
                ':vpn_name' => $old['vpn_name'] ?? '',
                ':vpn_route_desc' => $old['vpn_route_desc'] ?? '',
                ':vpn_endpoint_host' => $old['vpn_endpoint_host'] ?? '',
                ':vpn_endpoint_port' => $old['vpn_endpoint_port'] !== null ? (int) $old['vpn_endpoint_port'] : null,
                ':first_seen_unix' => $old['first_seen_unix'] !== null ? (int) $old['first_seen_unix'] : null,
                ':last_seen_unix' => $old['last_seen_unix'] !== null ? (int) $old['last_seen_unix'] : null,
            ];
            if ($history_requires_manual_id) {
                $missing_history_params[':id'] = $next_history_id++;
            }
            $history_stmt->execute($missing_history_params);

            $history_missing++;
        }
    }

    $pdo->commit();

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'site' => $site,
        'sync_id' => $sync_id,
        'processed' => $processed,
        'history_seen' => $history_seen,
        'history_missing' => $history_missing,
        'vpn_targets' => count($vpn_target_map),
        'synced_at' => date('c'),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
}
