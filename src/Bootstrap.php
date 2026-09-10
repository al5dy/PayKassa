<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo;

use Al5dy\PayKassaWoo\Support\Requirements;

final class Bootstrap
{
    public static function boot(): void
    {
        load_plugin_textdomain('paykassa', false, dirname(plugin_basename(PAYKASSA_FILE)) . '/languages');
        $requirements = new Requirements();
        if (! $requirements->is_met()) {
            $requirements->register_notice();
            return;
        }
        (new Plugin())->register();
    }
}
