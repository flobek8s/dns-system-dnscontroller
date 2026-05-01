<?php
require_once 'vendor/autoload.php';

use UniFi_API\Client;
use UniFi_API\Exceptions\CurlExtensionNotLoadedException;
use UniFi_API\Exceptions\CurlGeneralErrorException;
use UniFi_API\Exceptions\CurlTimeoutException;
use UniFi_API\Exceptions\InvalidBaseUrlException;
use UniFi_API\Exceptions\InvalidSiteNameException;
use UniFi_API\Exceptions\JsonDecodeException;

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

    $firmware = $unifi_connection->stat_sysinfo();
    $sys = $firmware[0] ?? null;
    if (!$sys) {
        throw new Exception('No sysinfo returned from UniFi controller.');
    }

    $target_network_id = $_ENV['target_network_id'];
    $network    = $unifi_connection->list_networkconf($target_network_id);
    $lan_network = $network[0] ?? null;

    // Collect all values
    $name            = $sys->name ?? '';
    $hostname        = $sys->hostname ?? '';
    $device_type     = $sys->ubnt_device_type ?? '';
    $network_name    = $lan_network->name ?? '';
    $domain_name     = $lan_network->domain_name ?? '';
    $network_version = $sys->version ?? '';
    $network_build   = $sys->build ?? '';
    $udm_version     = $sys->udm_version ?? '';
    $udm_display_version = $sys->console_display_version ?? '';
    $ip_subnet       = $lan_network->ip_subnet ?? '';
    $dns             = $lan_network->dhcpd_dns_1 ?? '';
    $dhcp_start      = $lan_network->dhcpd_start ?? '';
    $dhcp_end        = $lan_network->dhcpd_stop ?? '';

    // Setup database connection
    $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Check if record with id=1 exists
    $checkStmt = $pdo->prepare('SELECT id FROM `unifi_state` WHERE `id` = 1');
    $checkStmt->execute();
    $recordExists = $checkStmt->fetch() !== false;

    $params = [
        ':name'            => $name,
        ':hostname'        => $hostname,
        ':device_type'     => $device_type,
        ':network_name'    => $network_name,
        ':domain_name'     => $domain_name,
        ':network_version' => $network_version,
        ':network_build'   => $network_build,
        ':udm_version'     => $udm_version,
        ':udm_display_version' => $udm_display_version,
        ':ip_subnet'       => $ip_subnet,
        ':dns'             => $dns,
        ':dhcp_start'      => $dhcp_start,
        ':dhcp_end'        => $dhcp_end,
    ];

    if ($recordExists) {
        $sql = 'UPDATE `unifi_state` SET
                `name` = :name,
                `hostname` = :hostname,
                `device_type` = :device_type,
                `network_name` = :network_name,
                `domain_name` = :domain_name,
                `network_version` = :network_version,
                `network_build` = :network_build,
                `udm_version` = :udm_version,
                `udm_display_version` = :udm_display_version,
                `ip_subnet` = :ip_subnet,
                `dns` = :dns,
                `dhcp_start` = :dhcp_start,
                `dhcp_end` = :dhcp_end,
                `updated` = NOW()
                WHERE `id` = 1';
        $message = 'Successfully updated router state (ID: 1).';
    } else {
        $sql = 'INSERT INTO `unifi_state`
                (`id`, `name`, `hostname`, `device_type`, `network_name`, `domain_name`, `network_version`, `network_build`, `udm_version`, `udm_display_version`, `ip_subnet`, `dns`, `dhcp_start`, `dhcp_end`, `updated`)
                VALUES (1, :name, :hostname, :device_type, :network_name, :domain_name, :network_version, :network_build, :udm_version, :udm_display_version, :ip_subnet, :dns, :dhcp_start, :dhcp_end, NOW())';
        $message = 'Successfully inserted router state (ID: 1).';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    log_with_timestamp('unifi_state.php completed: ' . $message);
    exit(0);
}

log_with_timestamp('unifi_state.php failed: ' . $message);
exit(1);