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

## PayKassa Merchant URL configuration

The plugin generates one URL for each of PayKassa's four Merchant settings. Copy them from **WooCommerce → PayKassa health**; do not interchange the fields.

| PayKassa Merchant field | Plugin endpoint | Contract |
|---|---|---|
| URL of Invoice Payment Notifications | `/?wc-api=wc_gateway_paykassa` | Required server-to-server `sci_confirm_order` verification |
| URL of successful payment | `/?wc-api=wc_gateway_paykassa_return` | Browser UX return only; never payment evidence |
| URL malfunction when paying | `/?wc-api=wc_gateway_paykassa_cancel` | Browser UX return only; never cancels or settles an order |
| URL of Cryptocurrency Transaction Processor | `/?wc-api=wc_gateway_paykassa_transaction` | Optional, Live-only server-to-server `sci_confirm_transaction_notification` channel |

URLs use `home_url('/')` by default. **External PayKassa base URL (optional)** can point only these four Merchant URLs to a validated public HTTPS base such as an ngrok tunnel. It does not alter WordPress, WooCommerce, order, REST or hosted-payment URLs.

Both server callbacks independently verify `private_hash` with PayKassa. Raw callback fields and browser redirects never call `payment_complete()`. The invoice channel supplies invoice-hash-bound `PaymentEvidence`; the transaction channel supplies distinct hashless `TransactionNotificationEvidence`. Credited transaction evidence can settle only when its verified merchant, Live environment, order, amount, currency and system identify exactly one active immutable snapshot. Both then use the same settlement mutex, transaction event identity and `WebhookProcessor`.

PayKassa does not expose a reviewed machine-readable network minimum in the SCI/API contract used here. The optional minimum setting is therefore an explicitly merchant-owned guard keyed by exact direction, for example `Ethereum_ERC20:USDT=5`. It is empty/disabled by default and is not presented as an official PayKassa minimum.

## Provider contract

The SCI/API adapter implements the documented PayKassa endpoints and payload shapes from the official `paykassa-dev/paykassa-modules` wrapper at commit `1b3b4d4a0dcda0769b9bca7fef5bb131c923fbb8` (2025-03-29). It deliberately uses WordPress HTTP with TLS verification instead of shipping or modifying that wrapper's cURL implementation.

See [architecture](docs/architecture.md), [payment flow](docs/payment-flow.md), [webhook security](docs/webhook-security.md), [testing](docs/testing.md), and [releasing](docs/releasing.md).
