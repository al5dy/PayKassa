# PayKassa for WooCommerce

**PayKassa for WooCommerce** is a modern cryptocurrency payment gateway for WooCommerce that connects a store to PayKassa hosted crypto payments while keeping settlement decisions inside a strict, provider-verified payment flow.

It is designed for stores that want to accept **Bitcoin, Ethereum, USDT, USDC and other cryptocurrencies** through PayKassa without giving up modern WooCommerce compatibility, deterministic money handling, webhook idempotency, or clear operational diagnostics.

> Independent integration. This plugin is not an official PayKassa product.

## Highlights

- **WooCommerce Checkout Blocks** and classic checkout support.
- **HPOS / High-Performance Order Storage** compatibility.
- **Fiat-to-crypto checkout** for supported WooCommerce order currencies including USD, EUR, GBP and RUB.
- **16 supported crypto assets** exposed through **24 exact cryptocurrency/network directions** across 14 PayKassa payment systems.
- Hosted PayKassa payment creation with a fresh PayKassa Currency API quote when conversion is required.
- Provider-verified server-to-server settlement; browser redirects are never treated as payment proof.
- Immutable payment snapshots that preserve the exact order amount, order currency, payment amount, crypto currency, network/system, conversion pair, rate and credential context used for the invoice.
- Exact decimal-string arithmetic for amount comparison and conversion-sensitive checks.
- Durable webhook event identity and idempotent processing to prevent duplicate settlement.
- Separate Invoice Payment Notification and Live transaction-notification verification channels.
- Retained SCI credential profiles so unfinished invoices remain verifiable after merchant credential rotation.
- Split server callback and browser-return base URLs for reverse proxies, tunnels and multi-origin checkout setups.
- Configurable success, pending and failure return pages without allowing browser returns to settle orders.
- Optional merchant-defined minimum payment rules per exact PayKassa network/currency direction.
- Site Health checks, a redacted order payment panel, diagnostic logging and optional bounded payment recovery.
- Russian translation files included.

## Requirements

- WordPress **6.6+**
- WooCommerce **8.5+**
- PHP **8.1+**

The current CI matrix runs PHP 8.1, 8.2, 8.3 and 8.4. Integration coverage includes WordPress 6.6 / WooCommerce 8.5.2 and WordPress 7.1 / WooCommerce 11.1.0.

## Payment flow

The core payment flow intentionally separates customer UX from payment evidence:

```text
WooCommerce order
    ↓
Customer selects an exact crypto/network direction
    ↓
PayKassa Currency API quote (when order currency != payment currency)
    ↓
SCI credential context retained
    ↓
PayKassa hosted payment request created
    ↓
Immutable payment snapshot persisted
    ↓
Customer redirected to PayKassa
    ↓
PayKassa server callback received
    ↓
Callback token verified server-to-server with PayKassa SCI
    ↓
Verified PaymentEvidence / TransactionNotificationEvidence
    ↓
Snapshot + amount + currency + network + environment checks
    ↓
Database-backed idempotent settlement
    ↓
WooCommerce payment_complete()
```

The successful-payment and malfunction browser-return endpoints are **UX only**. They can redirect the customer, but they cannot prove payment, cancel a paid order, or call `payment_complete()`.

## Supported WooCommerce order currencies

The Currency API adapter currently accepts these order currencies:

`USD`, `EUR`, `GBP`, `RUB`, `BTC`, `ETH`, `LTC`, `DOGE`, `DASH`, `BCH`, `XRP`, `TRX`, `XLM`, `BNB`, `USDT`, `USDC`, `ADA`, `EOS`, `TON`, `SHIB`.

A store can therefore keep prices in a supported fiat currency such as USD or EUR while the customer chooses an enabled cryptocurrency/network direction. The plugin requests the conversion quote only when the payment invoice is created.

## Supported cryptocurrency and network directions

| PayKassa system / network | Payment currencies |
|---|---|
| Bitcoin | BTC |
| Ethereum | ETH |
| Litecoin | LTC |
| Dogecoin | DOGE |
| Dash | DASH |
| Bitcoin Cash | BCH |
| Ripple | XRP |
| TRON | TRX |
| Stellar | XLM |
| BNB Chain | BNB |
| TRON (TRC20) | USDT |
| BNB Smart Chain (BEP20) | USDT, USDC, ADA, EOS, BTC, ETH, DOGE, SHIB |
| Ethereum (ERC20) | USDT, USDC, SHIB |
| TON | TON, USDT |

The registry is based on the payment systems published by PayKassa's official PHP wrapper (`paykassa-dev/paykassa-modules`, commit `1b3b4d4a0dcda0769b9bca7fef5bb131c923fbb8`, 2025-03-29).

## Settlement and security model

### Provider verification first

Incoming callback fields are routing hints, not trusted payment evidence. The invoice callback verifies `private_hash` with PayKassa using `sci_confirm_order`. The Live transaction-notification channel uses the separate `sci_confirm_transaction_notification` contract.

### Immutable payment snapshot

Each created payment stores the financial state required to validate later settlement, including:

- WooCommerce order ID;
- order amount and order currency;
- PayKassa payment amount and payment currency;
- provider payment system/network;
- conversion pair, rate, source and quote timestamp;
- environment (`test` or `live`);
- merchant credential context;
- PayKassa payment-link/invoice identity.

Settlement is checked against this snapshot instead of mutable global WooCommerce settings.

### Exact decimal arithmetic

Money-sensitive values are handled as normalized decimal strings. The gateway does not rely on binary floating-point equality for payment amount validation.

### Idempotent webhook processing

Provider transaction identity is stored durably. Repeated delivery of the same verified callback does not create a second WooCommerce payment transition.

### Credential rotation

SCI credential profiles are retained for unfinished payment contexts. Rotating the current merchant secret does not automatically make an older open invoice unverifiable.

### Defensive transport behavior

PayKassa requests use the WordPress HTTP API with TLS verification, bounded redirects and bounded response sizes. Malformed or unexpected provider responses fail closed.

## WooCommerce integration

The gateway supports:

- classic checkout;
- WooCommerce Checkout Blocks;
- HPOS/custom order tables;
- native WooCommerce order payment state transitions;
- internal WooCommerce order IDs for PayKassa correlation, so sequential/display-order-number plugins do not break provider matching;
- merchant-selectable accepted order currencies;
- merchant-selectable exact cryptocurrency/network directions.

The production plugin uses its own minimal PSR-4 autoloader. Merchants do **not** need Composer or Node.js on the production store.

## PayKassa Merchant URL configuration

The plugin generates the four PayKassa Merchant URLs. Copy each generated URL into the matching PayKassa Merchant field; do not interchange them.

| PayKassa Merchant field | Plugin endpoint | Contract |
|---|---|---|
| Invoice Payment Notifications | `/?wc-api=wc_gateway_paykassa` | Required server-to-server `sci_confirm_order` verification |
| Successful payment | `/?wc-api=wc_gateway_paykassa_return` | Browser UX return only |
| Malfunction when paying | `/?wc-api=wc_gateway_paykassa_cancel` | Browser UX return only |
| Cryptocurrency Transaction Processor | `/?wc-api=wc_gateway_paykassa_transaction` | Optional Live-only server-to-server transaction notification |

### Split callback and browser origins

Two base URLs can be configured independently:

- **External PayKassa callback base URL** — affects only server-to-server callbacks.
- **External PayKassa browser return base URL** — affects only customer browser returns.

Both default independently to `home_url('/')`. In Live mode, external overrides require HTTPS.

The browser-return origin should match the public origin where the customer actually performed checkout so WooCommerce login/session cookies remain available.

## Customer return destinations

The merchant can choose separate published WordPress pages for:

- successful payment;
- payment still pending confirmation;
- failed/cancelled payment.

Native WooCommerce destinations remain the default. Custom return pages change UX only; they never change payment evidence or settlement rules.

## Minimum payment policy

PayKassa does not expose a reviewed machine-readable minimum per network in the SCI/Currency API contract used by this plugin. The gateway therefore provides an **optional merchant-owned** minimum rule per exact provider direction, for example:

```text
Ethereum_ERC20:USDT=5
TRON_TRC20:USDT=2
```

Empty means disabled. These values are not presented as official PayKassa limits.

## Diagnostics and recovery

The plugin includes:

- Site Health checks;
- a redacted WooCommerce order payment panel;
- optional redacted debug logging;
- API connectivity/history tooling when API credentials are configured;
- optional bounded background reconciliation.

Payment recovery is intentionally conservative. History records alone are not treated as payment proof. Unverifiable or ambiguous cases are routed to review rather than silently settling an order.

## Intentionally unsupported payment features

The plugin does not claim safe semantics for features that the reviewed PayKassa contract does not establish:

- automated cryptocurrency refunds;
- saved crypto payment methods;
- automatic WooCommerce subscriptions/recurring charges.

WooCommerce manual refund accounting remains available.

## Development

```bash
composer install
npm install
npm run build
composer test
composer test:integration
composer lint
composer stan
npm test
npm run test:e2e
npm run plugin-zip
```

Useful Composer scripts:

```bash
composer syntax
composer test
composer test:integration
composer lint
composer stan
```

## Test coverage

The repository contains:

- PHPUnit unit tests for decimal math, snapshots, gateway availability, provider parsing, redirects, minimum-payment policy, webhook rate limiting and trust boundaries;
- WP-CLI integration tests for schema upgrades, gateway behavior, merchant endpoints, reconciliation and uninstall behavior;
- browser E2E coverage for checkout/return flows and split-origin scenarios;
- GitHub Actions quality, integration and browser jobs.

## Packaging

Build the distributable WordPress plugin ZIP with:

```bash
npm run plugin-zip
```

Development dependencies are not required on a merchant's WordPress installation.

## External service

This plugin connects to the third-party PayKassa service.

When a customer starts payment, the plugin sends the merchant identifier, WooCommerce internal order ID, amount, selected payment currency/network and a short order comment to PayKassa. When PayKassa sends a payment notification, the plugin verifies the callback server-to-server using the configured SCI credentials. The PayKassa Currency API is used when a supported order currency must be converted to the selected payment currency. Optional API credentials are used only for merchant-initiated connectivity/history functionality and optional recovery.

PayKassa service information, terms and privacy information are available from PayKassa: https://paykassa.app/

## License

PayKassa for WooCommerce is licensed under the GNU General Public License v2.0 or later.

Copyright © 2026 Anton Lokotkov.