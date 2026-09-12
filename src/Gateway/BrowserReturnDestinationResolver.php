<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\Gateway;

/** Resolves administrator-selected UX pages without accepting callback routing input. */
final class BrowserReturnDestinationResolver
{
    public const SUCCESS_PAGE_SETTING = 'success_return_page_id';
    public const PENDING_PAGE_SETTING = 'pending_return_page_id';
    public const FAILURE_PAGE_SETTING = 'failure_return_page_id';

    public const SUCCESS_CONTEXT = 'browser_return_success';
    public const PENDING_CONTEXT = 'browser_return_pending';
    public const FAILURE_CONTEXT = 'browser_return_failure';
    public const DENIED_CONTEXT = 'browser_return_denied';

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly BrowserDestinationUrlMapper $mapper,
        private readonly array $settings = array()
    ) {
    }

    public static function from_current_settings(BrowserDestinationUrlMapper $mapper): self
    {
        $settings = get_option('woocommerce_paykassa_settings', array());
        return new self($mapper, is_array($settings) ? $settings : array());
    }

    public function resolve(\WC_Order $order, string $context, string $native_destination): string
    {
        $page_id = $this->page_id_for_context($context);
        $selected_destination = $page_id > 0
            ? self::published_page_permalink($page_id)
            : null;

        return $this->mapper->map(
            $selected_destination ?? $native_destination,
            $context,
            $order,
            $native_destination
        );
    }

    public function denied(string $native_destination): string
    {
        return $this->mapper->map(
            $native_destination,
            self::DENIED_CONTEXT,
            null,
            $native_destination
        );
    }

    /** @return array<int, string> */
    public static function published_page_options(): array
    {
        $options = array();
        $pages = get_pages(array(
            'post_status' => 'publish',
            'sort_column' => 'post_title',
            'sort_order' => 'ASC',
        ));
        foreach ($pages as $page) {
            if (! $page instanceof \WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status) {
                continue;
            }
            $title = get_the_title($page);
            $options[$page->ID] = is_string($title) && '' !== trim($title)
                ? $title
                /* translators: %d is the numeric WordPress page ID. */
                : sprintf(__('Page #%d', 'paykassa'), $page->ID);
        }
        return $options;
    }

    /**
     * Normalize a saved page selector and reject stale or non-page objects at save time.
     * Runtime performs the same lookup again in case the page is changed later.
     */
    public static function normalize_configured_page_id(mixed $value): string
    {
        if (null === $value || '' === $value || '0' === $value || 0 === $value) {
            return '0';
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new \InvalidArgumentException('PayKassa return page must be a published WordPress page.');
        }
        $raw = (string) $value;
        if (1 !== preg_match('/^[1-9][0-9]{0,18}$/', $raw)) {
            throw new \InvalidArgumentException('PayKassa return page must be a published WordPress page.');
        }
        $page_id = filter_var($raw, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if (false === $page_id || null === self::published_page_permalink((int) $page_id)) {
            throw new \InvalidArgumentException('PayKassa return page must be a published WordPress page.');
        }
        return (string) $page_id;
    }

    private function page_id_for_context(string $context): int
    {
        $setting = match ($context) {
            self::SUCCESS_CONTEXT => self::SUCCESS_PAGE_SETTING,
            self::PENDING_CONTEXT => self::PENDING_PAGE_SETTING,
            self::FAILURE_CONTEXT => self::FAILURE_PAGE_SETTING,
            default => '',
        };
        if ('' === $setting) {
            return 0;
        }
        $value = $this->settings[$setting] ?? '0';
        if ((! is_string($value) && ! is_int($value)) || 1 !== preg_match('/^[1-9][0-9]{0,18}$/', (string) $value)) {
            return 0;
        }
        $page_id = filter_var((string) $value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false === $page_id ? 0 : (int) $page_id;
    }

    private static function published_page_permalink(int $page_id): ?string
    {
        $page = get_post($page_id);
        if (
            ! $page instanceof \WP_Post
            || 'page' !== $page->post_type
            || 'publish' !== $page->post_status
            || ! is_post_publicly_viewable($page)
        ) {
            return null;
        }
        $permalink = get_permalink($page);
        return is_string($permalink) && '' !== $permalink ? $permalink : null;
    }
}
