<?php
require_once 'vendor/autoload.php';

use UniFi_API\Client;
use UniFi_API\Exceptions\CurlExtensionNotLoadedException;
use UniFi_API\Exceptions\CurlGeneralErrorException;
use UniFi_API\Exceptions\CurlTimeoutException;
use UniFi_API\Exceptions\InvalidBaseUrlException;
use UniFi_API\Exceptions\InvalidSiteNameException;
use UniFi_API\Exceptions\JsonDecodeException;

function parse_wireguard_endpoint(?string $config): array
{
    if (!$config) {
        return ['host' => '', 'port' => ''];
    }

    if (!preg_match('/^Endpoint\s*=\s*(.+)$/mi', $config, $matches)) {
        return ['host' => '', 'port' => ''];
    }

    $endpoint = trim($matches[1]);
    $parts    = parse_url('wg://' . $endpoint);

    return [
        'host' => $parts['host'] ?? '',
        'port' => isset($parts['port']) ? (string) $parts['port'] : '',
    ];
}

function env_or_default(string $key, string $default): string
{
    return getenv($key) ?: $default;
}

$message = '';
$success = false;

function log_with_timestamp(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

try {
    $site           = $_ENV['site_name'];
    $controller_url = $_ENV['unifi_url'];
    $api_key        = $_ENV['unifi_api'];
    $unifi_connection = new Client('', '', $controller_url, $site);
    $unifi_connection->set_api_key($api_key);

    $db_host        = $_ENV['mysql_host'];
    $db_name        = $_ENV['mysql_db'];
    $db_user        = $_ENV['mysql_user'];
    $db_pass        = $_ENV['mysql_pass'];

    $networks     = $unifi_connection->list_networkconf();
    $vpn_networks = array_values(array_filter((array) $networks, function ($net) {
        return isset($net->purpose) && $net->purpose === 'vpn-client';
    }));

    $vpn_by_id = [];
    foreach ($vpn_networks as $net) {
        $vpn_by_id[$net->_id] = $net;
    }
    $vpn_network_ids = array_keys($vpn_by_id);

    $traffic_routes = $unifi_connection->custom_api_request('/v2/api/site/' . $site . '/trafficroutes');
    $vpn_routes     = array_values(array_filter((array) $traffic_routes, function ($route) use ($vpn_network_ids) {
        return isset($route->network_id) && in_array($route->network_id, $vpn_network_ids, true) && ($route->enabled ?? false);
    }));

    // Setup database connection
    $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Check if record with id=1 exists
    $checkStmt = $pdo->prepare('SELECT id FROM `unifi_vpn_state` WHERE `id` = 1');
    $checkStmt->execute();
    $recordExists = $checkStmt->fetch() !== false;

    // Determine whether to INSERT or UPDATE
    if ($recordExists) {
        $sql = 'UPDATE `unifi_vpn_state` SET 
                `vpn_name` = :vpn_name, 
                `vpn_type` = :vpn_type, 
                `ip` = :ip, 
                `port` = :port, 
                `network_id` = :network_id, 
                `route_desc` = :route_desc, 
                `route_enabled` = :route_enabled, 
                `kill_switch` = :kill_switch, 
                `target_count` = :target_count, 
                `routing_table_id` = :routing_table_id, 
                `ip_subnet` = :ip_subnet,
                `updated` = NOW()
                WHERE `id` = 1';
    } else {
        $sql = 'INSERT INTO `unifi_vpn_state` 
                (`id`, `vpn_name`, `vpn_type`, `ip`, `port`, `network_id`, `route_desc`, `route_enabled`, `kill_switch`, `target_count`, `routing_table_id`, `ip_subnet`, `updated`)
                VALUES (1, :vpn_name, :vpn_type, :ip, :port, :network_id, :route_desc, :route_enabled, :kill_switch, :target_count, :routing_table_id, :ip_subnet, NOW())';
    }
    
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    foreach ($vpn_routes as $route) {
        $net = $vpn_by_id[$route->network_id] ?? null;
        if (!$net) continue;

        $endpoint = parse_wireguard_endpoint($net->wireguard_client_configuration_file ?? '');

        $stmt->execute([
            ':vpn_name' => $net->name ?? '',
            ':vpn_type' => $net->vpn_type ?? '',
            ':ip' => $endpoint['host'],
            ':port' => !empty($endpoint['port']) ? (int) $endpoint['port'] : 0,
            ':network_id' => $route->network_id ?? '',
            ':route_desc' => $route->description ?? '',
            ':route_enabled' => ($route->enabled ?? false) ? 'yes' : 'no',
            ':kill_switch' => ($route->kill_switch_enabled ?? false) ? 1 : 0,
            ':target_count' => count($route->target_devices ?? []),
            ':routing_table_id' => !empty($net->routing_table_id) ? (int) $net->routing_table_id : 0,
            ':ip_subnet' => $net->ip_subnet ?? '',
        ]);

        $inserted++;
    }

    $message = $recordExists 
        ? "Successfully updated VPN route (ID: 1)." 
        : "Successfully inserted VPN route (ID: 1).";
    $success = true;

} catch (CurlExtensionNotLoadedException $e) {
    $message = 'Curl extension missing: ' . $e->getMessage();
} catch (InvalidBaseUrlException $e) {
    $message = 'Invalid controller URL: ' . $e->getMessage();
} catch (InvalidSiteNameException $e) {
    $message = 'Invalid site name: ' . $e->getMessage();
} catch (JsonDecodeException $e) {
    $message = 'JSON decode failed: ' . $e->getMessage();
} catch (CurlGeneralErrorException $e) {
    $message = 'cURL error: ' . $e->getMessage();
} catch (CurlTimeoutException $e) {
    $message = 'Controller timeout: ' . $e->getMessage();
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
}

if ($success) {
    log_with_timestamp('unifi_vpn_state.php completed: ' . $message);
    exit(0);
}

log_with_timestamp('unifi_vpn_state.php failed: ' . $message);
exit(1);
