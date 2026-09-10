<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Infrastructure;

use Al5dy\PayKassaWoo\PayKassa\Exception\PayKassaException;

/** Connection-bound mutex: unlike a TTL, a slow worker cannot lose it to a timer. */
final class DatabaseMutex
{
    /** @var array<string, bool> Reject reentrant settlement on this connection as well. */
    private static array $held = array();
    private string $name = '';

    public function acquire(string $resource): bool
    {
        global $wpdb;
        $database = $wpdb->get_var('SELECT DATABASE()');
        if (! is_string($database) || '' === $database) {
            throw new PayKassaException('Database context unavailable.');
        }
        $name = hash('sha256', $database . ':' . $wpdb->prefix . ':paykassa:' . $resource);
        if (isset(self::$held[$name])) {
            return false;
        }
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if (null === $result || '' !== $wpdb->last_error) {
            throw new PayKassaException('Database mutex unavailable.');
        }
        if ('1' !== (string) $result) {
            return false;
        }
        self::$held[$name] = true;
        $this->name = $name;
        return true;
    }

    public function assert_owned(): void
    {
        global $wpdb;
        if ('' === $this->name || '1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name))) {
            throw new PayKassaException('Database mutex ownership lost.');
        }
    }

    public function release(): void
    {
        global $wpdb;
        if ('' === $this->name) {
            return;
        }
        $result = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
        unset(self::$held[$this->name]);
        $this->name = '';
        if ('1' !== (string) $result) {
            throw new PayKassaException('Database mutex release failed.');
        }
    }
}
