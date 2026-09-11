<?php

declare(strict_types=1);

use PayKassaWoo\Tools\HistoryContractProbe;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/HistoryContractProbe.php';

try {
    $options = getopt('', array('from:', 'to:', 'page:'));
    $options = is_array($options) ? $options : array();
    $page = filter_var($options['page'] ?? '0', FILTER_VALIDATE_INT);
    if (! is_string($options['from'] ?? null) || ! is_string($options['to'] ?? null) || false === $page) {
        throw new RuntimeException('required_arguments_from_to_optional_page');
    }
    $config = array();
    foreach (array('api_id', 'api_password', 'shop_id') as $key) {
        $value = getenv('PAYKASSA_SANDBOX_' . strtoupper($key));
        $config[$key] = false === $value ? '' : $value;
    }
    $report = HistoryContractProbe::run($config, $options['from'], $options['to'], $page);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    $allowed = array('invalid_json', 'invalid_envelope', 'provider_rejected', 'invalid_history_list', 'invalid_page_count', 'missing_sandbox_credentials', 'invalid_range_or_page', 'curl_required', 'transport_unavailable', 'transport_failed', 'required_arguments_from_to_optional_page');
    $code = in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'probe_failed';
    fwrite(STDERR, json_encode(array('status' => 'blocked', 'release_ready' => false, 'reason' => $code)) . "\n");
    exit(1);
}
