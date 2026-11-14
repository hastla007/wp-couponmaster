# Building a connector for Voucher Captain

A connector is a separate plugin that redeems Voucher Captain vouchers for something outside the WooCommerce cart: a course, a membership, a ticket, a service. Voucher Captain stays generic – no course or LMS fields in its core. The connector owns its service; Voucher Captain owns the voucher value.

A complete, tested reference implementation is in [`examples/vcap-example-connector`](examples/vcap-example-connector/vcap-example-connector.php) (CI: `tests/example-connector-integration.php`). API details: [API.md](API.md). Ledger states: [STATE_MACHINE.md](STATE_MACHINE.md).

## 1. Building blocks

| Need | Use |
|---|---|
| Own kind of voucher (e.g. "course voucher") | `vcap_voucher_types` filter – appears in the product's *Type* select |
| Redeem only in your plugin, not in the shop | Own channel via `vcap_redemption_channels` + `vcap_redemption_channel_for_target`; the merchant ticks it under *Redeemable via* |
| Decide what a voucher may unlock | `vcap_redemption_allowed` (voucher, target, user) – return `WP_Error` to refuse |
| Target must still exist | `vcap_redemption_target_available` |
| Redeem | `vcap()->redemptions` – `check`, `reserve`, `get`, `commit`, `release` (PHP) or `/wp-json/vcap/v1/redemptions` (REST) |
| React to completion / release | `vcap_voucher_redeemed`, `vcap_redemption_released` |
| React to voiding, refunds, edits | `vcap_voucher_event` (`voided`, `restored`, `reactivated`, …) |
| Order and refund data per line | `vcap()->api_v1->order_lines( $order )`, `VCAP\Issuer::line_data( $item )` |
| Own product settings | `vcap_product_settings_fields` (`WC_Product`) to print fields, `vcap_product_settings_save` (`WC_Product`, `$post`) to store them |
| Store something with each sold voucher line | `vcap_line_snapshot` (`array $extra`, `WC_Order_Item_Product`, `$config`, `WC_Order`) – extra keys in `_vcap_line`; core keys always win |
| Handle a code on the redeem page | `vcap_redeem_code` (`null`, `$code`) – return a result string (add your own `wc_add_notice()`) to take the code over, `null` to let the cart apply it |
| Extra fields in the redeem form | `vcap_redeem_form_fields` (print inputs, posted with the code) and `vcap_redeem_prefill` (pre-fill the code, e.g. while asking for more input) |
| Customer guidance | `vcap_voucher_next_steps` (list of "what happens next" steps in the voucher email; the redeem button is added), `vcap_email_intro` (intro text per voucher/email), `vcap_redeem_url` (redeem page link; setting "Redeem page") |
| Customer account | `vcap_account_voucher_status` (extra status lines), `vcap_account_voucher_actions` (extra buttons), `vcap_customer_can_resend` |
| Buttons on the voucher admin page | `vcap_voucher_admin_actions` (`Voucher`, `callable $form`) – call `$form( $do, $label )`; handle it in `vcap_voucher_admin_action` (`''`, `$do`, `Voucher`) and return a message key registered with `vcap_voucher_admin_messages` (keys ending in `_failed` show as errors) |
| Own events in the voucher history | `VoucherRepository::log()` plus a label via `vcap_event_labels` |
| Codes of another plugin in the scanner | `vcap_scanner_lookup` / `vcap_scanner_checkin` (`null`, `$code`) – called only for codes Voucher Captain does not know; return a scanner payload with your own `type` (see `Compat\PassCaptainScanner::view()`) or `null` |
| Scanner rules and texts | `vcap_scanner_redeem_refused` (`''`, `Voucher`) – reason why the counter refuses a voucher (also shown on lookup), `vcap_scanner_describe` (scanner payload), `vcap_checkin_undo_minutes` (default 10), `vcap_counter_reversal_minutes` (default 15), action `vcap_redemption_reversed` (`$voucher_id`, `$event_id`, `$amount`, `$reason`) |

## 2. Redemption flow (copy this)

1. **Derive a stable key** from what identifies the request, e.g. `myplugin:{user}:{hash(code)}` or `myplugin:grant:{grant id}`. Store it before calling Voucher Captain. A double click, a reload or a retry after a timeout must reuse the same key.
2. **`reserve( $code, [ 'key', 'target', 'user_id' ] )`** – locks the voucher. Same key + same request ⇒ same answer.
3. **Deliver your service idempotently**: store the redemption key with what you grant; if the key is already there, do nothing.
4. **`commit( $key )`** – final. Repeating it is harmless.
5. Only if you cannot deliver: **`release( $key )`** – the voucher becomes usable again.

### Errors and retries

| Answer | What to do |
|---|---|
| `vcap_temporary_failure` (503) | Keep the key, retry later (Action Scheduler) with the **same key** |
| Your service fails (timeout, outage) | Keep the reservation, retry steps 3–4 later. Give up after a limit and `release()` |
| `vcap_reservation_missing` on commit | The reservation expired (lifetime setting, default 15 min) and was released. Revoke anything you granted for this key; ask the user to redeem again |
| `vcap_cancelled` on commit | The voucher was voided while reserved (purchase refunded). Revoke, `release()`, tell the user |
| `vcap_already_redeemed` on reserve | Check whether *you* hold the grant for this key (completed by a retry) – then it is a success |
| Any other error | Show the message; nothing was reserved |

Retry jobs read the current state with `get( $key )` and continue from there: `reserved` ⇒ deliver + commit; `committed` ⇒ make sure the grant exists; `released` ⇒ revoke and forget the key. Make the reservation lifetime longer than your longest retry window, or expect `vcap_reservation_missing`.

## 3. Refunds and cancellations

| When | What Voucher Captain does | What the connector should do |
|---|---|---|
| Purchase order refunded/cancelled **before** any redemption | Voucher is voided (setting *Void vouchers on refund*, default on); `check`/`reserve` answer `vcap_cancelled` | Nothing |
| … **while** a reservation is open | Voucher is voided; `commit()` answers `vcap_cancelled`; `release()` keeps it voided | Revoke, release, inform the user |
| … **after** the redemption was committed | Voucher is voided (event `voided`); the redemption stays committed | Your policy: keep the access (service already delivered) **or** revoke it on `vcap_voucher_event` / `voided`. Document it in your terms. The example connector revokes. |
| Partial refund of the purchase order | Vouchers stay valid | Nothing |
| The service is refunded/cancelled in the connector | Nothing automatic | If the customer should get the voucher back, ask the merchant to *Reactivate* it (admin) – committed redemptions are never undone automatically |

## 4. Group packages: per seat or per package?

Voucher Captain issues **one voucher per unit**: quantity × *PDFs per item* (product setting) – see `vouchers` in the line snapshot. Decide in the connector:

- **Per seat** (recommended): one voucher unlocks one seat. Sell the package with *PDFs per item* = number of seats; each recipient redeems their own code. Refunds and transfers work per seat.
- **Per package**: one voucher unlocks the whole package. Keep *PDFs per item* = 1 and store the seat count in your target (e.g. `myplugin:package:5`) or read it from `order_lines()` (`voucher_line` + product). Partial refunds of a package must then be handled by the connector.

Discount codes and promotions always apply to the order line (the package price), never per seat.

## 5. "Customer owns product X" and external member prices

Voucher Captain does not know what a customer owns in another plugin. Use the existing hooks:

- **Only owners may redeem / only non-owners may redeem**: refuse in `vcap_redemption_allowed` (`$user_id` is the authenticated user).
- **Discount codes only for owners**: `woocommerce_coupon_is_valid` (throw an `Exception` with your message), or a promotion customer group via a user role your plugin assigns (Vouchers → Promotions → *Customer groups*).
- **Member prices or bundle prices from another plugin**: promotions and coupons are calculated on the cart price that plugin produced. If a promotion must not stack on member prices, limit it to a customer group without members (user roles), or remove the member-priced items in `woocommerce_coupon_get_items_to_apply` (priority > 20). To keep promotions apart from discount codes, untick *Also applies when the customer uses a coupon code*. Voucher products are never discounted unless the merchant allows it.
- Gift cards and store credit always pay the remaining amount **after** all discounts (`woocommerce_coupon_sort`).

## 6. API versioning policy

- The public API is: `vcap()->api_v1`, `vcap()->redemptions`, the REST namespace `vcap/v1`, the `VCAP\Contracts\*` interfaces, the hooks listed in API.md/CONNECTORS.md (all prefixed `vcap_`) and the documented order/coupon meta (`_vcap_line`, `_vcap_fields`, `_vcap_voucher`).
- Within **v1** changes are additive only: new methods, new optional arguments, new array keys, new hooks, new error codes for new situations. Existing keys, error codes, HTTP statuses and hook arguments keep their meaning.
- Breaking changes get a new version side by side (`api_v2`, `vcap/v2`). The previous version stays available for at least two minor releases and six months, with a deprecation notice (`_deprecated_function` and a changelog entry).
- Every public method and hook carries `@since`. Everything else (classes without a documented contract, database tables, private meta) is internal and may change in any release – never query Voucher Captain's tables directly.
- Connectors should check `defined( 'VCAP_VERSION' ) && version_compare( VCAP_VERSION, '1.1.0', '>=' )` and `function_exists( 'vcap' )` before registering hooks.

## 7. Checklist for a connector release

- [ ] Stable key per request, stored before `reserve()`
- [ ] Idempotent grant (key stored with the grant)
- [ ] Retry job with limit; `release()` when giving up
- [ ] Handling of `vcap_reservation_missing`, `vcap_cancelled`, `vcap_already_redeemed`
- [ ] Refund policy after redemption decided and documented
- [ ] Own channel registered; merchant documentation tells them to tick it
- [ ] Two-plugin integration test (Voucher Captain + connector) in CI, including a forced outage
