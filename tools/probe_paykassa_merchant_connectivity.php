<?php

declare(strict_types=1);

use PayKassaWoo\Tools\MerchantConnectivityProbe;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/MerchantConnectivityProbe.php';

$env_file = dirname(__DIR__) . '/.env';
if (is_file($env_file) && is_readable($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#') || false === strpos($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ('' !== $key && false === getenv($key)) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = array();
foreach (array(
    'shop_id' => 'PAYKASSA_SANDBOX_SHOP_ID',
    'shop_password' => 'PAYKASSA_SANDBOX_SHOP_PASSWORD',
    'api_id' => 'PAYKASSA_SANDBOX_API_ID',
    'api_password' => 'PAYKASSA_SANDBOX_API_PASSWORD',
    'public_site_url' => 'PAYKASSA_PUBLIC_SITE_URL',
    'system_id' => 'PAYKASSA_PROBE_SYSTEM_ID',
    'currency' => 'PAYKASSA_PROBE_CURRENCY',
    'amount' => 'PAYKASSA_PROBE_AMOUNT',
    'api_test_mode' => 'PAYKASSA_PROBE_API_TEST_MODE',
) as $key => $env_name) {
    $value = getenv($env_name);
    $config[$key] = false === $value ? '' : $value;
}

if ('' === $config['shop_id'] && '' === $config['api_id'] && '' === $config['public_site_url']) {
    fwrite(STDERR, json_encode(array(
        'status' => 'blocked',
        'release_ready' => false,
        'reason' => 'no_configuration_found',
        'hint' => 'Create .env from .env.example, or run scripts/export-merchant-env.sh',
    )) . "\n");
    exit(1);
}

$report = MerchantConnectivityProbe::run($config);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

$failed = false;
foreach (array('public_site', 'webhook_endpoint', 'api_connectivity', 'sci_test_invoice') as $section) {
    $row = $report[$section];
    if (! $row['checked']) {
        continue;
    }
    if (isset($row['reachable']) && false === $row['reachable']) {
        $failed = true;
    }
    if (isset($row['route_reachable']) && false === $row['route_reachable']) {
        $failed = true;
    }
    if (isset($row['connected']) && false === $row['connected']) {
        $failed = true;
    }
    if (isset($row['created']) && false === $row['created']) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
