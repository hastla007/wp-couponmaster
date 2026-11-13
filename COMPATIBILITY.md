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

## Multisite

Supported per site. Each site has its own voucher tables (`{site prefix}vcap_*`), settings, WooCommerce coupons and private upload folder; vouchers are never shared between sites. Network activation sets up every site (up to 500 at once; any further or later-created site is set up on its first request). Network deactivation suspends the balance coupons of every site. A network uninstall removes data only on the sites whose own "Delete data on uninstall" setting is enabled – the others keep their tables. CI job `multisite` checks network activation, a site created afterwards and the per-site uninstall.

## Bundled libraries

Voucher Captain bundles dompdf, php-font-lib, php-svg-lib, html5, php-qrcode and php-barcode-generator. Libraries that other plugins also ship are moved into the `VCAP\Vendor` namespace when the release ZIP is built (`bin/scope-vendor.php`), so two versions can never collide. This is currently `sabberworm/php-css-parser`: WooCommerce 8.x bundles an older, incompatible version, and loading both ended in a fatal error. The build fails if an unprefixed reference remains; `tests/scoping-integration.php` checks the installed ZIP.

## Optional integrations

CI job `ecosystem` installs each third-party plugin below from wordpress.org (latest release at run time; the job log
prints the version) next to WooCommerce 11.2.2 and Voucher Captain and runs `tests/ecosystem-integration.php`.

| Integration | Contract | CI coverage |
|---|---|---|
| **WooCommerce Stripe Gateway** (`woocommerce-gateway-stripe`) | Gateway-agnostic: vouchers are issued and balances charged on WooCommerce's paid statuses, never by the gateway itself. | `ecosystem (stripe)`: gateway registered; payment pending at the provider (on-hold) issues nothing; confirmation issues once, a repeated confirmation (webhook + return) not again; gift card + gateway with a failed attempt and a retry charges the card once; refund recorded without calling the provider voids the voucher; full refund of a redemption order gives the balance back. |
| **WooCommerce PayPal Payments** (`woocommerce-paypal-payments`) | as above | `ecosystem (paypal)`: the same flows with the `ppcp-gateway` gateway. |
| WooCommerce Subscriptions | Subscription products may issue vouchers. Renewal issuance is opt-in via `vcap_subscription_renewals` (default off); voucher-only order metadata is removed from renewal orders. | Renewal metadata filtering is tested without the extension (commercial, not installable in CI); a renewal with the real extension is a release acceptance check (`RELEASE_CHECKLIST.md`). Other subscription plugins are not supported out of the box (use `vcap_should_issue_for_order`, ADAPTERS.md 3). |
| **WooCommerce Multilingual** (WCML, `woocommerce-multilingual`, multi-currency without WPML) | Converts order-currency amounts with `unconvert_price_amount()`. | `ecosystem (wcml)`: rate read from WCML (1 EUR = 1.10 USD); a voucher paid with 55 USD is worth 50 EUR; an EUR gift card used on a USD order is charged the EUR value; cancelling gives it back. |
| **WOOCS** (`woocommerce-currency-switcher`) | Converts by dividing by the currency rate from `get_currencies()`. | `ecosystem (woocs)`: the same checks with WOOCS rates. |
| **CURCY** (`woo-multi-currency`, free edition; premium uses the same settings API) | Converts by dividing by the currency rate from `get_list_currencies()` (`WOOMULTI_CURRENCY_F_Data` free, `WOOMULTI_CURRENCY_Data` premium). | `ecosystem (curcy)`: the same checks with CURCY rates. |
| Aelia Currency Switcher | Uses `wc_aelia_cs_convert` to convert the order amount to the shop base currency. | Commercial, not installable in CI; the converter filter contract is exercised with a deterministic test callback. |
| Other currency switchers | Implement `vcap_amount_to_base_currency`; the fallback can use order meta `_vcap_exchange_rate`. | Unknown-provider fallback is tested. |

Real payments (card, PayPal account, redirects, the provider's webhooks against its sandbox) need the shop's own
provider accounts: they are a release acceptance check per shop (`RELEASE_CHECKLIST.md`). Each store should verify its
currency switcher's rate direction and rounding rules with one test order per currency before selling.

## Checkout and product fields

Voucher fields use WooCommerce product add-to-cart hooks and cart/order-item data. The integration suite checks field definitions, cart data persistence, order-item persistence, Store API reservation, paid-order commit, payment retry behavior, and both HPOS and legacy order CRUD. Voucher Captain declares HPOS and Cart/Checkout Blocks compatibility. Block-based add-to-cart requests (Store API `cart/add-item`) accept the voucher fields as `vcap_fields` (object of field key ⇒ value); they are validated like the product form (required fields, email/date/number/select rules) and refused with the error code `vcap_invalid_voucher_fields`. Products with required or custom fields link to the product page instead of adding to the cart from product lists.

The CI job `browser-e2e` runs Playwright against a real shop (WordPress 7.1.4, WooCommerce 11.2.2, Yoast SEO 28.8): product page fields, keyboard-only use, classic cart/checkout and the cart/checkout blocks with cash on delivery, shortcodes and blocks, My Account, admin screens and the product editor next to Yoast SEO, axe-core accessibility scans of all Voucher Captain markup, and a PHP notice check. External payment gateways, redirects and webhooks, and screen-reader testing by a person remain release acceptance checks.

## WP Pass Captain (tickets)

Release gate: Voucher Captain alone, WP Pass Captain alone and both together.

| Setup | Where it is checked |
|---|---|
| Voucher Captain without Pass Captain | Every suite of this repository's CI (`smoke`, `wordpress-integration`, `minimum-versions`, `browser-e2e`); Pass Captain code paths stay inactive. |
| Pass Captain without Voucher Captain | Pass Captain's own CI (hastla007/wp-passmaster), with a stub voucher provider. |
| Both plugins | CI job `pass-captain`: `tests/passcaptain-integration.php` against Pass Captain `main` and its release branch – vouchers and gift cards in the ticket checkout (reserve, commit, late payment, release, proportional refund, currencies, channels) and ticket vouchers (fixed date or date chosen by the customer, sold-out dates, attendee details, repeat, revoke on void). |

Both plugins keep their own plugin entries and admin menus (each links to the other while both are active; WooCommerce → Settings → Vouchers → Integrations shows the status). They talk only through the versioned filters `wp_pass_captain_voucher_provider_v1`, `wp_pass_captain_ticket_service_v1` and `wp_pass_captain_checkin_service_v1`, never through each other's tables.

**Which ticket to use:** Voucher Captain's voucher type *Ticket* is a simple admission pass without dates or capacity (e.g. a day pass sold in the shop), checked in at Voucher Captain. Events with dates, capacity, seats or admission rules belong in WP Pass Captain – sold there directly or through a ticket voucher. Existing Voucher Captain tickets keep working; while Pass Captain is active the product settings point this out.

**Shared scanner:** *Vouchers → Scanner* looks a code up in Voucher Captain first; codes it does not know that look like Pass Captain tickets (64-character code or ticket URL) are previewed and checked in through Pass Captain's check-in service, with Pass Captain's rules, locks, audit and the `wpcap_checkin` permission. Voucher redemption and ticket check-in stay separate actions.
