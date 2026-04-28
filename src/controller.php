<?php

// -----------------------------
// CONFIG (use env in real setup if you want later)
// -----------------------------
define('DB_HOST', $_ENV['mysql_host']);
define('DB_USER', $_ENV['mysql_user']);
define('DB_PASS', $_ENV['mysql_pass']);
define('DB_NAME', $_ENV['mysql_db']);

$interval = 60;

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

// -----------------------------
// MAIN LOOP (POLLING MODE)
// -----------------------------
while (true) {

    try {

        echo "[" . date('Y-m-d H:i:s') . "] Sync starting...\n";

        // -----------------------------
        // FETCH INGRESSES
        // -----------------------------
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "$kubeApi/apis/networking.k8s.io/v1/ingresses",
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

        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['items'])) {
            throw new Exception("Invalid Kubernetes response");
        }

        // -----------------------------
        // EXTRACT HOSTS
        // -----------------------------
        $stmt = $pdo->prepare("
            INSERT INTO k8s_ingress_dns (namespace, ingress_name, domain, last_seen)
            VALUES (:namespace, :ingress, :domain, NOW())
            ON DUPLICATE KEY UPDATE
                last_seen = NOW(),
                namespace = VALUES(namespace),
                ingress_name = VALUES(ingress_name)
        ");

        $count = 0;

        foreach ($data['items'] as $ingress) {

            $namespace = $ingress['metadata']['namespace'] ?? 'default';
            $name = $ingress['metadata']['name'] ?? 'unknown';

            if (!isset($ingress['spec']['rules'])) {
                continue;
            }

            foreach ($ingress['spec']['rules'] as $rule) {

                if (!isset($rule['host'])) {
                    continue;
                }

                $domain = $rule['host'];

                $stmt->execute([
                    ':namespace' => $namespace,
                    ':ingress' => $name,
                    ':domain' => $domain
                ]);

                $count++;
            }
        }

        echo "[" . date('Y-m-d H:i:s') . "] Synced $count domains\n";

    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}