=== PayKassa for WooCommerce ===
Contributors: al5dy
Tags: woocommerce, cryptocurrency, bitcoin, usdt, payment gateway
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin, USDT, USDC, Ethereum and 12+ cryptocurrencies in WooCommerce via PayKassa with Blocks, HPOS and provider-verified IPN.

== Description ==

**Turn WooCommerce into a modern cryptocurrency checkout with PayKassa.**

PayKassa for WooCommerce lets customers pay with Bitcoin, Ethereum, USDT, USDC, TON, Litecoin, Dogecoin and other supported cryptocurrencies while your store can continue pricing orders in supported currencies such as USD, EUR, GBP or RUB.

The plugin combines a customer-friendly hosted crypto checkout with a conservative payment-verification model: an order is marked paid only after PayKassa has verified the server-to-server notification. Browser redirects are never trusted as proof of payment.

= Why use PayKassa for WooCommerce? =

* **Accept major cryptocurrencies** including BTC, ETH, USDT, USDC, LTC, DOGE, TON, TRX, XRP, BCH, DASH, XLM, BNB, ADA, EOS and SHIB.
* **24 exact cryptocurrency/network payment directions** across 14 PayKassa payment systems and network variants.
* **Stablecoin support** on popular networks including USDT TRC20, USDT/USDC ERC20, USDT/USDC BEP20 and USDT on TON.
* **Fiat-to-crypto checkout** for supported WooCommerce order currencies including USD, EUR, GBP and RUB using a fresh PayKassa Currency API quote when the invoice is created.
* **WooCommerce Checkout Blocks** support for modern block-based checkout.
* **HPOS compatibility** for High-Performance Order Storage / custom order tables.
* **Hosted PayKassa checkout** so the customer completes the cryptocurrency payment through PayKassa.
* **Provider-verified payment confirmation** before WooCommerce calls `payment_complete()`.
* **Immutable payment snapshots** preserve the exact order amount, payment amount, currency, network, conversion and merchant context used for the invoice.
* **Exact decimal-string money checks** instead of unsafe floating-point equality.
* **Webhook idempotency** protects orders from duplicate callback delivery.
* **Credential rotation support** keeps unfinished payment contexts verifiable after SCI credentials change.
* **Test mode** for PayKassa test payments.
* **Site Health diagnostics**, redacted order payment details and optional redacted debug logging.
* **Custom success, pending and failure return pages** with native WooCommerce behavior as the safe default.
* **Separate callback and browser-return base URLs** for reverse proxies, tunnels and multi-origin setups.
* **Optional minimum payment rules** for exact PayKassa cryptocurrency/network directions.
* **Optional bounded payment recovery** for merchants who configure PayKassa API credentials.

= Sell in fiat, get paid in crypto =

Your WooCommerce catalog does not have to be priced in cryptocurrency.

For supported order currencies such as **USD, EUR, GBP and RUB**, the plugin can request a PayKassa Currency API quote when the payment invoice is created and convert the saved WooCommerce order amount into the customer's selected cryptocurrency payment amount.

The original WooCommerce order currency and total remain preserved. The converted payment amount, selected cryptocurrency, network and conversion data are stored in the payment snapshot used for later verification.

= Bitcoin, USDT, USDC, Ethereum and more =

The built-in PayKassa direction registry currently includes:

* Bitcoin (BTC)
* Ethereum (ETH)
* Litecoin (LTC)
* Dogecoin (DOGE)
* Dash (DASH)
* Bitcoin Cash (BCH)
* XRP
* TRON (TRX)
* Stellar (XLM)
* BNB Chain (BNB)
* USDT on TRON / TRC20
* USDT, USDC, ADA, EOS, BTC, ETH, DOGE and SHIB on BNB Smart Chain / BEP20
* USDT, USDC and SHIB on Ethereum / ERC20
* TON and USDT on TON

The merchant chooses exactly which cryptocurrency/network combinations are available at checkout.

= Built for modern WooCommerce =

PayKassa for WooCommerce supports both the classic checkout flow and WooCommerce Checkout Blocks. It declares compatibility with HPOS / custom order tables and uses WooCommerce CRUD APIs for order state and payment metadata.

The gateway correlates PayKassa payments using the immutable internal WooCommerce order ID, so plugins that only change the displayed order number do not break provider matching.

= Payment verification designed for real money =

Crypto payment confirmation is treated as a security boundary.

Incoming callback fields are not trusted by themselves. The plugin verifies the callback token with PayKassa server-to-server and then checks the verified payment evidence against the immutable invoice snapshot before settling the WooCommerce order.

The payment pipeline includes:

* server-to-server PayKassa SCI verification;
* immutable order/payment snapshots;
* exact amount, currency and network checks;
* Live/Test environment matching;
* durable webhook transaction deduplication;
* database-backed settlement locking;
* retained SCI credential contexts for unfinished invoices;
* fail-closed handling of malformed provider responses.

The successful-payment and malfunction browser URLs are only for customer UX. They cannot independently mark an order paid.

= Merchant controls =

From WooCommerce payment settings you can configure:

* PayKassa Merchant / Shop ID and SCI secret;
* PayKassa Test Mode;
* accepted WooCommerce order currencies;
* enabled cryptocurrency/network payment directions;
* optional PayKassa API credentials for diagnostics/recovery;
* optional minimum payment rules per exact payment direction;
* external server callback base URL;
* external browser-return base URL;
* success, pending and failed/cancelled customer return pages;
* redacted debug logging;
* uninstall data cleanup.

The plugin also generates the PayKassa Merchant URLs that must be copied into the matching fields in the PayKassa merchant interface.

= External service =

This plugin connects to the third-party PayKassa payment service. A PayKassa merchant account is required.

When a customer starts payment, the plugin sends the merchant ID, WooCommerce internal order ID, amount, selected payment currency/network and a short order comment to PayKassa. When PayKassa sends a payment notification, the plugin verifies the provider token server-to-server using the configured SCI credentials. The PayKassa Currency API is used when a supported order currency must be converted to the selected payment currency. Optional API credentials are used for merchant-initiated connection/history checks and optional payment recovery.

PayKassa service, terms and privacy information are available at [PayKassa](https://paykassa.app/).

This plugin is an independent integration and is not an official PayKassa product.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate PayKassa for WooCommerce.
3. Go to **WooCommerce > Settings > Payments > PayKassa**.
4. Enter your PayKassa Merchant / Shop ID and Merchant secret.
5. Choose the WooCommerce order currencies you want PayKassa to accept.
6. Choose the exact cryptocurrency/network payment directions you want to offer.
7. Copy the four generated Merchant URLs into the matching fields in your PayKassa merchant settings.
8. Use Test Mode first, then disable it when your Live PayKassa configuration is ready.
9. Place a controlled payment and verify the WooCommerce order reaches the expected paid status.

== Frequently Asked Questions ==

= Can I accept Bitcoin payments in WooCommerce? =

Yes. Bitcoin (BTC) is one of the built-in PayKassa payment directions and can be enabled from the gateway settings.

= Can I accept USDT or USDC in WooCommerce? =

Yes. The current registry includes USDT and USDC on multiple supported network variants, including TRC20, ERC20, BEP20 and TON where applicable.

= Can my WooCommerce store stay in USD or EUR while customers pay in crypto? =

Yes. Supported fiat order currencies include USD, EUR, GBP and RUB. When the customer creates a crypto payment, the plugin requests a PayKassa Currency API quote and stores both the original WooCommerce order amount and the resulting crypto payment amount.

= Does it support WooCommerce Checkout Blocks? =

Yes. The gateway integrates with WooCommerce Checkout Blocks as well as classic checkout.

= Does it support WooCommerce HPOS? =

Yes. The plugin declares compatibility with High-Performance Order Storage / custom order tables.

= How does the plugin know a crypto payment is really paid? =

It does not trust the customer's return URL. PayKassa's server notification is verified server-to-server with PayKassa SCI, and the verified evidence is checked against the stored payment snapshot before WooCommerce marks the order paid.

= Can I choose which cryptocurrencies and networks customers see? =

Yes. Payment methods are configured as exact cryptocurrency/network directions. You can enable only the combinations you actually want to accept.

= Does the plugin protect against duplicate IPN/webhook delivery? =

Yes. Verified provider transaction identities are stored durably and processed idempotently so a repeated callback does not create a second payment transition.

= Can I use a reverse proxy, tunnel or separate public callback hostname? =

Yes. Server callback and customer browser-return base URLs are configurable independently. This is useful when PayKassa needs a public callback URL while customer checkout runs on another origin.

= Can I customize the page customers see after payment? =

Yes. Separate published WordPress pages can be selected for successful, pending and failed/cancelled returns. Native WooCommerce destinations remain the default and are the safest choice unless you intentionally need custom pages.

= Does the 2.0 update keep my existing PayKassa credentials? =

Yes. The existing `woocommerce_paykassa_settings` option and its legacy Shop ID, merchant secret, test-mode, title and description values are retained. Leaving a secret field empty when saving keeps its currently stored value unless you explicitly reset it.

= Can I use a sequential order-number plugin? =

Yes. PayKassa correlation uses the immutable WooCommerce internal order ID, not the displayed order number.

= Does this plugin issue automatic cryptocurrency refunds? =

No. A blockchain sender address is not proof of a safe refund destination. WooCommerce manual refund accounting remains available.

= Does it support saved crypto payment methods or automatic subscriptions? =

No. The reviewed PayKassa contract does not establish safe semantics for those payment flows, so the plugin does not pretend to support them.

= Is this an official PayKassa plugin? =

No. PayKassa for WooCommerce is an independent integration for the PayKassa service.

== Screenshots ==

1. PayKassa gateway settings with accepted order currencies and exact crypto/network directions.
2. WooCommerce Checkout Block with cryptocurrency/network selection.
3. PayKassa-hosted cryptocurrency payment flow.
4. Redacted PayKassa payment details in the WooCommerce order screen.
5. Site Health diagnostics for PayKassa gateway configuration.

== Changelog ==

= 2.0.0 =
* Complete modern rewrite for PHP 8.1+, WordPress 6.6+ and WooCommerce 8.5+.
* Added HPOS and Checkout Block integrations.
* Added immutable payment snapshots, provider-verified IPN processing, exact amount comparison and durable webhook idempotency storage.
* Preserved 1.x settings and the `?wc-api=wc_gateway_paykassa` endpoint.
* Replaced unsafe legacy TLS and order access patterns.
* Added optional API health/reconciliation scheduling, diagnostics, Site Health checks and deterministic build tooling.

= 1.0.2 - 2020-07-20 =
* Update main SCI class.

= 1.0.1 - 2018-03-29 =
* Bugfix WC-API check response.
* Improved order processing.
* Minor changes.

= 1.0.0 - 2018-03-27 =
* First release.

== Upgrade Notice ==

= 2.0.0 =
This security-focused release preserves existing settings and the legacy callback URL. Review accepted order currencies and enabled cryptocurrency/network directions after updating, then verify at least one controlled payment before enabling the gateway for production traffic.
