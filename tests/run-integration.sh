#!/usr/bin/env bash
# Runs the WP-CLI integration suites against the WordPress in ./wp-test (see .github/workflows/plugin-smoke.yml).
set -euo pipefail
cd "$(dirname "$0")/.."
WP="php wp-cli.phar --path=wp-test"
# The compatibility suite also checks Yoast SEO; it runs only where Yoast is installed (VCAP_WITH_COMPAT=1).
compat=""
if [ "${VCAP_WITH_COMPAT:-0}" = "1" ]; then compat="compatibility"; fi
for suite in \
	woocommerce $compat security redemption order-lifecycle account coupon-link campaign reminder zero-total \
	purchase-rules first-order store-credit promotion bonus sku-rules reports referral shortcodes \
	connector example-connector affiliate-captain blocks fault-injection acceptance scoping tax email-texts validity \
	topup license-lifecycle rate-limit; do
	echo "::group::${suite}"
	$WP eval-file "$PWD/tests/${suite}-integration.php"
	echo "::endgroup::"
done
