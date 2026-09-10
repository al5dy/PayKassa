<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]/', '', $key) ?? '');
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return false;
    }
    function set_transient(string $key, $value, int $expiration): bool
    {
        return true;
    }
    function apply_filters(string $hook, $value)
    {
        return $value;
    }
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
