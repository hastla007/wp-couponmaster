# Adapters: multilingual, multi-currency, subscriptions, product fields

Voucher Captain talks to other plugins only through documented hooks and their public APIs – never through their database tables or private classes. An adapter is a small class (built in under `src/Compat/`, or in your own plugin) that maps one of the contracts below to a specific extension. Built-in adapters are loaded only when the other plugin is active; none is a hard dependency.

## 1. Multilingual (WPML, WooCommerce Multilingual, Polylang)

| Contract | How |
|---|---|
| Translatable content | `wpml-config.xml`: PDF templates (`vcap_template`) and field groups (`vcap_field_group`) are translatable post types; product voucher settings, channels and coupon SKU rules are copied to translations, redemption conditions and the event location are translated. A unit test checks that every product setting (`ProductConfig::DEFAULTS`, enabled, top-up) is listed. Promotions (`vcap_discount`) are not translated – one rule set applies to all languages; their titles can be translated as strings. |
| Settings texts | Default and per-country redemption conditions and the texts of the voucher emails (subject, heading, body, additional content): WPML string translation through `<admin-texts>` in `wpml-config.xml`; with Polylang they are registered as strings (Languages → Translations, group "Voucher Captain"). |
| PDF in the order language | `vcap_render_template_id` (template id, voucher) – WPML (`wpml_object_id`) and Polylang (`pll_get_post`) return the template translation for the order language. |
| Emails in the customer language | `Compat\Multilingual`: every voucher email (also scheduled deliveries, reminders, resends and bonus mails, which WCML does not handle) switches to the order language – WPML order meta `wpml_language`, Polylang `pll_get_post_language()`, filter `vcap_order_language` – and to its locale (filter `vcap_email_locale`, default: site language), so texts, dates in the PDF and links follow it. Staff PDF downloads use the same language. |
| UI texts | Text domain `voucher-captain`; German (de_DE, de_AT) included. Site Health asks for a translation only for languages the shop offers (site language, WPML/Polylang languages). |
| Other plugins | Return the order's language code with `vcap_order_language` and its locale with `vcap_email_locale`, and implement `vcap_render_template_id` with the plugin's "translated post" function. |

## 2. Multi-currency

WooCommerce coupons – and therefore voucher values, balances and store credit – are stored in the **shop base currency**. Currency switchers convert coupon amounts on the fly in the cart.

| Contract | How |
|---|---|
| Voucher bought in another currency | `vcap_amount_to_base_currency( float $amount, string $currency, ?WC_Order $order )` converts the paid amount to the base currency before the voucher is created. Built in: WooCommerce Multilingual, Aelia, WOOCS, CURCY, WooPayments Multi-Currency. The rate of the sale is stored at checkout as order meta `_vcap_exchange_rate` (order-currency units per base unit) and wins over the live rate later. |
| No exchange rate known | Money is **never taken 1:1**. A rate counts as known for the base currency, a stored `_vcap_exchange_rate`, a built-in switcher that knows the currency (WCML and Aelia have no rate list: a currency they return unchanged counts as unknown), or another `vcap_amount_to_base_currency` callback that changes a probe amount (a callback returning the amount unchanged means "no rate"). Filter `vcap_currency_rate_known( bool $known, string $currency, ?WC_Order $order )` overrides this, e.g. for a converter with a rate of exactly 1. Without a rate: vouchers of the order are not issued (order on hold, note, admin notice), `refund_as_credit()` returns `vcap_credit_currency`, fixed-value vouchers are invalid in such a cart or order, a balance charge becomes a shortfall. Site Health ("Foreign currency without converter") lists refused currencies and switchers without adapter. |
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

## 5. Affiliate Captain (partner programme)

Built in: `src/Compat/AffiliateCaptain.php`, active only when Affiliate Captain reports integration contract 1.x (`wpac_contract_version()`).

| Contract | How |
|---|---|
| Partner per code campaign | Vouchers → Generate codes → Campaigns → *Partner (Affiliate Captain)*: enter the partner code. It is checked with `wpac_resolve_partner_code()` and stored in the option `vcap_affiliate_campaigns`. |
| Coupon → partner | The adapter answers Affiliate Captain's `wpac_coupon_affiliate` filter (priority 20) for coupons of an assigned campaign. A coupon linked to a partner directly in Affiliate Captain wins (priority 10). Paused or ended partners are resolved by Affiliate Captain and receive nothing. |
| Orders, refunds | Recorded by Affiliate Captain's own WooCommerce integration – Voucher Captain sends nothing itself, so there is no double booking. |
| Vouchers and gift cards | Purchased vouchers (`_vcap_voucher`) are means of payment and never map to a partner. By default the partner who brought the buyer of a voucher earns commission on the voucher sale (the redeeming order is reduced by the voucher amount, so nothing is paid twice). `add_filter( 'vcap_affiliate_commission_on_voucher_sales', '__return_false' )` pays nothing on voucher products instead. |
| Status | Listed on Affiliate Captain's integration page (`wpac_registered_integrations`). |

**No double commission (today's rule).** Affiliate Captain is the only place where commission is booked, and only on
WooCommerce orders it records itself. Voucher Captain sends Affiliate Captain no sales events of its own; it only
answers `wpac_coupon_affiliate` (which partner a campaign code belongs to) and `wpac_commission_base`. Per flow:

| Flow | Commission |
|---|---|
| Buying a voucher or gift card (WooCommerce order) | Once, on that order, for the partner Affiliate Captain attributes to the buyer (referral link, cookie). With `vcap_affiliate_commission_on_voucher_sales` false: 0 on voucher lines. |
| Redeeming it in a later shop order | The voucher reduces that order (coupon) or pays part of it (gift card): the voucher value is not counted again; the partner of that order earns only on what was paid with money. The voucher code itself never maps to a partner (`_vcap_voucher`). |
| Redemption at the counter, through the redemption API or in the Pass Captain checkout | No WooCommerce order: no commission. |
| Campaign discount code (Vouchers → Generate codes) used in an order | Once, on that order, for the campaign's partner (unless Affiliate Captain links the coupon directly). |
| Refund of the voucher order | Handled by Affiliate Captain's own refund logic for that order; Voucher Captain voids the voucher. |
| Vouchers issued without an order (New voucher, card import, confirmed voucher requests from forms) | No order: no commission. Company orders that should earn commission must go through a WooCommerce order. |
| Review source events (`vcap_review_redemption_*`) | Never commission-relevant. |

A future sales-source connector (voucher events as their own Affiliate Captain source) would need its own source key
and idempotency and must exclude orders that Affiliate Captain already records – it is not part of 1.2.0.

Test: `tests/affiliate-captain-integration.php` (runs with and without Affiliate Captain; without it the two contract functions are stubbed).

## 6. Rules for every adapter

- Load only when the other plugin is active (`class_exists` / `function_exists` on `plugins_loaded`).
- Use only the other plugin's public API and documented hooks; keep the adapter in one class.
- Never change Voucher Captain's tables or `_vcap_*` meta directly; use the services (`vcap()->vouchers`, `vcap()->redemptions`, `vcap()->store_credit`).
- Add an integration test that runs with and without the other plugin (a deterministic stub is fine when the plugin is commercial).
- See [COMPATIBILITY.md](COMPATIBILITY.md) for what CI covers and [CONNECTORS.md](CONNECTORS.md) for redemption connectors.

## 7. Form Captain (voucher requests)

Built in: `src/Integrations/FormCaptain/`, loaded only after `form_captain_loaded_v1` with contract major 1 (Form Captain 1.0 or newer). Without Form Captain nothing is registered. Form Captain contract: `wp-formmaster/docs/spec/api-v1.md`, `docs/CONNECTORS.md`.

| Contract | How |
|---|---|
| Connector | Key `voucher_captain` via `form_captain_connectors_v1`, action `voucher_captain.voucher_request` ("Voucher request to Voucher Captain") via `form_captain_register_action_v1()`. Form Captain keeps the connector disabled until the site opts in (*Form Captain → Integrations*). No entitlement: Voucher Captain is free. |
| Field mapping (allowlist) | `company` (text, optional), `contact_name` (text), `email` (email), `quantity` (number/select/radio/text/hidden), `value` (same types, per voucher, base currency, "50", "50,50", "1.250,00"), `personalization` (textarea/text, optional, ≤ 1000 characters), `consent` (consent/checkbox). No sensitive fields; unmapped fields never arrive. Action setting `product_id`: optional voucher product suggested for the approval. |
| What a submission does | Stores one **request** in the own table `{prefix}vcap_fc_requests` (status `pending`, "To be reviewed") – never a voucher, coupon, order, email or payment. The worker of Form Captain runs the callback; the job's mapped `values` are the only data read (no Form Captain tables, no `form_captain_get_entry_v1()` call needed). |
| Idempotency | Request key `form-captain:{site}:{form}:{entry}` (UNIQUE). Repeated deliveries, a second action instance on the same form and retries after a timeout return `success` with the same `remote_id` (`vcap-request-{id}`) and store nothing new. |
| Results | `success` (`created`, `already_created`); `retry` `voucher_captain_unavailable` (WooCommerce/Voucher Captain not running or table missing, filter `vcap_form_captain_available`) and `storage_failed`; `fail` with `invalid_job`, `missing_contact`, `invalid_email`, `invalid_quantity` (1–100, filter `vcap_form_captain_max_quantity`), `invalid_value` (> 0, ≤ 10000, filter `vcap_form_captain_max_value`), `text_too_long`, `consent_missing`. Test runs (`test_mode`) validate only and store nothing. Without WooCommerce the connector reports `requirements.ok = false`, so Form Captain retries instead of losing the job. |
| Review | *Vouchers → Voucher requests* (capability `vcap_manage_vouchers`, nonce per request): list by status, edit an open request (validated like a submission), **approve** or **reject** (internal reason). |
| Approval | Exactly once per request (atomic status change `pending → approving → approved`). Creates a WooCommerce order (`created_via = vcap_form_captain`, meta `_vcap_fc_request`) for the contact with the chosen voucher product (simple product or variation, one voucher per item), quantity × requested value, the personalisation as personal message, and calls `vcap()->issuer->issue_for_order( $order, true )` – the existing issuing service (codes, coupons, ledger, delivery). The order is set *on hold* (payment by invoice, outside the plugin). If nothing could be issued the order is cancelled and the request stays open. Voucher tables are never written by the adapter. |
| Rejection | Status `rejected`; nothing is issued, no order. |
| Erasure (`form_captain_entry_deleted_v1`) | Open, rejected and withdrawn requests of the entry are deleted. Approved requests keep their numbers and order link but lose company, contact, e-mail and personalisation (the order belongs to WooCommerce and its privacy tools); issued vouchers stay untouched. |
| Consent withdrawn (`form_captain_consent_withdrawn_v1`) | An open request becomes `withdrawn` and loses its personal data (it can never be approved); rejected requests are anonymised; approved requests stay (vouchers were issued under a contract – Form Captain sends `entry_deleted_v1` if the entry is erased). |
| Hooks | `vcap_form_captain_request_created` (id), `vcap_form_captain_request_approved` (id, order, vouchers), `vcap_form_captain_request_rejected` (id). |
| Uninstall | The table is dropped with the other tables when *Delete data on uninstall* is on. |

Test: `tests/form-captain-integration.php` (needs Form Captain; uses the contract test kit `tests/integration/Contract/ConnectorContractAssertions.php` of Form Captain; CI job `form-captain` against Form Captain `main` and the contract 1.1.0 branch).

### 7.1 Hand-over to the voucher request service (one store, one screen)

When Voucher Captain provides the request service `vcap_voucher_request_service_v1` (contract 1.x; `VCAP\Integrations\VoucherRequests`, being built on branch `claude/wp-plugin-analyse-todo-hvollo-refactors`), the adapter hands every checked request to `submit()` instead of storing it in its own table:

- reference `formcaptain:{site hash}:{form}:{entry}` (idempotent in the service), kind `company_order` or `personalisation` (no company, with a text), product, amount, quantity, contact and message; nothing about payment or issuing;
- answers: created / replayed → `success` with remote id `vcap-request-{id}`; HTTP 429/503 → `retry`; other errors → final `fail` with the code of the service; rejected earlier → `success` `already_rejected` (no new request);
- the adapter's own screen "Voucher requests" is hidden while its fallback table is empty, so there is one menu entry.

Without the service (Voucher Captain versions before it) the adapter keeps using its own table `vcap_fc_requests` and screen as described above.

Erasure with the service: data protection requests are handled by the service's own exporter/eraser (by e-mail address, run by WordPress together with Form Captain's), its retention removes open requests after 6 months. Single entries (VC-FC-06): on `form_captain_entry_deleted_v1` and `form_captain_consent_withdrawn_v1` the adapter calls `forget_reference( $reference )` of the service with the reference of the hand-over (and still cleans its fallback table). A service without that method is left alone; this is logged once and shown to managers as an admin notice ("request service without forget_reference – deletion per entry not possible, data protection through the service's eraser").

**Contract `forget_reference( string $reference ): bool|\WP_Error`** (service contract 1.x, additive):

| Rule | |
|---|---|
| Caller | Runs **without a capability check** – it is called from WP-Cron, retention or WP-CLI of the form plugin. Never exposed to visitors (no REST route, no AJAX). |
| Open request | Deleted with its data. |
| Partly issued, issued, handed over | Anonymised: contact name, e-mail, phone, recipients, message and note are removed; company, amounts, counts and the issued vouchers stay (voucher ledger). |
| Claim | The claim of the reference is set to "rejected" (with UTC time) **before** the request goes, so a repeated `submit()` with the same reference stores nothing new and answers `state = rejected`. The retention removes old rejected claims. |
| Event | Fires `vcap_voucher_request_forgotten( int $request_id, string $result )` with `deleted`, `anonymized` or `none` (request id 0); no personal data. |
| Answer | `true` when a request was deleted or anonymised, `false` when none was stored for the reference, `WP_Error` (`vcap_invalid_args`, 400) for an empty reference. Repeating the call is harmless. |

Implemented in `VCAP\Integrations\VoucherRequests` on branch `claude/wp-plugin-analyse-todo-hvollo-refactors` (commit `b18621b`); anonymising also removes the stored issue errors (`_vcap_request_errors`). A repeated `submit()` of a forgotten reference answers `state = rejected` with `request_id = 0`; the adapter reports that as `success` / `already_rejected`.

Test: `tests/form-captain-request-service.php` (22 checks, fake services with the interface of the real one, with and without `forget_reference()`).

