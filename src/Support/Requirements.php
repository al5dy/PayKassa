<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Support;

final class Requirements
{
    public function is_met(): bool
    {
        return version_compare(PHP_VERSION, '8.1', '>=')
            && version_compare(get_bloginfo('version'), '6.6', '>=')
            && defined('WC_VERSION')
            && version_compare(WC_VERSION, '8.5', '>=');
    }

    public function register_notice(): void
    {
        add_action(
            'admin_notices',
            static function (): void {
                if (! current_user_can('activate_plugins')) {
                    return;
                }
                echo '<div class="notice notice-error"><p>' . esc_html__('PayKassa for WooCommerce requires PHP 8.1+, WordPress 6.6+, and WooCommerce 8.5+.', 'paykassa') . '</p></div>';
            }
        );
    }
}
