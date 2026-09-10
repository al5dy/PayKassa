# PayKassa for WooCommerce

PHP 8.1+ WooCommerce payment gateway with a conservative, provider-verified crypto payment flow.

## Development

```bash
composer install
npm install
npm run build
composer test
composer lint
composer stan
npm test
npm run plugin-zip
```

The production plugin has its own minimal PSR-4 autoloader, so merchants never run Composer. Composer only supplies development tooling.

## Provider contract

The SCI/API adapter implements the documented PayKassa endpoints and payload shapes from the official `paykassa-dev/paykassa-modules` wrapper at commit `1b3b4d4a0dcda0769b9bca7fef5bb131c923fbb8` (2025-03-29). It deliberately uses WordPress HTTP with TLS verification instead of shipping or modifying that wrapper's cURL implementation.

See [architecture](docs/architecture.md), [payment flow](docs/payment-flow.md), [webhook security](docs/webhook-security.md), [testing](docs/testing.md), and [releasing](docs/releasing.md).
