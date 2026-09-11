#!/usr/bin/env bash
set -euo pipefail

# All writes are restricted to a new, disposable WordPress site and database.
# PAYKASSA_TEST_DB_* credentials must belong to the local/CI test database server.
base_dir=$(cd "$(dirname "$0")/.." && pwd)
plugin_zip=${PAYKASSA_TEST_PLUGIN_ZIP:-"$base_dir/dist/paykassa-2.0.0.zip"}
if [[ ! -f "$plugin_zip" ]]; then
    printf 'Build the release ZIP before running integration tests.\n' >&2
    exit 1
fi
site_dir=$(mktemp -d /tmp/paykassa-integration.XXXXXXXX)
task_id=${site_dir##*.}
database="paykassa_test_${task_id,,}"
database_created=false
wp_cli=(wp --path="$site_dir" --no-color)

cleanup() {
    local result=$?
    trap - EXIT
    if [[ "$database_created" == true ]]; then
        # Drop only the database successfully created by this invocation.
        if [[ "$database" =~ ^paykassa_test_[a-z0-9]+$ ]]; then
            "${wp_cli[@]}" db drop --yes || result=1
        fi
    fi
    # Keep the temporary site files for diagnosis. They contain test-only
    # credentials and can be removed after inspection.
    printf 'Temporary integration site: %s\n' "$site_dir"
    exit "$result"
}
trap cleanup EXIT

"${wp_cli[@]}" core download --version="${PAYKASSA_TEST_WP_VERSION:-7.1}" --locale=en_US
printf '%s\n' "${PAYKASSA_TEST_DB_PASSWORD:-}" | "${wp_cli[@]}" config create \
    --dbname="$database" \
    --dbuser="${PAYKASSA_TEST_DB_USER:-root}" \
    --dbhost="${PAYKASSA_TEST_DB_HOST:-localhost}" \
    --dbprefix=pk_test_ --skip-check --prompt=dbpass
"${wp_cli[@]}" db create
database_created=true
"${wp_cli[@]}" config set PAYKASSA_TEST_DATABASE true --raw
"${wp_cli[@]}" config set DISABLE_WP_CRON true --raw
"${wp_cli[@]}" config set WP_DEBUG true --raw
"${wp_cli[@]}" config set WP_DEBUG_DISPLAY false --raw
mkdir -p "$site_dir/wp-content/mu-plugins"
cp "$base_dir/tests/fixtures/disposable-site.php" "$site_dir/wp-content/mu-plugins/paykassa-test-isolation.php"
"${wp_cli[@]}" core install --url=http://paykassa.test --title='PayKassa integration' \
    --admin_user=paykassa_test --admin_password=local-test-password \
    --admin_email=paykassa@example.invalid --skip-email
"${wp_cli[@]}" plugin install woocommerce --version="${PAYKASSA_TEST_WC_VERSION:-11.1.0}" --activate
"${wp_cli[@]}" plugin install "$plugin_zip" --activate
"${wp_cli[@]}" eval 'if (! \Al5dy\PayKassaWoo\Infrastructure\Installer::schema_is_valid()) { throw new \RuntimeException("Fresh activation did not create the required schema."); }'
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-schema-upgrade.php" --use-include
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-upgrade-smoke.php" --use-include
for hpos in no yes; do
    "${wp_cli[@]}" option update woocommerce_custom_orders_table_enabled "$hpos"
    PAYKASSA_EXPECT_HPOS="$hpos" "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-smoke.php" --use-include
    PAYKASSA_EXPECT_HPOS="$hpos" "${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-reconciliation.php" --use-include
done
"${wp_cli[@]}" plugin deactivate paykassa
"${wp_cli[@]}" eval-file "$base_dir/tests/Integration/wp-cli-uninstall-smoke.php" --use-include
