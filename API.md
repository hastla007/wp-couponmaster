# Voucher Captain PHP API v1

Voucher Captain exposes an in-process PHP service at `vcap()->api_v1`. This is a plugin integration contract, not a public REST API. Calls must run after the plugin has booted and WooCommerce is available: from the action `vcap_loaded` on (since 1.2.0; `vcap()` is defined only then, see CONNECTORS.md).

## Methods

`lookup(string $code)` returns a normalized voucher data array or a `WP_Error`.

`reserve(string $code, float $amount, WC_Order $order)` reserves stored balance against a persisted WooCommerce order. Repeating the same amount for the same order and voucher returns the existing reservation. A different amount conflicts.

`commit(string $code, WC_Order $order)` commits the order's reservation. Repeated commits return the committed result.

`release(string $code, WC_Order $order)` returns reserved balance to the voucher. It returns a conflict error when no active reservation exists.

Errors use stable codes: `vcap_not_found`, `vcap_invalid_redemption`, `vcap_balance_only`, `vcap_balance_conflict`, and `vcap_reservation_missing`. Error data includes an HTTP-style `status`, but this facade does not register REST routes.

## Example

```php
$service = vcap()->api_v1;
$voucher = $service->lookup( $code );
if ( is_wp_error( $voucher ) ) {
    return $voucher;
}

$reservation = $service->reserve( $code, 20.00, $order );
if ( is_wp_error( $reservation ) ) {
    return $reservation;
}

// Call after payment has been confirmed.
$result = $service->commit( $code, $order );
```

Always use the same saved `WC_Order` for retries. The order ID and voucher ID form the idempotency scope; callers do not supply arbitrary idempotency keys.

## Provider extension

The `VCAP\\Contracts\\RedemptionProvider` interface is the boundary for external voucher stores. Add providers through the `vcap_redemption_providers` filter. Each provider must implement `supports`, `lookup`, `reserve`, `commit`, and `release`; return arrays with the documented operation result or a `WP_Error`. Providers must enforce their own atomic reservation and retry semantics.

Native types (since 1.2.0): the facades `VoucherService`, `RedemptionService` and `RestController` declare their return types natively (`array|\WP_Error`, `bool|\WP_Error`, `\WP_REST_Response|\WP_Error`), exactly the types they already documented; all of them are `final`, so this changes nothing for callers. The `RedemptionProvider` interface stays without native return types on purpose: adding them would force every existing provider to change its method signatures, which is a breaking change reserved for API v2. Your provider may declare `: array|\WP_Error` itself (PHP allows narrowing an untyped interface method). A provider result that is neither an array nor a `WP_Error` is answered by `VoucherService` with the error `vcap_provider_invalid_response` (HTTP status 502) and logged.

```php
add_filter( 'vcap_redemption_providers', function( array $providers, string $code, $service ): array {
    array_unshift( $providers, new My_Ticket_Captain_Provider() );
    return $providers;
}, 10, 3 );
```

Register a provider only when its external system is available. This hook defines the integration seam; it does not itself install or activate a Ticket Captain connector. A real cross-plugin adapter still needs a shared contract version and a two-plugin integration test.

## External redemptions (since 1.1.0)

`vcap()->redemptions` redeems vouchers **without** a WooCommerce order, for example to unlock a course, grant a membership or issue a ticket in another plugin. It works for single-use vouchers (the whole voucher) and stored-balance gift cards (a partial amount). The WooCommerce cart reads the same voucher state, so a code cannot be spent in the cart and externally at the same time.

### Flow

1. `check( $code, [ 'target' => …, 'amount' => … ] )` – optional pre-check, reserves nothing.
2. `reserve( $code, [ 'key' => …, 'target' => …, 'amount' => …, 'meta' => [] ] )` – locks the voucher (single-use: status `reserved`; gift card: the amount is deducted). The reservation expires after the configured lifetime (WooCommerce → Settings → Vouchers → Integrations, default 15 minutes) and is then released automatically – by a background job, and also right away wherever the voucher or redemption is read (`check()`, `reserve()`, `get()`, the cart), so it does not depend on WP-Cron. An expired reservation can no longer be committed (`vcap_reservation_missing`). `meta` may hold at most 8 KB (as JSON) in at most 3 levels (`vcap_invalid_request` otherwise).
3. Deliver your service (grant the course, issue the ticket …).
4. `commit( $key )` makes the redemption final and fires `vcap_voucher_redeemed` exactly once – or `release( $key )` gives the value back (fires `vcap_redemption_released`).

`get( $key )` returns the current state. All methods return an array or a `WP_Error`.

### Idempotency

The caller supplies the key (8–64 characters `A–Z a–z 0–9 . _ : -`, e.g. a UUID or `learnboating:grant:1234`). Repeating `reserve()` with the same key and the same request returns the existing redemption while it is `reserved` or `committed` (marked `replayed: true`; REST answers `200` instead of `201`); a reservation that was released or expired answers `vcap_redemption_released` (409) – it holds nothing, so do not deliver for it: revoke and redeem again under a new key. The same key with a different voucher, target, amount or user returns `vcap_idempotency_conflict`. `commit()` and `release()` are idempotent. Store the key before calling `reserve()` so a timeout can be retried safely.

### Identity

PHP callers may pass `user_id`; otherwise the logged-in user is used. Personal vouchers (restricted to the buyer's or recipient's email) are redeemed only for a user whose account email matches (V6, see SECURITY.md → Personal vouchers); a guest is refused with `vcap_login_required` (403, "Please log in with j***@example.com …"), another account with `vcap_not_allowed`. PHP callers may pass the customer's `email` together with `email_verified => true` when they vouch that the customer controls that address (their own authenticated customer account, staff who checked the holder's ID); an `email` without `email_verified` – e.g. an address a guest typed in a checkout – never unlocks a personal voucher. Store credit (type `credit`) is never redeemable through this API – it belongs to one customer account and is used in the shop. The REST API always uses the authenticated user and ignores any `user_id` in the request. Only the reserving user or a voucher manager may read, commit or release a redemption (`vcap_rest_can_access_redemption` filter). For any other caller a redemption of another user, one with an internal key or target and an unknown key all answer the same `404 vcap_reservation_missing`, so the API never tells whether such a key exists; `commit`, `release` and `refund` check these rules (and the owner) again in the service under the row lock (S9).

### REST

| Method | Route | Body | Success |
|---|---|---|---|
| POST | `/wp-json/vcap/v1/redemptions/check` | `code`, `pin`, `target`, `amount`, `currency` | 200 voucher data |
| POST | `/wp-json/vcap/v1/redemptions` | `code`, `pin`, `key`, `target`, `amount`, `currency`, `meta` | 201 redemption (200 for a repeated request) |
| GET | `/wp-json/vcap/v1/redemptions/{key}` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/commit` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/release` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/refund` | `amount`, `refund_key` | 200 redemption with `refunded` (total) and `restored` (this call) |

PIN cards (M12-99): imported codes can carry a PIN (import option "PIN column", stored hashed). For customers such a code is found only with its PIN – as `pin` or in the convention `code#PIN`, which every code field of the shop accepts (coupon field, Store API, redeem form, balance check, top-up); without it the answer is `404 vcap_not_found`, counted like an unknown code. Shop staff are not asked for the PIN (they hold the card). Codes without a PIN behave as before.

Authentication: browsers of logged-in users send the WordPress REST nonce (`X-WP-Nonce`, from `wp_create_nonce( 'wp_rest' )`); external servers use WordPress Application Passwords for a dedicated technical user. Anonymous requests receive `401`. Narrow access with the `vcap_rest_can_redeem` filter.

Targets over REST: shop managers (`manage_woocommerce` / `vcap_manage_vouchers`, e.g. the technical user of a connector server) and accounts with the optional capability `vcap_redeem_api` (a "Voucher POS" account, see *Counter API* below) may use any target; customers only targets whose prefix a connector registered with `vcap_rest_redemption_target_prefixes` (e.g. `add_filter( 'vcap_rest_redemption_target_prefixes', fn( $p ) => array_merge( $p, array( 'learnboating:' ) ) )`) – without one, customers can check codes but not reserve them over REST, and they cannot commit or release a redemption a connector made for them with an unregistered target. Targets of built-in integrations (`passcaptain:…`, filter `vcap_internal_redemption_target_prefixes`) are refused over REST for everybody (`403 vcap_not_allowed`). Keys are global, so over REST a key with a prefix (`system:…`) must start with the prefix of its target (`400 vcap_invalid_request`; UUID keys are free), and keys a connector creates on the server (Pass Captain `wpcap-…`/`passcaptain:…`, Learnboating `learnboating:grant:…`) are never accepted over REST – connectors add theirs with `vcap_internal_redemption_key_prefixes`. PHP callers are not affected.

Currency (M12-33): fixed values are redeemed only in the currency the voucher is kept in. `currency` (optional, ISO 4217, e.g. `EUR`) names the currency of the calling system; without it the shop base currency is expected. A voucher kept in another currency (e.g. issued before the shop base currency was changed) is refused with `409 vcap_currency_mismatch`, never redeemed 1:1. Percentage vouchers have no currency. PHP callers pass `currency` in `$args` of `check()` and `reserve()`.

Redemption data: `redemption_key`, `state` (`reserved`, `committed`, `released`), `voucher_id`, `code`, `user_id`, `target`, `amount`, `currency`, `balance_after` (gift cards), `expires_at` (while reserved), `created_at`, `committed_at` (UTC), `meta`.

Voucher data (`check`): `code`, `type`, `usage_mode`, `value`, `balance` (gift cards), `currency`, `expires_at`, `product_id` and the VAT classification `purpose` (`multi` = multi-purpose voucher, a means of payment; `single` = single-purpose voucher; `discount` = promotion), `treatment` (`payment`, `single`, `discount`) and `sale_tax` (single-purpose vouchers: `{rate_bp, country}` of the sale, else `null`).

Refund (`/refund`): gives back (part of) a committed redemption, same access rules as `commit` (the user who reserved, or a voucher manager); idempotent per `refund_key` (8–64 characters like `key`). Errors: `vcap_not_committed` (409), `vcap_refund_exceeded` (409), see *Refunds of completed redemptions*.

Till and receipt: `meta.till` (till/branch) and `meta.receipt` (receipt number of the till) given with the reservation are stored with the `api_committed` and `api_refunded` ledger rows, like the scanner's bookings (journal and till columns).

### Counter API (point of sale)

A till system can redeem vouchers in two ways:

1. **Redemption API** (recommended for external POS systems): `check` → `POST /redemptions` (target e.g. `pos:shop-1`, `meta.till`, `meta.receipt`) → `commit` after the sale is paid, `release` if it is cancelled, `refund` for a return. Idempotent per key; the account needs no admin rights. Create a dedicated user with a role "Voucher POS" that has only `read` and `vcap_redeem_api`, and give it an Application Password, e.g.:

   ```
   wp role create voucher_pos "Voucher POS"
   wp cap add voucher_pos read vcap_redeem_api
   wp user create pos-shop1 pos-shop1@example.com --role=voucher_pos
   wp user application-password create pos-shop1 "Till shop 1"
   ```

   Such an account may use any target except the internal ones and reads/commits/releases/refunds only its own redemptions. The counter daily limits do not apply to the redemption API.
2. **Scanner routes** (the built-in scanner; a stable contract for staff devices with the capability `vcap_scan_tickets`, role "Voucher cashier"):

| Method | Route | Body | Answer |
|---|---|---|---|
| POST | `/wp-json/vcap/v1/lookup` | `q` (code, token or scanned URL) | voucher as shown in the scanner: `state` (`ok`/`warning`/`error`), `message`, `balance`, `currency`, `decimals`, `purpose`, `treatment`, `sale_tax`, `treatment_label`, `personal` (`{names, emails}` masked, or `null`), `reversible` (own cancellable bookings), `receipt_required` |
| POST | `/wp-json/vcap/v1/redeem` | `q`, `amount` (gift cards), `receipt`, `till`, `note`, `allow_partial`, `id_checked`, `idempotency_key` | the voucher plus `success`, `booked`, `balance_after`, `event_id`, `slip` (data of the redemption slip) |
| POST | `/wp-json/vcap/v1/redeem/reverse` | `q`, `event_id`, `reason`, `idempotency_key` | the voucher plus `success` |
| POST | `/wp-json/vcap/v1/checkin` | `q`, `count`, `undo`, `reason`, `till`, `idempotency_key` | the ticket plus `success` |

   Rules: a personal voucher needs `id_checked=true` (staff checked the ID against `personal.names`; else `409 vcap_id_check_required`); with the setting "Receipt no. required" a redemption without `receipt` is refused (`400 vcap_receipt_required`; default "automatic": required for shops based in Germany or Austria); with a managed list of tills (setting "Tills / branches") `till` must be one of them (`400 vcap_till_unknown`), and a staff member fixed to a till on their profile always books there. A repeated request with the same `idempotency_key` gets the stored answer with `replayed: true` – nothing is booked again. Unknown codes are limited per staff member (100 per 15 minutes, filter `vcap_rate_limit_scanner_lookup`; `429 vcap_rate_limited`) and counted in Reports → Tills and staff. Daily limits per staff member and till apply (`daily_limit`). There is no offline mode on purpose: without a connection nothing is booked, so a code cannot be redeemed twice at two tills.

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `vcap_invalid_request` | 400 | Missing/malformed key, target or amount |
| `vcap_not_allowed` | 401/403 | Not logged in, voucher restricted to another email, refused by `vcap_redemption_allowed`, or redemption of another user |
| `vcap_not_found` | 404 | Unknown code, or a PIN card without its correct PIN (counts towards the invalid-code limit) |
| `vcap_reservation_missing` | 404/409 | Unknown key, or reservation expired/released |
| `vcap_already_redeemed` | 409 | Voucher or redemption already completed |
| `vcap_reserved` | 409 | Voucher is reserved by another redemption right now |
| `vcap_insufficient_balance` | 409 | Gift card balance too low |
| `vcap_not_active` | 409 | Voucher scheduled for later delivery |
| `vcap_currency_mismatch` | 409 | Voucher kept in another currency than the shop base currency or the `currency` given |
| `vcap_target_unavailable` | 409 | Refused by `vcap_redemption_target_available` |
| `vcap_idempotency_conflict` | 409 | Key reused for a different request |
| `vcap_redemption_released` | 409 | `reserve()` repeated for a key whose reservation was released or expired – redeem again with a new key |
| `vcap_expired` / `vcap_cancelled` | 410 | Voucher expired / voided |
| `vcap_not_redeemable` | 422 | Tickets and shop-only (reusable) vouchers |
| `vcap_rate_limited` | 429 | Too many invalid codes (setting, default 10 per user and 15 minutes; while many invalid codes arrive site-wide – filter `vcap_redemption_site_wide_failures`, default 300 – every caller without vouchers of their own gets a fifth) |
| `vcap_temporary_failure` | 503 | Database/lock problem – retry with the same key |

### Rules for integrations

```php
// Only course vouchers may unlock courses.
add_filter( 'vcap_redemption_allowed', function ( $allowed, VCAP\Voucher $voucher, string $target, int $user_id ) {
    if ( str_starts_with( $target, 'learnboating:' ) && ! has_term( 'kurse', 'product_cat', $voucher->product_id ) ) {
        return new WP_Error( 'vcap_not_allowed', 'Dieser Gutschein gilt nicht für Kurse.', array( 'status' => 403 ) );
    }
    return $allowed;
}, 10, 4 );

// Refuse targets that no longer exist.
add_filter( 'vcap_redemption_target_available', fn( $available, string $target ) => $available && my_course_exists( $target ), 10, 2 );

// React once per completed redemption (runs after the COMMIT, see below).
add_action( 'vcap_voucher_redeemed', function ( array $redemption ) { /* audit, notify … */ } );
```

**Timing (D6).** Actions that report a change (`vcap_voucher_redeemed`, `vcap_redemption_reserved` / `_released` / `_refunded`, `vcap_voucher_updated`, `vcap_voucher_event`, `vcap_store_credit_changed` …) fire only after the database transaction that made the change was committed, exactly once, and never for a change that was rolled back; outside a transaction they fire immediately. The filters `vcap_redemption_allowed` and `vcap_redemption_target_available` decide and therefore run synchronously: `reserve()` evaluates them before it locks the voucher row (status, balance and owner are re-checked under the lock), but they can still run inside a caller's transaction – keep them fast, without remote calls or own transactions. Integrations that call the API inside their own transaction use `VCAP\Db::transaction( callable $work, string $context )` (or `Db::begin()` / `Db::commit()` / `Db::rollback_if_error()`): the call nests as a savepoint and its actions wait for the outermost COMMIT. Details: CONNECTORS.md → *Event timing*.

## Issuing vouchers without an order (schema 1.8.0)

`vcap()->issuer->issue_standalone( array $args )` issues one voucher without a WooCommerce order – for company orders with a list of recipients (one call per recipient), goodwill vouchers or prizes. It uses the same code generation, WooCommerce coupon, `issued` ledger entry and email delivery as a purchased voucher and returns the `VCAP\Voucher` or a `WP_Error`.

| Argument | Meaning |
|---|---|
| `product_id` | Voucher product whose settings are used (type, usage, template, validity, restrictions, conditions). Optional. |
| `amount` | Value (percent for percentage vouchers). Default: the product's fixed value or price; required without a product. |
| `type`, `usage`, `discount_type`, `template_id` | Override the product's settings (`voucher`/`giftcard`/`ticket`; `single`/`balance`/`unlimited`; `fixed_cart`/`percent`). |
| `expires_at` / `expiry_days` | Expiry as UTC `Y-m-d H:i:s` (`''`/`null` = never) or a number of days; default: the product's rule, else the global default. |
| `recipient_name`, `recipient_email`, `message` | The voucher is mailed to the recipient when an address is given. |
| `buyer_name`, `buyer_email`, `buyer_copy` | The ordering party (e.g. the company): gets the voucher without a recipient address, or a copy with `buyer_copy => true`. |
| `send` | Deliver by email now (default `true`). `false`: the voucher counts as handed over, no email (the admin can still send it). |
| `delivery_at` | Deliver and activate later (UTC). |
| `code` | Own, unused code; default a generated one. It must be as hard to guess as a generated code (at least `vcap_min_code_bits`, default 50 bits, rated like an imported code with `CodeGenerator::analyse_batch()`), otherwise the call answers `vcap_weak_code`. |
| `allow_weak` | `true`: accept an own code below that strength knowingly (e.g. a printed card series). Default `false`. |
| `source`, `reference` | Free label of the caller and an idempotency key (up to 100 characters, e.g. `order-4711/3`), both shown in the history. A second call with the same `reference` issues nothing and answers `vcap_reference_used` with `voucher_id` in the error data (0 while the first call is still running). |
| `reason` | Why it was issued (e.g. "goodwill"), shown in the history with source and reference. |
| `balance` | Remaining value below `amount` (stored balance only), e.g. an existing card taken over with what is left of it. Default: `amount`. |
| `paid` | Whether the voucher was sold, i.e. paid outside WooCommerce (e.g. a company order on invoice). Default `false`: a voucher without a sale (goodwill, prize) is a price reduction for VAT (treatment `discount`) and is listed as free of charge in the liability report (TAX.md 2.8, M12-42). Vouchers → New voucher and `wp vcap issue --amount=…` pass `false`, the card import passes `true`. The core passes it on to `vcap_voucher_issued_standalone`; the tax handling of sold/unsold vouchers reads it. |

The admin screens Vouchers → New voucher and Vouchers → Import cards and the commands `wp vcap issue --amount=<amount> [--email=<email>] [--product=<id>] [--send]` and `wp vcap import <file> [--dry-run]` are built on this method (sources `admin`, `cli`, `import`; imported cards get the history entry `imported`).

Errors: `vcap_invalid_product`, `vcap_invalid_args`, `vcap_invalid_amount`, `vcap_no_address`, `vcap_code_taken`, `vcap_weak_code`, `vcap_reference_used`, `vcap_issue_failed` (the error data carries an HTTP-like `status`). Afterwards the action `vcap_voucher_issued_standalone` (`Voucher`, `$args`) fires (not `vcap_vouchers_issued`, whose listeners expect an order).

**Permission and context are the caller's responsibility**: the method checks no capability, so background tasks can use it. Call it only from code that has checked who asked for it – in an admin form `current_user_can( 'vcap_manage_vouchers' )` and a nonce, in a REST route a `permission_callback`. Amounts are in the shop's base currency.

```php
foreach ( $recipients as $i => $r ) {
	$voucher = vcap()->issuer->issue_standalone( array(
		'product_id'      => 123,
		'recipient_name'  => $r['name'],
		'recipient_email' => $r['email'],
		'buyer_name'      => 'ACME GmbH',
		'buyer_email'     => 'hr@acme.example',
		'source'          => 'company-order',
		'reference'       => 'company-order-' . $company_order_id . '/' . $i,
	) );
	if ( is_wp_error( $voucher ) && 'vcap_reference_used' !== $voucher->get_error_code() ) {
		// Log and retry later: the same reference never issues twice.
	}
}
```

## Workflow plugins (since 1.2.0)

`vcap()->workflow` implements the workflow provider contract v1 for plugins such as Workflow Captain: voucher events after the COMMIT (`vcap_workflow_event`, an envelope with stable event id and no code or personal data), services with capability check, idempotency key and audit entry (`issue`, `resend`, `void`, `reactivate`, `redeem`, `contact`), replay of missed events, and the optional hand-over of single customer emails (expiry reminder, gift notices; off by default). Contract, envelope, payload allowlist, errors, idempotency and deprecation rules: [WORKFLOW.md](WORKFLOW.md).

## Returned voucher emails (bounces, since 1.3.0)

"Delivered" means that `wp_mail()` accepted the email. Voucher emails carry the headers `X-VCAP-Voucher` (the voucher
id, comma separated for a bundled email) and `X-VCAP-Role` (`recipient` or `buyer`). A bounce handler (webhook of the
mail service, queue SMTP plugin) reports a returned email with:

```php
vcap()->delivery->mark_bounced( $voucher_id, 'recipient', '550 mailbox unavailable', true );
```

The role no longer counts as delivered, the voucher history gets a "Delivery failed" entry, the order a note; with the
fourth argument the buyer is told by email (with the signed "Correct the recipient" link where the gift may still be
redirected). The action `vcap_voucher_bounced( Voucher $voucher, string $role, string $reason )` fires afterwards.

## Extension points for connector plugins (since 1.1.0)

### Voucher types

Add your own voucher type (e.g. a course voucher) with the `vcap_voucher_types` filter. It appears in the product's "Type" select and behaves like a regular voucher (coupon, PDF, email). Built-in types (`voucher`, `giftcard`, `ticket`, `credit`) cannot be replaced. Use the type in `vcap_redemption_allowed` to decide what a voucher may unlock.

```php
add_filter( 'vcap_voucher_types', fn( array $types ) => $types + array( 'course' => __( 'Course voucher', 'my-plugin' ) ) );
```

### Redemption channels

Each voucher product chooses where its vouchers can be redeemed ("Redeemable via"): `shop` (cart/checkout) and `external` (redemption API). The choice is stored with every sold order line, so later product changes do not alter sold vouchers. No selection allows every channel.

- `vcap_redemption_channels` (array channel ⇒ label): add your own channel, e.g. `learnboating`.
- `vcap_redemption_channel_for_target` (string channel, string target): map your redemption targets to your channel (default `external`).
- `vcap_redemption_channel_allowed` (bool, Voucher, channel, stored channels): final decision.
- `VCAP\Channels::allowed( $voucher, $channel )` checks a voucher. The shop refuses vouchers without the `shop` channel (error 9006); the redemption API answers `vcap_not_redeemable`.

### Per-line order and refund data

`vcap()->api_v1->order_lines( WC_Order $order )` returns, per product line (keyed by order item id): product and variation id, name, quantity, refunded quantity, subtotal/total and taxes, refunded total, refunded VAT (`refunded_tax`, all rates of the line) and order currency, the voucher line snapshot and the vouchers issued for the line (id, code, type, status, value, balance, currency, expiry). Filter: `vcap_order_lines`.

The voucher line snapshot (`VCAP\Issuer::line_data( $item )`, order item meta `_vcap_line`) is written once when the vouchers are issued: `face_value` and `voucher_currency` of each voucher, number of `vouchers`, `quantity`, `order_currency`, `subtotal`, `subtotal_tax`, `total`, `total_tax` (after discounts), `tax_class`, `tax_status`, `prices_include_tax`, `voucher_type`, `discount_type`, `amount_source`, `channels` and `recorded_at`.

### Refunds of completed redemptions

`vcap()->redemptions->refund( $key, $amount, $refund_key )` gives back (part of) a committed redemption, e.g. when the service it paid for is refunded. Gift cards get the amount back on their balance; a single-use voucher becomes usable again once the refunds reach the redeemed amount (partial refunds are recorded only). Idempotent per `$refund_key`; refunds are capped at the redeemed amount (`vcap_refund_exceeded`), only committed redemptions can be refunded (`vcap_not_committed`). Ledger event `api_refunded`, action `vcap_redemption_refunded`.

## Captain Suite (since 1.2.0)

Two in-process PHP contracts for the separate plugins WP Review Captain and WP Form Captain. Both are versioned
(`CONTRACT`: review source `1.1.0`, voucher requests `1.0.0`; a consumer checks the major version), carry no personal data in events, are idempotent and
never touch the other plugin's tables. Voucher Captain stays the only owner of codes, values, issuing, redemption,
refunds and voucher personal data.

### Review source (WP Review Captain), contract v1

`$source = apply_filters( 'vcap_review_source_v1', null )` returns `VCAP\Integrations\ReviewSource` (also
`vcap()->review_source`). Voucher Captain only reports that a redemption **confirmed a service**; invitations,
consent, frequency limits, sending, opt-out links and reviews belong to the review plugin.

Which redemptions count (all conditions):

| Condition | Default |
|---|---|
| Product setting *Redemption confirms a service (for review invitations)* (`_vcap_review_service`) | off; `vcap_review_service_voucher` ( `bool`, `Voucher` ) for vouchers without a product. Store credit and tickets never count. |
| Channel | `counter` (scanner, *Mark as redeemed (store)*: staff confirm on site). `external` (redemption API) only when added to `vcap_review_channels` **and** the target starts with a prefix of `vcap_review_external_target_prefixes` (e.g. `spa:`); the built-in `passcaptain:` targets never. `shop` never: the order is a purchase, which the review plugin judges by the order itself. |
| A review plugin listens | `vcap_review_redemption_eligible` has a listener (filter `vcap_review_source_active`). Without one nothing is recorded. |
| One service = one request | only the **first** confirmed redemption of a voucher becomes `eligible` – also for gift cards with a remaining value (`usage_mode` `balance`) redeemed in several visits. Further redemptions of the same voucher (counter or external) create no event and no reference record while that one is eligible. When the first one is revoked (`redeem_reversed`, `refunded`), the next confirmed redemption may become eligible again; redemptions made in between are not reported afterwards. Filter `vcap_review_first_redemption_only` ( `bool $only = true`, `Voucher` ): `false` counts every redemption on its own (owner decision 16.11.2026). |

Never a review source: purchases, issuing, delivery, top-ups, store credit, discount codes (campaign coupons are no
vouchers), reservations that were released.

Events (after the COMMIT, once per reference and event):

- `vcap_review_redemption_eligible( array $payload )` – after the confirmed redemption.
- `vcap_review_redemption_revoked( array $payload )` – reason `redeem_reversed` (counter cancellation), `refunded`
  (any refund of the external redemption), `voided` (voucher voided, e.g. its purchase refunded), `erased` (the
  privacy eraser removed the voucher's personal data), `deleted` (voucher deleted) or `no_recipient` (no address
  left, e.g. after the retention cleanup – noticed on the next `recipient()` call). Only a reference that was
  eligible is revoked; revoked is final. With `erased`, `deleted` and `no_recipient` the reference is also unlinked
  from the voucher, so no address can be resolved for it any more.

Payload (exactly these keys): `contract` (`1.1.0`; `1.0.0` before the `locale` addition), `event` (`eligible`/`revoked`), `event_id` (`{reference}:{event}`),
`reference`, `source` (`voucher-captain`), `site_id`, `channel` (`counter`/`external`), `experience_id` (voucher
product id), `occurred_at` (UTC), `reason`. No code, name, email, amount, till or receipt.

Reference: `vcr1_` + 32 hex characters, derived from the site and the redemption (`counter:{history entry id}` –
the `event_id` now also given in `vcap_voucher_redeemed` – or `external:{redemption key}`): the same redemption
always has the same reference, a repeated event creates no duplicate. Stored as a small option per reference (state,
voucher id, product, channel, times; removed on uninstall).

Service methods: `contract()`, `get( $reference )` (current payload or `null`, for reconciliation),
`recipient( $reference )` → `{reference, email, name, role, locale}` (`role` `recipient`, else `buyer`) or `WP_Error`.
`locale` (since contract 1.1.0, additive – consumers of 1.0.0 ignore it) is the language for the invitation, in this
order: the language of the voucher's order when known (WPML/Polylang, `vcap_order_language`, then the filter
`vcap_email_locale` as for the voucher emails), else the locale of the customer account with that address
(`get_user_locale()`), else the site language (`get_locale()`); filter `vcap_review_recipient_locale` ( `string`,
`Voucher`, `role` ). Errors:
`vcap_review_not_allowed` (403), `vcap_review_unknown_reference` (404), `vcap_review_revoked` (410),
`vcap_review_no_recipient` (404), `vcap_review_opted_out` (409). Allowed for voucher managers, shop managers and
background runs without a logged-in user (WP-Cron, WP-CLI); narrow or widen with `vcap_review_recipient_allowed`
( `bool`, `user_id` ). `vcap_review_opted_out` ( `false`, `email`, `reference`, `Voucher` ) lets the review plugin's
opt-out list (or any other) block an address; `vcap_review_recipient_resolved` ( `reference`, `user_id` ) is for audit
logs. The voucher's recipient may never have bought anything: whether an invitation is allowed (consent, existing
customer exception) is the review plugin's decision.

```php
add_action( 'vcap_review_redemption_eligible', function ( array $p ) {
	if ( '1' !== strtok( $p['contract'], '.' ) ) {
		return;
	}
	my_review_queue_add( $p['event_id'], $p['reference'], $p['experience_id'] ); // Dedupe by event_id.
} );
add_action( 'vcap_review_redemption_revoked', fn( array $p ) => my_review_queue_cancel( $p['reference'] ) );
// Later, in the sending job (WP-Cron):
$to = apply_filters( 'vcap_review_source_v1', null )?->recipient( $reference );
```

### Voucher requests from forms (WP Form Captain), contract v1

`$requests = apply_filters( 'vcap_voucher_request_service_v1', null )` returns `VCAP\Integrations\VoucherRequests`
(also `vcap()->voucher_requests`). A form plugin hands over what the visitor entered; Voucher Captain stores a
**request** – nothing else: no voucher, coupon, email, payment or offer. A manager who may issue vouchers without an
order opens it under *Vouchers → Voucher requests* and either issues the vouchers (choosing product, value, *Sold* or
*Free of charge* – there is no default – and whether to email them) or rejects it (the request is deleted).

`submit( array $request )` → `{request_id, reference, state, replayed}` or `WP_Error`. Keys: `reference` (required,
8–100 characters `A–Z a–z 0–9 . _ : / -`, e.g. `formcaptain:entry:123`; a repeated call answers the same request with
`replayed: true`), `kind` (`company_order`, `personalisation`), `product_id` (a voucher product), `amount` (per
voucher), `quantity` (without recipients), `recipients` (list of `name`, `email`, `message`), `contact` (`company`,
`name`, `email` – required, `phone`), `message`, `note`, `contract` (optional, major 1). At most 100 vouchers. Every
other key (`paid`, `code`, `send`, a status …) is dropped. While 500 requests are open (filter
`vcap_voucher_requests_max_open`; 0 accepts none) a new reference is refused with `vcap_request_limit` (429); a
repeated reference is still answered. `submit()` checks no capability – it is called for
visitors after the form plugin's own nonce and spam checks; the form plugin's entries are never read.

`confirm( $request_id, [ 'sale' => 'sold'|'free', 'send' => bool, 'product_id', 'amount', 'type', 'reason' ] )`
needs the rights of *Vouchers → New voucher* and issues one voucher per recipient (else `quantity` to the contact)
through `issue_standalone()` with the references `vcap-request:{id}/{n}` (source `request`; buyer = company or
contact): repeating it issues only what is missing. States `open`, `partial` (some failed – correct and confirm
again), `issued` (the recipient list is then removed from the request; the data lives in the vouchers). `reject()`
deletes an open request. Errors: `vcap_request_contract` (409), `vcap_invalid_request`, `vcap_invalid_email`,
`vcap_invalid_product`, `vcap_invalid_amount` (400), `vcap_not_allowed` (403), `vcap_invalid_args`, `vcap_not_found`,
`vcap_temporary_failure` (503), `vcap_request_limit` (429). Privacy: requests are part of WordPress's personal data
export and erasure (Tools → Export/Erase Personal Data, exporter/eraser `voucher-captain-requests`, 100 requests per
page). A person gets only their own part: as contact the contact data, message and note; as recipient their name,
address and message. Erasure blanks exactly these fields (company, value and count stay). The suggested privacy
policy text names the requests. Actions (no personal data): `vcap_voucher_request_received` ( `id`, `kind`,
`vouchers` ), `vcap_voucher_request_confirmed` ( `id`, `state`, `voucher_ids`, `user_id` ),
`vcap_voucher_request_rejected` ( `id`, `user_id` ), `vcap_voucher_request_handed_over` ( `id`, `order_id`,
`user_id` ), `vcap_voucher_request_expired` ( `id` ).

Retention (owner decision 16.11.2026): the daily maintenance (step `voucher_requests`, batches of 50 within the step's
time budget) deletes requests in the states `open` and `partial` 6 months after their receipt (a `partial` one after
the last confirmation – it was never completed and still holds recipient data, so it is treated like an open one) and
requests in the states `issued` and `handed_over` 24 months after the issuing or the hand-over. Rejected requests are
deleted at once by `reject()`. A deleted request takes its form-reference claim with it (the same form entry could be
submitted again afterwards). Filter `vcap_voucher_requests_retention_months` ( `array{open:int, closed:int}` ,
default `[ 'open' => 6, 'closed' => 24 ]`; `0` keeps that group). The suggested privacy policy text names both periods.

#### Hand-over to a company order (add-on)

Owner decision 16.11.2026: both features stay – the core keeps *Voucher requests*; an add-on (e.g. Voucher Captain
Pro, company orders) may take a request over. Without such an add-on nothing changes.

- Availability: `apply_filters( 'vcap_voucher_request_handover_available', false, int $request_id )` → `bool`. The
  request view shows *Take over as company order* only when it is `true`, the request is `open` (nothing issued yet),
  has at least 2 recipients and the user has `vcap_manage_vouchers`.
- Hand-over (admin-post `vcap_request_handover`, nonce per request, right `vcap_manage_vouchers`, or
  `$requests->hand_over( $request_id )`): `$result = apply_filters( 'vcap_voucher_request_handover', null, array $handover )`
  with exactly these keys: `contract` (`'1.0.0'`), `request_id` (int), `reference` (`vcap-request:{request_id}`,
  stable – the add-on de-duplicates by it: the same reference again answers the same order), `company`
  (`name`, `contact_name`, `contact_email`, `contact_phone`), `currency` (ISO 4217), `recipients` (list of `name`,
  `email`, `amount` (float, the request's value per voucher, 0 when none), `message`), `note` (message and note of the
  request, `''` when none).
- Expected answer: `array{ url: string, order_id: int }` (admin URL of the company order's preview) or `WP_Error`.
  `null` (nobody answered) → `vcap_handover_unavailable` *No extension takes over this request.*; an answer without
  order id or URL → `vcap_handover_invalid`.
- Success: state `handed_over` (`VoucherRequests::STATE_HANDED_OVER`), meta `_vcap_request_handover` (`order_id`,
  `url`, `at` UTC, `user_id`), the recipients are removed as after issuing (only the count stays – they belong to the
  company order now), redirect to `url`; the request view shows *Handed over to company order #{order_id}*. A second
  hand-over (double click) answers the stored order without asking the add-on again. On a `WP_Error` the request
  view shows the message and the request stays `open` with all its data.
- A handed-over request cannot be issued (`confirm()` → `vcap_handed_over`, 409) or rejected. Privacy export and
  erasure treat `handed_over` like `issued`.

## Extension points for add-ons (public API since 1.2.0)

Voucher Captain is free and complete; paid features live in a separate add-on plugin (docs/FREE-PREMIUM.md, planned:
`voucher-captain-pro`). An add-on uses only the points below, the PHP API above and the REST namespace `vcap/v1`. They
are public API under the versioning rules of CONNECTORS.md → *API versioning policy*: within 1.x only additive changes
(new hooks, extra trailing arguments, new array keys); a hook is never renamed, removed or given a different meaning
without a deprecation period. `tests/run.php` ("A4: add-on extension points") checks that every hook of this table is
called in the code and listed in docs/HOOKS.md, the generated reference with each call site.

| Hook / function | Type | Arguments | Since | Use |
|---|---|---|---|---|
| `vcap_loaded` | action | `VCAP\Plugin` | 1.2.0 | Start of the add-on: the services (`vcap()->api_v1`, `->redemptions`, `->issuer` …) exist. |
| `vcap_admin_menu` | action | `string $parent_slug` (`vcap-vouchers`) | 1.2.0 | Own pages under *Vouchers*: `add_submenu_page( $parent_slug, … )` (shown before *Settings*). |
| `vcap_settings_sections` | filter | `array $sections` (id ⇒ label) | 1.2.0 | Own section of WooCommerce → Settings → Vouchers; core sections cannot be renamed or removed. |
| `vcap_settings_fields` | filter | `array $fields`, `string $section` | 1.0.0 | Fields of a section (WooCommerce settings API, saved by WooCommerce). An add-on section starts empty. |
| `vcap_product_settings_fields` / `vcap_product_settings_save` | action | – / `WC_Product`, `array $post` | 1.1.0 | Own fields in the product's *Voucher* tab and saving them. |
| `vcap_template_design` | filter | `array $design`, `int $template_id` | 1.0.0 | The design a PDF template prints with. |
| `vcap_sanitize_design` | filter | `array $clean`, `array $posted` | 1.0.0 | Keep own element keys when a design is saved. |
| `vcap_render_template_id` | filter | `int $template_id`, `Voucher` | 1.0.0 | Print a voucher with another template. |
| `vcap_placeholder_definitions` / `vcap_placeholders` | filter | `array $defs` / `array $values`, `Voucher` | 1.0.0 | Own placeholders in PDFs and email texts. |
| `vcap_report_tabs` | filter | `array $tabs` (view id ⇒ label) | 1.2.0 | Own tab under *Vouchers → Reports*; core views cannot be replaced. |
| `vcap_report_page` | action | `string $view` | 1.2.0 | Print the page of the own report tab (the capability is checked before). |
| `vcap_report_group` | filter | `array $group`, `string $code`, `int $coupon_id` | 1.1.0 | Group of a code in the campaign report. |
| `vcap_voucher_types`, `vcap_redemption_channels`, `vcap_redemption_allowed` | filter | see *Extension points for connector plugins* | 1.1.0 | Own voucher types, channels and rules. |
| `vcap_redemption_providers` / `VCAP\Contracts\RedemptionProvider` | filter / interface | see *Provider extension* | 1.1.0 | Own redemption backend. |
| `vcap_voucher_admin_actions` / `vcap_voucher_admin_action` | action / filter | see docs/HOOKS.md | 1.1.0 | Own buttons on the voucher page. |
| `vcap_voucher_links` | filter | `array $links`, `Voucher`, `string $context` (`email`, `email_plain`, `account` – also the order received page and *My Account → View order* –, `placeholder`) | 1.2.0 | Links such as "Add to Wallet" in the voucher email, the voucher lists and the PDF placeholder `{link:<id>}`. |
| `vcap_voucher_next_steps` | filter | `string[] $steps`, `Voucher` | 1.1.0 | Steps in the voucher email after receiving it. |
| `vcap()->issuer->issue_standalone( $args )` | method | see *Issuing vouchers without an order* | 1.2.0 | Vouchers without an order (company orders, goodwill); the caller checks the capability. Then `vcap_voucher_issued_standalone` (`Voucher`, `$args`). |
| `vcap_vouchers_issued` | action | `Voucher[]`, `WC_Order` | 1.0.0 | Vouchers of an order were issued. |
| `vcap_voucher_redeemed` | action | see *External redemptions* | 1.1.0 | An external redemption was completed (after COMMIT). |
| `vcap_voucher_event` | action | `$voucher_id`, `$event`, `$amount`, `$balance_after`, `$note`, `$order_id`, `$source`, `$user_id` | 1.0.0; `$source`/`$user_id` 1.2.0 | Every history entry (after COMMIT). Register with `add_action( 'vcap_voucher_event', $cb, 10, 8 )` for the last two; `$source` is `VCAP\Source::current()` (admin, cron, rest, scanner, checkout, cli, web), `$user_id` 0 for none. |

The core makes no request to a license or update server and needs no add-on (tests/license-guard.php, readme.txt →
External services). An add-on never locks or moves a core feature (docs/FREE-PREMIUM.md).

## WP Pass Captain

When WP Pass Captain is active, Voucher Captain registers itself as its voucher provider (`wp_pass_captain_voucher_provider_v1`, contract version 1): vouchers and gift cards can pay for tickets in Pass Captain's own checkout. Quote, reserve, commit, release and refund map to the redemption API (target `passcaptain:{order reference}`, channel `passcaptain` – tick it under *Redeemable via*, or leave all channels unticked). Amounts are converted between Pass Captain's minor units and the voucher currency; vouchers in another currency are refused. Ticket refunds give the voucher share back proportionally. Checked in CI with both plugins installed (`tests/passcaptain-integration.php`).

### Ticket vouchers (voucher → Pass Captain tickets)

A voucher product can name a Pass Captain ticket type – a fixed date – or a whole event, where the customer chooses one of its upcoming dates (*WP Pass Captain ticket*: "Fixed date: …" or "Customer chooses the date: …"; optional *Ticket name (date choice)* to offer only ticket types with that name; *Tickets per voucher* in the product's voucher settings; shown while Pass Captain ≥ the version with `wp_pass_captain_ticket_service_v1` is active). Each sold voucher stores that target with its order line (`_vcap_line` → `passcaptain_ticket`) and is exchanged for the tickets:

- **Customer:** entering the code on the redeem page (`[vcap_redeem]`) exchanges it instead of applying it to the cart. Tickets go to the logged-in customer, else to the voucher's recipient (or buyer). For an event voucher the form asks for the date first (select of upcoming dates).
- **Admin:** *Exchange for tickets* on the voucher page (with a date select for event vouchers).
- **Code:** `vcap()->passcaptain_tickets->exchange( $code, [ 'email' => …, 'name' => …, 'attendees' => […] ] )` returns `voucher_id`, `order_public_id`, `tickets`, `email`, `status` or a `WP_Error`. Event vouchers need `ticket_type_id` (one of `VCAP\Compat\PassCaptainTickets::options( $voucher )`); without it the error `vcap_ticket_choice_required` carries the choosable dates in its data (`options`). Only dates with enough free places for all tickets of the voucher are offered. If the ticket type has required attendee fields, pass `attendees` (one entry per ticket: `[ 'answers' => [ field key => value ] ]`); without them the error `vcap_ticket_attendees_required` carries `fields` and `quantity`. The redeem page asks for date and attendee details step by step (`VCAP\Compat\PassCaptainTickets::fields( $ticket_type_id )`).

Flow: reserve the voucher (key `passcaptain:ticket:{voucher id}`, target `passcaptain:ticket:{ticket type}`, channel `passcaptain`) → Pass Captain `issue_tickets()` (idempotency key `vcap-voucher-{voucher id}`, free order "paid by voucher", normal capacity, attendee fields and ticket mail) → commit. Repeating the exchange answers with the same tickets. A sold-out or removed ticket type releases the voucher (it stays usable); a temporary Pass Captain failure keeps the reservation (24 h, filter `vcap_passcaptain_ticket_reservation_minutes`) so the exchange can be repeated. Single-use vouchers only – gift cards with a balance pay in the ticket checkout instead. When the voucher is voided later (purchase refunded), its tickets are revoked in Pass Captain; checked-in tickets stay valid (filter `vcap_passcaptain_revoke_on_void`). Saving a ticket product with channels ticked adds `passcaptain` automatically. Fixed-date ticket vouchers (a ticket type, not "customer chooses the date") are sold only while that date has room for all their tickets in the cart (`vcap_passcaptain_check_fixed_date_capacity`), and expire when the event starts (`vcap_voucher_expires_at`; the expiry reminder then reminds before the event). Places are taken only at the exchange – Pass Captain may sell them in the meantime. A ticket voucher is only exchanged for its tickets: sold without a channel selection it is redeemable via `passcaptain` only (not in the cart, at the counter or as payment in the Pass Captain checkout); ticking "Shop" or the filter `vcap_passcaptain_ticket_voucher_as_value` also allows it as a value voucher.

| Hook | Purpose |
|---|---|
| `vcap_passcaptain_ticket_target` ( `?array $target`, `Voucher $voucher` ) | Ticket target (`ticket_type_id` or `event_id` + optional `ticket_name`, `quantity`) for vouchers without a product line, e.g. imported codes |
| `vcap_passcaptain_tickets_issued` ( `Voucher`, `array $result`, `string $email` ) | After a successful exchange |
| `vcap_passcaptain_ticket_voucher_as_value` ( `bool $allowed`, `Voucher $voucher` ) | Allow a ticket voucher to pay as a value voucher too (default: only when "Shop" is ticked on the product) |
| `vcap_shop_channel_refused_message` ( `string $message`, `Voucher $voucher` ) | Cart message for a voucher that is not redeemable in the shop |
| `vcap_passcaptain_ticket_reservation_minutes`, `vcap_passcaptain_revoke_on_void` | Reservation lifetime during the exchange; revoke tickets when the voucher is voided |

