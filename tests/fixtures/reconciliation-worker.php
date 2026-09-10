<?php

declare(strict_types=1);

use Al5dy\PayKassaWoo\Infrastructure\Logger;
use Al5dy\PayKassaWoo\PayKassa\PayKassaClientFactory;
use Al5dy\PayKassaWoo\Webhook\WebhookEventStore;
use Al5dy\PayKassaWoo\Webhook\WebhookProcessor;

$site = isset($argv[1]) ? realpath($argv[1]) : false;
if (! is_string($site) || ! str_starts_with($site, '/tmp/paykassa-integration.')) {
    throw new RuntimeException('Only the disposable test site is allowed.');
}
require $site . '/wp-load.php';
if (! defined('PAYKASSA_TEST_DATABASE') || ! PAYKASSA_TEST_DATABASE || ! str_starts_with(DB_NAME, 'paykassa_test_')) {
    throw new RuntimeException('Only a disposable test database is allowed.');
}
$fixture = get_option('paykassa_test_worker');
add_filter('pre_http_request', static function ($preempt, array $args, string $url) use ($fixture): array {
    if ('https://paykassa.app/sci/0.4/index.php' !== $url || 'sci_confirm_order' !== $args['body']['func']) {
        throw new RuntimeException('Unexpected worker HTTP request.');
    }
    return array('body' => wp_json_encode(array('error' => false, 'message' => '', 'data' => $fixture['response'])), 'response' => array('code' => 200));
}, 10, 3);
add_action('woocommerce_pre_payment_complete', static function (): void {
    echo "WORKER_READY\n";
    flush();
    stream_set_timeout(STDIN, 20);
    if ("resume\n" !== fgets(STDIN)) {
        throw new RuntimeException('Worker resume barrier failed.');
    }
});
$evidence = (new PayKassaClientFactory())->sci(get_option('woocommerce_paykassa_settings'))->verify_ipn($fixture['token']);
$result = (new WebhookProcessor(new WebhookEventStore(), new Logger()))->process($evidence);
echo 'WORKER_RESULT=' . wp_json_encode($result) . "\n";
