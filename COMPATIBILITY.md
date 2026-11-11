# Compatibility matrix

## Tested platform

The CI integration suite installs WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8 and Voucher Captain together. It runs against MySQL 8.4 and MariaDB 11.4, with both WooCommerce HPOS and legacy post storage. PHP lint and smoke checks run on PHP 8.1 and 8.4.

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

The current CI suite is API and hook level. It does not run browser automation for classic checkout, Checkout blocks, the product page, Yoast admin screens, external payment gateways, or gateway webhooks. Those UI and provider combinations remain release acceptance checks.
