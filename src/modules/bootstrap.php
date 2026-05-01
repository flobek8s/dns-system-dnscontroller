<?php

function loadConfig()
{
    return [
        'db_host' => $_ENV['mysql_host'],
        'db_user' => $_ENV['mysql_user'],
        'db_pass' => $_ENV['mysql_pass'],
        'db_name' => $_ENV['mysql_db'],
        'interval_seconds' => intval($_ENV['poll_interval'] ?? '300'),
        'token_file' => '/var/run/secrets/kubernetes.io/serviceaccount/token',
        'ca_file' => '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt',
        'kube_api' => 'https://kubernetes.default.svc',
        'pihole_url' => $_ENV['pihole_url'] ?? '',
        'pihole_pass' => $_ENV['pihole_pass'] ?? '',
        'pihole_verify_ssl' => filter_var($_ENV['pihole_verify_ssl'] ?? 'false', FILTER_VALIDATE_BOOL),
    ];
}

function createPdo(array $config)
{
    return new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function getKubernetesToken($tokenFile)
{
    $token = file_get_contents($tokenFile);
    if ($token === false || $token === '') {
        throw new Exception('Unable to read Kubernetes service account token');
    }

    return $token;
}