#!/usr/bin/env bash
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
plugin_zip=${PAYKASSA_TEST_PLUGIN_ZIP:-"$base_dir/dist/paykassa-2.0.0.zip"}
port=${PAYKASSA_E2E_PORT:-8892}
base_url="http://127.0.0.1:${port}"
site_dir=$(mktemp -d /tmp/paykassa-browser.XXXXXXXX)
task_id=${site_dir##*.}
database="paykassa_browser_${task_id,,}"
database_created=false
server_pid=''
session="paykassa-e2e-${task_id,,}"
wp_cli=(wp --path="$site_dir" --no-color)
playwright_cli=(npx --yes --package @playwright/cli playwright-cli --session "$session")

cleanup() {
	local result=$?
	trap - EXIT
	"${playwright_cli[@]}" close >/dev/null 2>&1 || true
	if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
		kill "$server_pid" || true
		wait "$server_pid" 2>/dev/null || true
	fi
	if [[ "$database_created" == true ]] && [[ "$database" =~ ^paykassa_browser_[a-z0-9]+$ ]]; then
		"${wp_cli[@]}" db drop --yes >/dev/null || result=1
	fi
	if [[ "$result" -eq 0 ]]; then
		gio trash "$site_dir" 2>/dev/null || true
	else
		printf 'Temporary browser site retained for diagnosis: %s\n' "$site_dir" >&2
	fi
	exit "$result"
}
trap cleanup EXIT

if [[ ! -f "$plugin_zip" ]]; then
	printf 'Build the release ZIP before running browser tests.\n' >&2
	exit 1
fi

"${wp_cli[@]}" core download --version="${PAYKASSA_TEST_WP_VERSION:-7.1}" --locale=en_US
printf '%s\n' "${PAYKASSA_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
	--dbname="$database" \
	--dbuser="${PAYKASSA_TEST_DB_USER:-root}" \
	--dbhost="${PAYKASSA_TEST_DB_HOST:-localhost}" \
	--dbprefix=pk_browser_ --skip-check --prompt=dbpass
"${wp_cli[@]}" db create
database_created=true
"${wp_cli[@]}" config set PAYKASSA_TEST_DATABASE true --raw
"${wp_cli[@]}" config set PAYKASSA_BROWSER_TEST true --raw
"${wp_cli[@]}" config set DISABLE_WP_CRON true --raw
"${wp_cli[@]}" config set WP_DEBUG true --raw
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw
mkdir -p "$site_dir/wp-content/mu-plugins"
cp "$base_dir/tests/fixtures/disposable-site.php" "$site_dir/wp-content/mu-plugins/paykassa-test-isolation.php"
cp "$base_dir/tests/fixtures/browser-provider.php" "$site_dir/wp-content/mu-plugins/paykassa-browser-provider.php"
"${wp_cli[@]}" core install --url="$base_url" --title='PayKassa browser test' \
	--admin_user=paykassa_test --admin_password=local-test-password \
	--admin_email=paykassa@example.invalid --skip-email
"${wp_cli[@]}" plugin install woocommerce --version="${PAYKASSA_TEST_WC_VERSION:-11.1.0}"
if ! "${wp_cli[@]}" plugin activate woocommerce; then
	# Some local XAMPP builds can terminate the first activation while
	# WooCommerce probes optional image formats. Activation itself is
	# idempotent, so retry once on the same disposable installation.
	"${wp_cli[@]}" plugin activate woocommerce
fi
"${wp_cli[@]}" option delete wc_installing >/dev/null 2>&1 || true
"${wp_cli[@]}" plugin install "$plugin_zip" --activate

cart_id=$("${wp_cli[@]}" post create --post_type=page --post_title='PayKassa Cart' --post_name=paykassa-cart --post_content='[woocommerce_cart]' --post_status=publish --porcelain)
classic_checkout_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Classic Checkout' --post_name=classic-checkout --post_content='[woocommerce_checkout]' --post_status=publish --porcelain)
blocks_checkout_id=$("${wp_cli[@]}" post create --post_type=page --post_title='Blocks Checkout' --post_name=blocks-checkout --post_content='placeholder' --post_status=publish --porcelain)
account_id=$("${wp_cli[@]}" post create --post_type=page --post_title='PayKassa Account' --post_name=paykassa-account --post_content='[woocommerce_my_account]' --post_status=publish --porcelain)
PAYKASSA_BLOCKS_PAGE_ID="$blocks_checkout_id" "${wp_cli[@]}" eval '$method = new ReflectionMethod("WC_Install", "get_checkout_block_content"); $method->setAccessible(true); wp_update_post(array("ID" => (int) getenv("PAYKASSA_BLOCKS_PAGE_ID"), "post_content" => $method->invoke(null)));' --skip-themes
"${wp_cli[@]}" option update woocommerce_cart_page_id "$cart_id"
"${wp_cli[@]}" option update woocommerce_checkout_page_id "$classic_checkout_id"
"${wp_cli[@]}" option update paykassa_browser_blocks_checkout_id "$blocks_checkout_id"
"${wp_cli[@]}" option update woocommerce_myaccount_page_id "$account_id"
"${wp_cli[@]}" option update woocommerce_currency USD
"${wp_cli[@]}" option update woocommerce_default_country US:CA
"${wp_cli[@]}" option update woocommerce_enable_guest_checkout yes
"${wp_cli[@]}" option update woocommerce_enable_signup_and_login_from_checkout no
"${wp_cli[@]}" option update woocommerce_paykassa_settings '{"enabled":"yes","shop_id":"browser-shop","shop_password":"browser-secret","testmode":"no","title":"Cryptocurrency (PayKassa)","description":"Browser smoke cryptocurrency payment.","accepted_order_currencies":["USD"],"enabled_payment_directions":["ethereum_erc20:USDT"],"external_base_url":"https://ocelot-dribble-creature.ngrok-free.dev/","minimum_payment_directions":"","debug":"yes"}' --format=json
product_id=$("${wp_cli[@]}" eval '$product = new WC_Product_Simple(); $product->set_name("Browser PayKassa Product"); $product->set_regular_price("2.00"); $product->set_price("2.00"); $product->set_virtual(true); $product->set_status("publish"); echo $product->save();')
"${wp_cli[@]}" rewrite structure '/%postname%/' --hard

mkdir -p "$base_dir/output/playwright"
"${wp_cli[@]}" server --host=127.0.0.1 --port="$port" >"$base_dir/output/playwright/wp-server.log" 2>&1 &
server_pid=$!
for _attempt in $(seq 1 30); do
	if curl -fsS "$base_url" >/dev/null; then
		break
	fi
	sleep 1
done
curl -fsS "$base_url" >/dev/null

pushd "$base_dir/output/playwright" >/dev/null
"${playwright_cli[@]}" open "$base_url/?paykassa_e2e_product=$product_id"
"${playwright_cli[@]}" run-code --filename "$base_dir/tests/E2E/browser-smoke.js"
"${playwright_cli[@]}" console error
popd >/dev/null

PAYKASSA_FAILED_ORDER_ID=$("${wp_cli[@]}" post list --post_type=shop_order,shop_order_placehold --orderby=ID --order=DESC --posts_per_page=1 --field=ID 2>/dev/null || true)
if [[ -n "$PAYKASSA_FAILED_ORDER_ID" ]]; then
	PAYKASSA_FAILED_ORDER_ID="$PAYKASSA_FAILED_ORDER_ID" "${wp_cli[@]}" eval '$order = wc_get_order((int) getenv("PAYKASSA_FAILED_ORDER_ID")); if (! $order instanceof WC_Order || $order->is_paid() || "pending" !== $order->get_status()) { throw new RuntimeException("Failure return mutated the unpaid order."); }'
fi

printf 'PayKassa browser E2E passed: Classic Checkout, Blocks Checkout, exact IPN ACKs, success return, and failure retry.\n'
