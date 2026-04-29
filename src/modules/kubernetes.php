<?php

function kubernetesGetJson($kubeApi, $token, $caFile, $path)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $kubeApi . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
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
        throw new Exception('Kubernetes API request failed for ' . $path . ': HTTP ' . $statusCode . ' - ' . $message);
    }

    if (!is_array($data)) {
        throw new Exception('Invalid Kubernetes response for ' . $path);
    }

    return $data;
}

function getTraefikResources($kubeApi, $token, $caFile, $resource)
{
    $data = kubernetesGetJson($kubeApi, $token, $caFile, '/apis/traefik.io/v1alpha1/' . $resource);

    if ($data !== null) {
        return $data;
    }

    return kubernetesGetJson($kubeApi, $token, $caFile, '/apis/traefik.containo.us/v1alpha1/' . $resource);
}