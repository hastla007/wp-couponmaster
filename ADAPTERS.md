# Adapters: multilingual, multi-currency, subscriptions, product fields

Voucher Captain talks to other plugins only through documented hooks and their public APIs – never through their database tables or private classes. An adapter is a small class (built in under `src/Compat/`, or in your own plugin) that maps one of the contracts below to a specific extension. Built-in adapters are loaded only when the other plugin is active; none is a hard dependency.

## 1. Multilingual (WPML, WooCommerce Multilingual, Polylang)

| Contract | How |
|---|---|
| Translatable content | `wpml-config.xml`: PDF templates (`vcap_template`) and field groups (`vcap_field_group`) are translatable post types; product voucher settings, channels and coupon SKU rules are copied to translations. Promotions (`vcap_discount`) are not translated – one rule set applies to all languages; their titles can be translated as strings. |
| PDF in the order language | `vcap_render_template_id` (template id, voucher) – the WPML adapter returns the template translation for the order language (`wpml_object_id`). |
| Emails in the customer language | WooCommerce/WCML switch the locale for customer emails; Voucher Captain emails are WooCommerce emails (`WC_Email`) and follow it. |
| UI texts | Text domain `voucher-captain`; German (de_DE, de_AT) included. |
| Other plugins (e.g. Polylang) | Implement `vcap_render_template_id` with the plugin's "translated post" function, and set the locale before `vcap()->delivery->send()` if the plugin does not switch it for WooCommerce emails. |

## 2. Multi-currency

WooCommerce coupons – and therefore voucher values, balances and store credit – are stored in the **shop base currency**. Currency switchers convert coupon amounts on the fly in the cart.

| Contract | How |
|---|---|
| Voucher bought in another currency | `vcap_amount_to_base_currency( float $amount, string $currency, ?WC_Order $order )` converts the paid amount to the base currency before the voucher is created. Built in: WooCommerce Multilingual, Aelia, WOOCS, CURCY, WooPayments Multi-Currency. The rate of the sale is stored at checkout as order meta `_vcap_exchange_rate` (order-currency units per base unit) and wins over the live rate later. |
| No exchange rate known | Money is **never taken 1:1**. A rate counts as known for the base currency, a stored `_vcap_exchange_rate`, a built-in switcher, or another `vcap_amount_to_base_currency` callback that changes a probe amount (a callback returning the amount unchanged means "no rate"). Filter `vcap_currency_rate_known( bool $known, string $currency, ?WC_Order $order )` overrides this, e.g. for a converter with a rate of exactly 1. Without a rate: vouchers of the order are not issued (order on hold, note, admin notice), `refund_as_credit()` returns `vcap_credit_currency`, fixed-value vouchers are invalid in such a cart or order, a balance charge becomes a shortfall. Site Health ("Foreign currency without converter") lists refused currencies and switchers without adapter. |
| Voucher used in another currency | The same filter converts the discount of a foreign-currency order before it is deducted from a balance. |
| Line snapshot | `_vcap_line` keeps `order_currency` (paid) and `voucher_currency` (stored) separately. |
| Reports | Amounts in other currencies are reported on separate lines and never added to base-currency totals. |
| Store credit refunds | `refund_as_credit()` converts the refunded amount with the same filter (refused without a known rate). |

An adapter for another switcher implements one filter:

```php
add_filter( 'vcap_amount_to_base_currency', function ( float $amount, string $currency, $order ) {
    $rate = my_switcher_rate( $currency ); // units of $currency per 1 base unit
    return $rate > 0 ? $amount / $rate : $amount; // Unchanged = no rate: Voucher Captain refuses instead of 1:1.
}, 10, 3 );
```

Check the rate direction and rounding of your switcher with one test order per currency before selling.

## 3. Subscriptions (WooCommerce Subscriptions)

| Contract | How |
|---|---|
| Subscription product as voucher | Allowed; the first (parent) order issues vouchers like any order. |
| Renewals | Setting *Subscription renewals* (`vcap_subscription_renewals`, default off): when on, every paid renewal issues new vouchers; when off, renewals issue nothing (`vcap_should_issue_for_order`). |
| Copied order meta | Voucher-only meta is removed from renewal orders (`wc_subscriptions_renewal_order_data`, `wcs_renewal_order_meta`), so renewals never point to the parent's vouchers. |
| Paying renewals with gift cards/store credit | Not automatic – renewals are charged by the payment gateway. A connector may apply a coupon to the renewal order with `$order->apply_coupon()` before payment. |

Other subscription plugins: return `false` from `vcap_should_issue_for_order` for renewal orders you do not want to issue vouchers for, and strip `_vcap_*` meta when you copy orders.

## 4. Product fields and add-ons

| Contract | How |
|---|---|
| Field definitions | `vcap_field_definitions( array $fields, WC_Product $product )` – add, remove or change voucher fields per product (keys, type, label, required, maxlength, options). `vcap_builtin_fields` changes the built-in ones. |
| Values | Posted as `vcap_fields[key]` (product form) or `vcap_fields` in the Store API `cart/add-item` request (blocks). Stored in cart item data and on the order line (`_vcap_fields`), shown in cart, checkout, emails and as PDF placeholders. |
| Product configuration | `vcap_product_config( array $config, WC_Product $product )` – e.g. let a product-options plugin decide the voucher value or template. |
| Custom amount / price plugins | `vcap_custom_amount_price( float $price, array $cart_item )` sets the cart price of a "customer chooses amount" voucher; `vcap_voucher_amount( float $amount, WC_Order_Item_Product $item, array $config, WC_Order $order )` decides the face value issued. The paid line total is always in the line snapshot. |
| Add-on plugins that add their own price (gift wrap etc.) | Face value defaults to the paid line price ÷ vouchers. If add-ons must not increase the face value, set the voucher value source to *Fixed* or filter `vcap_voucher_amount`. |

## 5. WP Affiliate Captain (partner programme)

Built in: `src/Compat/AffiliateCaptain.php`, active only when WP Affiliate Captain reports integration contract 1.x (`wpac_contract_version()`).

| Contract | How |
|---|---|
| Partner per code campaign | Vouchers → Generate codes → Campaigns → *Partner (WP Affiliate Captain)*: enter the partner code. It is checked with `wpac_resolve_partner_code()` and stored in the option `vcap_affiliate_campaigns`. |
| Coupon → partner | The adapter answers Affiliate Captain's `wpac_coupon_affiliate` filter (priority 20) for coupons of an assigned campaign. A coupon linked to a partner directly in Affiliate Captain wins (priority 10). Paused or ended partners are resolved by Affiliate Captain and receive nothing. |
| Orders, refunds | Recorded by Affiliate Captain's own WooCommerce integration – Voucher Captain sends nothing itself, so there is no double booking. |
| Vouchers and gift cards | Purchased vouchers (`_vcap_voucher`) are means of payment and never map to a partner. By default the partner who brought the buyer of a voucher earns commission on the voucher sale (the redeeming order is reduced by the voucher amount, so nothing is paid twice). `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' )` pays nothing on voucher products instead. |
| Status | Listed on Affiliate Captain's integration page (`wpac_registered_integrations`). |

Test: `tests/affiliate-captain-integration.php` (runs with and without Affiliate Captain; without it the two contract functions are stubbed).

## 6. Rules for every adapter

- Load only when the other plugin is active (`class_exists` / `function_exists` on `plugins_loaded`).
- Use only the other plugin's public API and documented hooks; keep the adapter in one class.
- Never change Voucher Captain's tables or `_vcap_*` meta directly; use the services (`vcap()->vouchers`, `vcap()->redemptions`, `vcap()->store_credit`).
- Add an integration test that runs with and without the other plugin (a deterministic stub is fine when the plugin is commercial).
- See [COMPATIBILITY.md](COMPATIBILITY.md) for what CI covers and [CONNECTORS.md](CONNECTORS.md) for redemption connectors.
