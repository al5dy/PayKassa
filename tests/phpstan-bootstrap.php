<?php

declare(strict_types=1);

foreach (
    array(
        'ABSPATH' => __DIR__ . '/phpstan-wp-root/',
        'PAYKASSA_DIR' => dirname(__DIR__) . '/',
        'PAYKASSA_FILE' => '/tmp/paykassa.php',
        'PAYKASSA_URL' => 'https://example.invalid/wp-content/plugins/paykassa/',
        'PAYKASSA_VERSION' => '2.0.1',
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS' => 3600,
        'DAY_IN_SECONDS' => 86400,
    ) as $name => $value
) {
    if (! defined($name)) {
        define($name, $value);
    }
}
