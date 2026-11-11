# Compatibility matrix

## Tested platform

The CI integration suite installs WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8 and Voucher Captain together. It runs against MySQL 8.4 and MariaDB 11.4, with both WooCommerce HPOS and legacy post storage. PHP lint and smoke checks run on PHP 8.1 and 8.4.

## Minimum versions vs. test baseline

| | Minimum (plugin header, enforced) | Test baseline |
|---|---|---|
| WordPress | 6.4 | 7.1.4 |
| WooCommerce | 8.9 (first version with the `woocommerce_coupon_get_items_to_apply` filter used by promotions, purchase rules and SKU rules – verified against the 8.8.0–10.0.0 releases; older versions show an admin notice and the plugin stays inactive) | 11.2.2 |
| PHP | 8.1 | 8.4 |
| Yoast SEO | not required | 28.8 (optional, tested alongside) |

The CI job `minimum-versions` installs WordPress 6.4.5 and WooCommerce 8.9.0 on PHP 8.1 **without** Yoast SEO and runs the full integration suite, so the minimums are tested, not assumed. Update both columns with every release.

## Bundled libraries

Voucher Captain bundles dompdf, php-font-lib, php-svg-lib, html5, php-qrcode and php-barcode-generator. Libraries that other plugins also ship are moved into the `VCAP\Vendor` namespace when the release ZIP is built (`bin/scope-vendor.php`), so two versions can never collide. This is currently `sabberworm/php-css-parser`: WooCommerce 8.x bundles an older, incompatible version, and loading both ended in a fatal error. The build fails if an unprefixed reference remains; `tests/scoping-integration.php` checks the installed ZIP.

## Optional integrations

| Integration | Contract | CI coverage |
|---|---|---|
| WooCommerce Subscriptions | Subscription products may issue vouchers. Renewal issuance is opt-in via `vcap_subscription_renewals` (default off); voucher-only order metadata is removed from renewal orders. | Renewal metadata filtering is tested without requiring the paid extension. |
| WooCommerce Multilingual (WCML) | Converts order-currency amounts with `unconvert_price_amount()`. | Adapter shape is documented; WCML itself is not bundled in CI. |
| Aelia Currency Switcher | Uses `wc_aelia_cs_convert` to convert the order amount to the shop base currency. | Converter filter contract is exercised with a deterministic test callback. |
| WOOCS | Converts by dividing by the selected currency rate returned by `get_currencies()`. | Provider is optional and not installed in CI. |
| CURCY (VillaTheme) | Converts by dividing by the selected currency rate from `get_list_currencies()`. | Provider is optional and not installed in CI. |
| Other currency switchers | Implement `vcap_amount_to_base_currency`; the fallback can use order meta `_vcap_exchange_rate`. | Unknown-provider fallback is tested. |

Provider integrations are optional. Each store should verify its currency switcher’s rate direction and rounding rules before sales in additional currencies.

## Checkout and product fields

Voucher fields use WooCommerce product add-to-cart hooks and cart/order-item data. The integration suite checks field definitions, cart data persistence, order-item persistence, Store API reservation, paid-order commit, payment retry behavior, and both HPOS and legacy order CRUD. Voucher Captain declares HPOS and Cart/Checkout Blocks compatibility. Block-based add-to-cart requests (Store API `cart/add-item`) accept the voucher fields as `vcap_fields` (object of field key ⇒ value); they are validated like the product form (required fields, email/date/number/select rules) and refused with the error code `vcap_invalid_voucher_fields`. Products with required or custom fields link to the product page instead of adding to the cart from product lists.

The CI job `browser-e2e` runs Playwright against a real shop (WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8): product page fields, keyboard-only use, classic cart/checkout and the cart/checkout blocks with cash on delivery, shortcodes and blocks, My Account, admin screens and the product editor next to Yoast SEO, axe-core accessibility scans of all Voucher Captain markup, and a PHP notice check. External payment gateways, redirects and webhooks, and screen-reader testing by a person remain release acceptance checks.
