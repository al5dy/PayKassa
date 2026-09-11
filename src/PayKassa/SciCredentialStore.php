<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa;

use Al5dy\PayKassaWoo\Infrastructure\DatabaseMutex;
use Al5dy\PayKassaWoo\PayKassa\Exception\ConfigurationException;

/** Retains versioned SCI credentials required by immutable unfinished invoices. */
final class SciCredentialStore
{
    public const OPTION = 'paykassa_sci_credential_profiles';

    public function register_rotation_guard(): void
    {
        add_filter('pre_update_option_woocommerce_paykassa_settings', array($this, 'retain_before_settings_update'), 10, 2);
    }

    /** @param mixed $new_value @param mixed $old_value @return mixed */
    public function retain_before_settings_update($new_value, $old_value)
    {
        if (
            is_array($old_value)
            && isset($old_value['shop_id'], $old_value['shop_password'])
            && is_string($old_value['shop_id'])
            && is_string($old_value['shop_password'])
            && '' !== $old_value['shop_id']
            && '' !== $old_value['shop_password']
        ) {
            $this->retain($old_value, true);
        }
        return $new_value;
    }

    /**
     * @param array<string, mixed> $settings
     * @return string Secret-specific immutable credential context.
     */
    public function retain(array $settings, bool $seed_legacy_alias = false): string
    {
        $profile = self::profile($settings);
        $context = self::context($profile['shop_id'], $profile['shop_password'], 'yes' === $profile['testmode']);
        if (self::profiles_satisfy(self::profiles(), $profile, $context, $seed_legacy_alias)) {
            return $context;
        }

        $mutex = new DatabaseMutex();
        if (! $mutex->acquire('sci-credential-store')) {
            throw new ConfigurationException('The SCI credential store is busy.');
        }
        try {
            return $this->retain_locked($profile, $context, $seed_legacy_alias, $mutex);
        } finally {
            $mutex->release();
        }
    }

    /**
     * @param array{shop_id:string,shop_password:string,testmode:string} $profile
     */
    private function retain_locked(array $profile, string $context, bool $seed_legacy_alias, DatabaseMutex $mutex): string
    {
        $mutex->assert_owned();
        // The pre-lock fast-path populated WordPress's option caches. Evict
        // the value, negative and autoload caches before the mandatory
        // under-lock re-check so a concurrent writer is never overwritten
        // from a stale read.
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
        $profiles = self::profiles();
        if (self::profiles_satisfy($profiles, $profile, $context, $seed_legacy_alias)) {
            return $context;
        }

        $record = array(
            'shop_id' => $profile['shop_id'],
            'shop_password' => $profile['shop_password'],
            'testmode' => $profile['testmode'],
            'retained_at' => gmdate('c'),
        );
        $changed = ! isset($profiles[$context]) || ! self::record_matches($profiles[$context], $record);
        if ($changed) {
            $profiles[$context] = $record;
        }

        // Snapshots created before credential versioning used only shop+mode
        // as their context. Seed that alias once while their original secret
        // is still configured; a later rotation must never overwrite it.
        $legacy_context = self::legacy_context($profile['shop_id'], 'yes' === $profile['testmode']);
        if ($seed_legacy_alias && ! isset($profiles[$legacy_context])) {
            $profiles[$legacy_context] = $record;
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $profiles, false);
        }
        $mutex->assert_owned();
        $stored = $this->settings_for_context($context);
        if (null === $stored || ! hash_equals($profile['shop_password'], $stored['shop_password'])) {
            throw new ConfigurationException('The SCI credential profile could not be retained safely.');
        }
        return $context;
    }

    /** @return array{shop_id:string,shop_password:string,testmode:string}|null */
    public function settings_for_context(string $context): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $context)) {
            return null;
        }
        $profiles = self::profiles();
        if (! isset($profiles[$context]) || ! is_array($profiles[$context])) {
            return null;
        }
        try {
            return self::profile($profiles[$context]);
        } catch (ConfigurationException $exception) {
            return null;
        }
    }

    /** @return list<string> */
    public function contexts_for_merchant(string $shop_id, bool $test_mode): array
    {
        $profiles = self::profiles();
        $contexts = array();
        foreach ($profiles as $context => $record) {
            if (! is_string($context) || ! is_array($record)) {
                continue;
            }
            try {
                $profile = self::profile($record);
            } catch (ConfigurationException $exception) {
                continue;
            }
            if ($profile['shop_id'] === $shop_id && ('yes' === $profile['testmode']) === $test_mode) {
                $contexts[] = $context;
            }
        }
        return array_values(array_unique($contexts));
    }

    public static function context(string $shop_id, string $shop_password, bool $test_mode): string
    {
        return hash_hmac('sha256', $shop_id . "\0" . ($test_mode ? 'test' : 'live') . "\0" . $shop_password, wp_salt('auth'));
    }

    /** Context format used by snapshots before secret-specific versioning. */
    public static function legacy_context(string $shop_id, bool $test_mode): string
    {
        return hash('sha256', $shop_id . "\0" . ($test_mode ? 'test' : 'live'));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{shop_id:string,shop_password:string,testmode:string}
     */
    private static function profile(array $settings): array
    {
        $shop_id = isset($settings['shop_id']) && is_string($settings['shop_id']) ? trim($settings['shop_id']) : '';
        $password = isset($settings['shop_password']) && is_string($settings['shop_password']) ? $settings['shop_password'] : '';
        $testmode = 'yes' === ($settings['testmode'] ?? 'no') ? 'yes' : 'no';
        if ('' === $shop_id || '' === $password || strlen($shop_id) > 128 || strlen($password) > 512) {
            throw new ConfigurationException('Valid SCI merchant credentials are required.');
        }
        return array('shop_id' => $shop_id, 'shop_password' => $password, 'testmode' => $testmode);
    }

    /** @param mixed $existing @param array<string, string> $expected */
    private static function record_matches($existing, array $expected): bool
    {
        return is_array($existing)
            && ($existing['shop_id'] ?? null) === $expected['shop_id']
            && ($existing['shop_password'] ?? null) === $expected['shop_password']
            && ($existing['testmode'] ?? null) === $expected['testmode'];
    }

    /** @return array<string, mixed> */
    private static function profiles(): array
    {
        $profiles = get_option(self::OPTION, array());
        return is_array($profiles) ? $profiles : array();
    }

    /**
     * @param array<string, mixed>                                   $profiles
     * @param array{shop_id:string,shop_password:string,testmode:string} $profile
     */
    private static function profiles_satisfy(array $profiles, array $profile, string $context, bool $seed_legacy_alias): bool
    {
        $record = array(
            'shop_id' => $profile['shop_id'],
            'shop_password' => $profile['shop_password'],
            'testmode' => $profile['testmode'],
        );
        if (! isset($profiles[$context]) || ! self::record_matches($profiles[$context], $record)) {
            return false;
        }
        if (! $seed_legacy_alias) {
            return true;
        }
        $legacy_context = self::legacy_context($profile['shop_id'], 'yes' === $profile['testmode']);
        return isset($profiles[$legacy_context]);
    }
}
