<?php
require_once 'vendor/autoload.php';

use UniFi_API\Client;

function env_or_default(string $key, string $default = ''): string
{
    $value = getenv($key);

    return $value === false ? $default : $value;
}

function normalize_scalar($value)
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return (string) $value;
    }

    return trim((string) $value);
}

function values_differ($a, $b): bool
{
    return normalize_scalar($a) !== normalize_scalar($b);
}

function resolve_grpcurl_binary(): string
{
    $env_override = getenv('STARLINK_GRPCURL_BIN');
    if (is_string($env_override) && $env_override !== '' && is_executable($env_override)) {
        return $env_override;
    }

    $candidates = [
        '/usr/bin/grpcurl',
        '/usr/local/bin/grpcurl',
        '/snap/bin/grpcurl',
    ];

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    $from_path = shell_exec('command -v grpcurl 2>/dev/null');
    if (is_string($from_path)) {
        $from_path = trim($from_path);
        if ($from_path !== '') {
            return $from_path;
        }
    }

    return '';
}

function extract_first_json_object(string $raw): string
{
    $raw = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;

    $start = strpos($raw, '{');
    if ($start === false) {
        return trim($raw);
    }

    $depth = 0;
    $in_string = false;
    $escaped = false;
    $length = strlen($raw);

    for ($i = $start; $i < $length; $i++) {
        $ch = $raw[$i];

        if ($in_string) {
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                $in_string = false;
            }

            continue;
        }

        if ($ch === '"') {
            $in_string = true;
            continue;
        }

        if ($ch === '{') {
            $depth++;
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($raw, $start, $i - $start + 1);
            }
        }
    }

    return trim($raw);
}

function sanitize_protobuf_json(string $json): string
{
    $clean = preg_replace('/\\bNaN\\b/', 'null', $json);
    $clean = preg_replace('/\\b-Infinity\\b/', 'null', $clean ?? $json);
    $clean = preg_replace('/\\bInfinity\\b/', 'null', $clean ?? $json);

    return $clean ?? $json;
}

function fetch_starlink_grpc_status(array &$errors): array
{
    $grpcurl_bin = resolve_grpcurl_binary();
    if ($grpcurl_bin === '') {
        $errors[] = 'grpcurl binary not found for runtime.';

        return ['ok' => false, 'parsed' => null, 'raw' => ''];
    }

    $timeout_s = (int) env_or_default('STARLINK_GRPC_TIMEOUT', '4');
    if ($timeout_s < 1) {
        $timeout_s = 1;
    }

    $payload = json_encode(['get_status' => new stdClass()]);
    $command = 'timeout -s KILL ' . $timeout_s . 's ' . escapeshellarg($grpcurl_bin)
        . ' -plaintext -d ' . escapeshellarg($payload)
        . ' 192.168.100.1:9200 SpaceX.API.Device.Device/Handle 2>/dev/null';

    $raw = shell_exec($command);
    if (!is_string($raw) || trim($raw) === '') {
        $debug = shell_exec(
            'timeout -s KILL ' . $timeout_s . 's ' . escapeshellarg($grpcurl_bin)
            . ' -plaintext -d ' . escapeshellarg($payload)
            . ' 192.168.100.1:9200 SpaceX.API.Device.Device/Handle 2>&1'
        );
        $errors[] = 'No response from Starlink gRPC get_status.';

        return ['ok' => false, 'parsed' => null, 'raw' => (string) $debug];
    }

    $json_payload = sanitize_protobuf_json(extract_first_json_object($raw));
    $parsed = json_decode($json_payload, true);
    if (!is_array($parsed)) {
        $errors[] = 'Failed to parse Starlink gRPC JSON: ' . json_last_error_msg();

        return ['ok' => false, 'parsed' => null, 'raw' => $raw];
    }

    return ['ok' => true, 'parsed' => $parsed, 'raw' => $raw];
}

function fetch_public_ip(array &$errors): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'header' => "User-Agent: unifi-starlink-sync/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents('https://api64.ipify.org/', false, $context);
    if (!is_string($raw)) {
        $errors[] = 'Unable to query https://api64.ipify.org/.';

        return '';
    }

    return trim($raw);
}

function array_get(array $data, string $path, $default = null)
{
    $parts = explode('.', $path);
    $current = $data;

    foreach ($parts as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return $default;
        }

        $current = $current[$part];
    }

    return $current;
}

function table_requires_manual_id(PDO $pdo, string $table_name): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = 'id' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'");
    $stmt->execute([':table_name' => $table_name]);

    return (int) $stmt->fetchColumn() > 0;
}

function next_table_id(PDO $pdo, string $table_name): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM ' . $table_name)->fetchColumn();
}

try {
    $errors = [];

    $site           = env_or_default('UNIFI_SITE', 'default');
    $controller_url = $_ENV['unifi_url'];
    $api_key        = $_ENV['unifi_api'];
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

    $devices = (array) $client->list_devices();
    $monthly_gateway_usage = (array) $client->stat_monthly_gateway(null, null, ['wan-tx_bytes', 'wan-rx_bytes']);
    $first_device = $devices[0] ?? null;

    $isp = '';
    $city = '';
    if (is_object($first_device) && isset($first_device->geo_info) && isset($first_device->geo_info->{"WAN"})) {
        $isp = (string) ($first_device->geo_info->{"WAN"}->isp_name ?? '');
        $city = (string) ($first_device->geo_info->{"WAN"}->city ?? '');
    }

    $grpc = fetch_starlink_grpc_status($errors);
    $dish_status = is_array($grpc['parsed']) ? (array) array_get($grpc['parsed'], 'dishGetStatus', []) : [];

    $dish_id = (string) array_get($dish_status, 'deviceInfo.id', '');
    $hardware_version = (string) array_get($dish_status, 'deviceInfo.hardwareVersion', '');
    $software_version = (string) array_get($dish_status, 'deviceInfo.softwareVersion', '');
    $boot_count = array_get($dish_status, 'deviceInfo.bootcount', null);
    $uptime_seconds = array_get($dish_status, 'deviceState.uptimeS', null);
    $pop_ping_latency_ms = array_get($dish_status, 'popPingLatencyMs', null);
    $obstruction_fraction = array_get($dish_status, 'obstructionStats.fractionObstructed', null);

    $public_ip = fetch_public_ip($errors);

    $latest_month_usage = null;
    foreach ($monthly_gateway_usage as $usage_row) {
        if (!is_object($usage_row)) {
            continue;
        }

        if (!isset($usage_row->{"wan-rx_bytes"}) && !isset($usage_row->{"wan-tx_bytes"})) {
            continue;
        }

        $latest_month_usage = $usage_row;
    }

    $monthly_data_usage_bytes = null;
    if (is_object($latest_month_usage)) {
        $rx = isset($latest_month_usage->{"wan-rx_bytes"}) && is_numeric($latest_month_usage->{"wan-rx_bytes"})
            ? (float) $latest_month_usage->{"wan-rx_bytes"}
            : 0.0;
        $tx = isset($latest_month_usage->{"wan-tx_bytes"}) && is_numeric($latest_month_usage->{"wan-tx_bytes"})
            ? (float) $latest_month_usage->{"wan-tx_bytes"}
            : 0.0;
        $monthly_data_usage_bytes = $rx + $tx;
    }

    $row = [
        'isp' => $isp,
        'city' => $city,
        'dish_id' => $dish_id,
        'hardware_version' => $hardware_version,
        'software_version' => $software_version,
        'boot_count' => is_numeric($boot_count) ? (int) $boot_count : null,
        'uptime_seconds' => is_numeric($uptime_seconds) ? (int) $uptime_seconds : null,
        'pop_ping_latency_ms' => is_numeric($pop_ping_latency_ms) ? (float) $pop_ping_latency_ms : null,
        'obstruction_fraction' => is_numeric($obstruction_fraction) ? (float) $obstruction_fraction : null,
        'obstruction_percent' => is_numeric($obstruction_fraction) ? ((float) $obstruction_fraction * 100) : null,
        'monthly_data_usage_bytes' => $monthly_data_usage_bytes,
        'public_ip' => $public_ip,
    ];

    $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_state (
        id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        isp VARCHAR(120) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        dish_id VARCHAR(80) NOT NULL DEFAULT '',
        hardware_version VARCHAR(120) NOT NULL DEFAULT '',
        software_version VARCHAR(160) NOT NULL DEFAULT '',
        boot_count INT UNSIGNED DEFAULT NULL,
        uptime_seconds BIGINT UNSIGNED DEFAULT NULL,
        pop_ping_latency_ms DECIMAL(10,3) DEFAULT NULL,
        obstruction_fraction DECIMAL(10,6) DEFAULT NULL,
        obstruction_percent DECIMAL(10,3) DEFAULT NULL,
        monthly_data_usage_bytes DECIMAL(20,3) DEFAULT NULL,
        monthly_data_usage_mb DECIMAL(20,3) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1000000) STORED,
        monthly_data_usage_tb DECIMAL(20,6) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1000000000000) STORED,
        public_ip VARCHAR(45) NOT NULL DEFAULT '',
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Keep existing installations aligned with decimal MB/TB expressions.
    try {
        $pdo->exec("ALTER TABLE starlink_state
            MODIFY COLUMN monthly_data_usage_mb DECIMAL(20,3) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1000000) STORED,
            MODIFY COLUMN monthly_data_usage_tb DECIMAL(20,6) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1000000000000) STORED");
    } catch (Throwable $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_state_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_id VARCHAR(40) NOT NULL,
        isp VARCHAR(120) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        dish_id VARCHAR(80) NOT NULL DEFAULT '',
        hardware_version VARCHAR(120) NOT NULL DEFAULT '',
        software_version VARCHAR(160) NOT NULL DEFAULT '',
        boot_count INT UNSIGNED DEFAULT NULL,
        uptime_seconds BIGINT UNSIGNED DEFAULT NULL,
        pop_ping_latency_ms DECIMAL(10,3) DEFAULT NULL,
        obstruction_fraction DECIMAL(10,6) DEFAULT NULL,
        obstruction_percent DECIMAL(10,3) DEFAULT NULL,
        monthly_data_usage_bytes DECIMAL(20,3) DEFAULT NULL,
        monthly_data_usage_mb DECIMAL(20,3) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1048576) STORED,
        monthly_data_usage_tb DECIMAL(20,6) GENERATED ALWAYS AS (monthly_data_usage_bytes / 1099511627776) STORED,
        public_ip VARCHAR(45) NOT NULL DEFAULT '',
        change_summary VARCHAR(255) NOT NULL DEFAULT '',
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at),
        KEY idx_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_boot_count_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_id VARCHAR(40) NOT NULL,
        old_boot_count INT UNSIGNED DEFAULT NULL,
        new_boot_count INT UNSIGNED DEFAULT NULL,
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at),
        KEY idx_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_public_ip_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_id VARCHAR(40) NOT NULL,
        old_public_ip VARCHAR(45) NOT NULL DEFAULT '',
        new_public_ip VARCHAR(45) NOT NULL DEFAULT '',
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at),
        KEY idx_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_software_version_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_id VARCHAR(40) NOT NULL,
        old_software_version VARCHAR(160) NOT NULL DEFAULT '',
        new_software_version VARCHAR(160) NOT NULL DEFAULT '',
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at),
        KEY idx_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS starlink_monthly_data_usage_changes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_id VARCHAR(40) NOT NULL,
        old_monthly_data_usage_bytes DECIMAL(20,3) DEFAULT NULL,
        old_monthly_data_usage_mb DECIMAL(20,3) GENERATED ALWAYS AS (old_monthly_data_usage_bytes / 1048576) STORED,
        old_monthly_data_usage_tb DECIMAL(20,6) GENERATED ALWAYS AS (old_monthly_data_usage_bytes / 1099511627776) STORED,
        new_monthly_data_usage_bytes DECIMAL(20,3) DEFAULT NULL,
        new_monthly_data_usage_mb DECIMAL(20,3) GENERATED ALWAYS AS (new_monthly_data_usage_bytes / 1048576) STORED,
        new_monthly_data_usage_tb DECIMAL(20,6) GENERATED ALWAYS AS (new_monthly_data_usage_bytes / 1099511627776) STORED,
        observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_observed_at (observed_at),
        KEY idx_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $manual_id_tables = [
        'starlink_state_history',
        'starlink_boot_count_changes',
        'starlink_public_ip_changes',
        'starlink_software_version_changes',
        'starlink_monthly_data_usage_changes',
    ];
    $manual_id_required = [];
    $next_manual_ids = [];
    foreach ($manual_id_tables as $table_name) {
        $manual_id_required[$table_name] = table_requires_manual_id($pdo, $table_name);
        if ($manual_id_required[$table_name]) {
            $next_manual_ids[$table_name] = next_table_id($pdo, $table_name);
        }
    }

    $old = $pdo->query('SELECT * FROM starlink_state WHERE id = 1 LIMIT 1')->fetch();

    $stmt_upsert = $pdo->prepare("INSERT INTO starlink_state (
        id, isp, city, dish_id, hardware_version, software_version, boot_count,
        uptime_seconds, pop_ping_latency_ms, obstruction_fraction, obstruction_percent,
        monthly_data_usage_bytes, public_ip, observed_at
    ) VALUES (
        1, :isp, :city, :dish_id, :hardware_version, :software_version, :boot_count,
        :uptime_seconds, :pop_ping_latency_ms, :obstruction_fraction, :obstruction_percent,
        :monthly_data_usage_bytes, :public_ip, NOW()
    ) ON DUPLICATE KEY UPDATE
        isp = VALUES(isp),
        city = VALUES(city),
        dish_id = VALUES(dish_id),
        hardware_version = VALUES(hardware_version),
        software_version = VALUES(software_version),
        boot_count = VALUES(boot_count),
        uptime_seconds = VALUES(uptime_seconds),
        pop_ping_latency_ms = VALUES(pop_ping_latency_ms),
        obstruction_fraction = VALUES(obstruction_fraction),
        obstruction_percent = VALUES(obstruction_percent),
        monthly_data_usage_bytes = VALUES(monthly_data_usage_bytes),
        public_ip = VALUES(public_ip),
        observed_at = NOW()
    ");
    $stmt_upsert->execute($row);

    // History is written only when these stable fields changed.
    $tracked = ['isp', 'city', 'dish_id', 'hardware_version', 'software_version', 'boot_count', 'public_ip', 'monthly_data_usage_bytes'];
    $changed_fields = [];

    if ($old === false) {
        $changed_fields[] = 'first_insert';
    } else {
        foreach ($tracked as $key) {
            if (values_differ($old[$key] ?? null, $row[$key] ?? null)) {
                $changed_fields[] = $key;
            }
        }
    }

    if (!empty($changed_fields)) {
        $history_requires_manual_id = $manual_id_required['starlink_state_history'] ?? false;
        $stmt_hist = $pdo->prepare($history_requires_manual_id
            ? "INSERT INTO starlink_state_history (
            id, sync_id, isp, city, dish_id, hardware_version, software_version,
            boot_count, uptime_seconds, pop_ping_latency_ms, obstruction_fraction,
            obstruction_percent, monthly_data_usage_bytes, public_ip, change_summary
        ) VALUES (
            :id, :sync_id, :isp, :city, :dish_id, :hardware_version, :software_version,
            :boot_count, :uptime_seconds, :pop_ping_latency_ms, :obstruction_fraction,
            :obstruction_percent, :monthly_data_usage_bytes, :public_ip, :change_summary
        )"
            : "INSERT INTO starlink_state_history (
            sync_id, isp, city, dish_id, hardware_version, software_version,
            boot_count, uptime_seconds, pop_ping_latency_ms, obstruction_fraction,
            obstruction_percent, monthly_data_usage_bytes, public_ip, change_summary
        ) VALUES (
            :sync_id, :isp, :city, :dish_id, :hardware_version, :software_version,
            :boot_count, :uptime_seconds, :pop_ping_latency_ms, :obstruction_fraction,
            :obstruction_percent, :monthly_data_usage_bytes, :public_ip, :change_summary
        )");

        $hist_data = $row;
        $hist_data['sync_id'] = $sync_id;
        $hist_data['change_summary'] = implode(',', $changed_fields);
        if ($history_requires_manual_id) {
            $hist_data['id'] = $next_manual_ids['starlink_state_history']++;
        }
        $stmt_hist->execute($hist_data);

        if ($old !== false && in_array('boot_count', $changed_fields, true)) {
            $boot_changes_requires_manual_id = $manual_id_required['starlink_boot_count_changes'] ?? false;
            $stmt = $pdo->prepare($boot_changes_requires_manual_id
                ? 'INSERT INTO starlink_boot_count_changes (id, sync_id, old_boot_count, new_boot_count) VALUES (:id, :sync_id, :old_boot_count, :new_boot_count)'
                : 'INSERT INTO starlink_boot_count_changes (sync_id, old_boot_count, new_boot_count) VALUES (:sync_id, :old_boot_count, :new_boot_count)');
            $data = [
                'sync_id' => $sync_id,
                'old_boot_count' => is_numeric($old['boot_count'] ?? null) ? (int) $old['boot_count'] : null,
                'new_boot_count' => $row['boot_count'],
            ];
            if ($boot_changes_requires_manual_id) {
                $data['id'] = $next_manual_ids['starlink_boot_count_changes']++;
            }
            $stmt->execute($data);
        }

        if ($old !== false && in_array('public_ip', $changed_fields, true)) {
            $public_ip_changes_requires_manual_id = $manual_id_required['starlink_public_ip_changes'] ?? false;
            $stmt = $pdo->prepare($public_ip_changes_requires_manual_id
                ? 'INSERT INTO starlink_public_ip_changes (id, sync_id, old_public_ip, new_public_ip) VALUES (:id, :sync_id, :old_public_ip, :new_public_ip)'
                : 'INSERT INTO starlink_public_ip_changes (sync_id, old_public_ip, new_public_ip) VALUES (:sync_id, :old_public_ip, :new_public_ip)');
            $data = [
                'sync_id' => $sync_id,
                'old_public_ip' => (string) ($old['public_ip'] ?? ''),
                'new_public_ip' => (string) $row['public_ip'],
            ];
            if ($public_ip_changes_requires_manual_id) {
                $data['id'] = $next_manual_ids['starlink_public_ip_changes']++;
            }
            $stmt->execute($data);
        }

        if ($old !== false && in_array('software_version', $changed_fields, true)) {
            $software_changes_requires_manual_id = $manual_id_required['starlink_software_version_changes'] ?? false;
            $stmt = $pdo->prepare($software_changes_requires_manual_id
                ? 'INSERT INTO starlink_software_version_changes (id, sync_id, old_software_version, new_software_version) VALUES (:id, :sync_id, :old_software_version, :new_software_version)'
                : 'INSERT INTO starlink_software_version_changes (sync_id, old_software_version, new_software_version) VALUES (:sync_id, :old_software_version, :new_software_version)');
            $data = [
                'sync_id' => $sync_id,
                'old_software_version' => (string) ($old['software_version'] ?? ''),
                'new_software_version' => (string) $row['software_version'],
            ];
            if ($software_changes_requires_manual_id) {
                $data['id'] = $next_manual_ids['starlink_software_version_changes']++;
            }
            $stmt->execute($data);
        }

        if ($old !== false && in_array('monthly_data_usage_bytes', $changed_fields, true)) {
            $monthly_usage_changes_requires_manual_id = $manual_id_required['starlink_monthly_data_usage_changes'] ?? false;
            $stmt = $pdo->prepare($monthly_usage_changes_requires_manual_id
                ? 'INSERT INTO starlink_monthly_data_usage_changes (id, sync_id, old_monthly_data_usage_bytes, new_monthly_data_usage_bytes) VALUES (:id, :sync_id, :old_monthly_data_usage_bytes, :new_monthly_data_usage_bytes)'
                : 'INSERT INTO starlink_monthly_data_usage_changes (sync_id, old_monthly_data_usage_bytes, new_monthly_data_usage_bytes) VALUES (:sync_id, :old_monthly_data_usage_bytes, :new_monthly_data_usage_bytes)');
            $data = [
                'sync_id' => $sync_id,
                'old_monthly_data_usage_bytes' => is_numeric($old['monthly_data_usage_bytes'] ?? null) ? (float) $old['monthly_data_usage_bytes'] : null,
                'new_monthly_data_usage_bytes' => is_numeric($row['monthly_data_usage_bytes'] ?? null) ? (float) $row['monthly_data_usage_bytes'] : null,
            ];
            if ($monthly_usage_changes_requires_manual_id) {
                $data['id'] = $next_manual_ids['starlink_monthly_data_usage_changes']++;
            }
            $stmt->execute($data);
        }
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'sync_id' => $sync_id,
        'state_upserted' => true,
        'history_written' => !empty($changed_fields),
        'changed_fields' => $changed_fields,
        'errors' => $errors,
        'state' => $row,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Exception $e) {
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
}
