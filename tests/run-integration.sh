#!/usr/bin/env bash
# Runs the WP-CLI integration suites against the WordPress in ./wp-test (see .github/workflows/plugin-smoke.yml).
#
# Needs a FRESH database: WordPress and WooCommerce freshly installed, Voucher Captain just activated, no other
# test run before (CI creates a new MySQL service per job). The suites share that database in the order below:
# they create products, vouchers, orders and users with unique names and clean up what later suites would trip
# over, but a second run on the same database (leftover vouchers, rate-limit counters, promotion counts, scheduled
# actions) is not supported. Locally: drop and recreate the database, reinstall WordPress and reactivate the plugins
# before each run. Suites that change shop settings or tax rates (tax, acceptance, blocks) restore them in
# `finally` via tests/state-guard.php, so a failing assertion does not change the conditions of the next suite.
set -euo pipefail
cd "$(dirname "$0")/.."
WP="php wp-cli.phar --path=wp-test"
# The compatibility suite also checks Yoast SEO; it runs only where Yoast is installed (VCAP_WITH_COMPAT=1).
compat=""
if [ "${VCAP_WITH_COMPAT:-0}" = "1" ]; then compat="compatibility"; fi
for suite in \
	woocommerce $compat security redemption order-lifecycle account coupon-link campaign reminder zero-total \
	purchase-rules first-order store-credit promotion bonus sku-rules ledger reports referral shortcodes \
	connector example-connector affiliate-captain captain-suite blocks fault-injection acceptance scoping tax email-texts validity bulk \
	topup tax-accounting rate-limit product-setup install delivery diagnostics counter pos pdf refund-lifecycle cart-coupons shop-admin caching store-checkout workflow; do
	echo "::group::${suite}"
	$WP eval-file "$PWD/tests/${suite}-integration.php"
	echo "::endgroup::"
done
