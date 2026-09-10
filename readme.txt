=== PayKassa for WooCommerce ===
Contributors: al5dy
Tags: cryptocurrency, crypto, bitcoin, woocommerce, payment gateway, paykassa
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Modern crypto payments for WooCommerce through PayKassa — Blocks, HPOS, secure IPN processing and multi-network cryptocurrency checkout.

== Description ==

PayKassa for WooCommerce is an independent WooCommerce gateway that creates a PayKassa-hosted cryptocurrency payment request and marks an order paid only after PayKassa has verified its IPN server-to-server.

* Compatible with WooCommerce Checkout Blocks and High-Performance Order Storage (HPOS).
* Uses the order's saved currency and total, never the current global store currency during payment confirmation.
* Preserves the 1.x gateway ID, settings option and legacy callback URL.
* Uses an immutable invoice snapshot, exact decimal-string comparison and durable webhook transaction deduplication.
* Provides a redacted order payment panel, Site Health checks, optional API health/reconciliation scheduling and test mode.
* Does not offer automated crypto refunds, saved payment methods or automatic subscriptions because the documented PayKassa API does not establish safe semantics for them.

= Currency safety =

The documented current PayKassa SCI crypto flow accepts a selected cryptocurrency amount and network. This plugin does not invent exchange rates. It is available only when the saved WooCommerce order currency is an enabled, documented PayKassa crypto direction. Stores using fiat or an unconfirmed currency pair will not see an unsafe payment method.

= External service =

This plugin connects to the third-party PayKassa payment service. A PayKassa merchant account is required. When a customer starts payment, the plugin sends the merchant ID, order's internal ID, amount, currency, selected network and a short order comment to PayKassa. When PayKassa calls the configured notification URL, the plugin sends the provider token and merchant SCI credentials to PayKassa for server-to-server verification. Optional API credentials are only used for a merchant-initiated connection/history check.

PayKassa's service, terms and privacy information are available at [PayKassa](https://paykassa.app/). This plugin is not an official PayKassa product.

== Installation ==

1. Install and activate WooCommerce and PayKassa for WooCommerce.
2. Go to WooCommerce > Settings > Payments > PayKassa.
3. Enter the PayKassa Shop ID and Merchant secret, then enable the gateway.
4. Configure the notification URL in PayKassa as `https://YOUR-STORE/?wc-api=wc_gateway_paykassa`.
5. Enable only networks your store can safely accept in its order currency.

== Frequently Asked Questions ==

= Does the 2.0 update keep my credentials? =

Yes. The existing `woocommerce_paykassa_settings` option and its `shop_id`, `shop_password`, `testmode`, title and description are retained. Empty password fields on save retain their existing stored value.

= Can I use a sequential order-number plugin? =

Yes. PayKassa correlation uses the immutable WooCommerce internal order ID, not the displayed order number.

= Does this plugin issue crypto refunds? =

No. A blockchain sender address is not proof of a safe refund destination. WooCommerce manual refund accounting remains available.

== Screenshots ==

1. PayKassa gateway settings and safe network configuration.
2. Checkout Block with cryptocurrency-network selection.
3. PayKassa-hosted payment redirect.
4. PayKassa payment details in the WooCommerce order screen.
5. Site Health result for gateway configuration.

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
This security-focused release preserves existing settings and the legacy callback URL. Verify each enabled payment direction after updating because 2.0 refuses unconfirmed currency conversion flows.
