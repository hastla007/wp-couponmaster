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

Register a provider only when its external system is available. This hook defines the integration seam; it does not itself install or activate a Pass Captain connector. A real cross-plugin adapter still needs a shared contract version and a two-plugin integration test.

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

Refund (`/refund`): gives back (part of) a committed redemption. Allowed for voucher managers and "Voucher POS" accounts (`vcap_redeem_api`, own redemptions only); a customer account gets `vcap_refund_not_allowed` (403) – the service may already have been provided (SEC4c, owner decision 16.11.2026; a customer cancels with `release` before the commit). A connector that lets its customers cancel after the commit allows it with the filter `vcap_customer_may_refund_redemption` ( `false`, redemption data or `null`, user id ); the access rules of `commit` still apply. Server code uses `vcap()->redemptions->refund()`; idempotent per `refund_key` (8–64 characters like `key`). Errors: `vcap_not_committed` (409), `vcap_refund_exceeded` (409), see *Refunds of completed redemptions*.

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

   Rules: a personal voucher needs `id_checked=true` (staff checked the ID against `personal.names`; else `409 vcap_id_check_required`); with the setting "Receipt no. required" a redemption without `receipt` is refused (`400 vcap_receipt_required`; default "automatic": required for shops based in a country whose country data sets `fiscal_receipt` – Germany and Austria; filter `vcap_counter_require_receipt` wins); with a managed list of tills (setting "Tills / branches") `till` must be one of them (`400 vcap_till_unknown`), and a staff member fixed to a till on their profile always books there. A repeated request with the same `idempotency_key` gets the stored answer with `replayed: true` – nothing is booked again. Unknown codes are limited per staff member (100 per 15 minutes, filter `vcap_rate_limit_scanner_lookup`; `429 vcap_rate_limited`) and counted in Reports → Tills and staff. Daily limits per staff member and till apply (`daily_limit`). There is no offline mode on purpose: without a connection nothing is booked, so a code cannot be redeemed twice at two tills.

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
| `vcap_rate_limited` | 429 | Too many invalid codes (setting, default 10 per user and 15 minutes; while many invalid codes arrive site-wide – setting "Invalid code attempts of all callers" `vcap_redemption_site_wide_failures`, default 300, at least 50, then the filter of the same name – every caller without vouchers of their own gets a fifth) |
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

**Journal id of credit and top-up bookings (since contract 1.7.0, VC-12).** `vcap_store_credit_changed` passes as
7th argument and `vcap_gift_card_topped_up` as 5th argument a context array `array( 'journal_id' => int )`: the
ledger (history) id of the booking. Two bookings of the same customer in the same second therefore stay apart, e.g.
in a workflow event store (suite spec R-7.9a). Register the action with 7 or 5 accepted arguments to get it; existing
listeners with fewer arguments keep working. `0` when no ledger row was written in this request.

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

## Read functions (since 1.3.0, contract 1.2.0)

Read-only state of a voucher by its **record id**, for add-ons and automations that must re-check an event or evaluate
a condition without knowing the voucher code (an event carries the `voucher_id`, never the code). Available once the
plugin booted (`vcap_loaded`); check `function_exists( 'vcap_read_voucher' )` or
`version_compare( VCAP_CONTRACT_VERSION, '1.2.0', '>=' )`. The functions are thin wrappers of the service
`vcap()->read` (`VCAP\API\V1\ReadService`, methods `voucher()`, `gift_card()`, `store_credit()`, `redemption()`,
`redemptions()`, `order_vouchers()`).

| Function | Argument | Returns | Since |
|---|---|---|---|
| `vcap_read_voucher( int\|string $id )` | voucher id | voucher keys below, or `null` | 1.3.0 |
| `vcap_read_gift_card( int\|string $id )` | voucher id of a gift card | voucher keys plus `top_up_count`, `top_up_reversed_count`; `null` when the id is unknown or no gift card (type `giftcard`) | 1.3.0 |
| `vcap_read_store_credit( int\|string $user_id )` | WordPress user id | `user_id`, `account_id` (voucher id of the account), `status`, `currency`, `balance_minor`, `created_at`, `updated_at`; `null` when the user has no account (none is opened) | 1.3.0 |
| `vcap_read_redemption( int\|string $id )` | record id of an external redemption | redemption keys below, or `null` | 1.3.0 |
| `vcap_read_redemptions( int\|string $voucher_id )` | voucher id | list of redemptions (redemption keys), newest first, at most 100; `[]` when it has none; `null` when the voucher id is unknown | 1.3.0 |
| `vcap_read_order_vouchers( int\|string $order_id )` | WooCommerce order id | `order_id`, `voucher_count`, `voucher_ids` (int[]); `null` when no voucher belongs to the order | 1.3.0 |

**Voucher keys** (always all of them, in this order): `voucher_id`, `type` (`voucher`, `giftcard`, `ticket`, `credit` or
a connector type), `status` (`active`, `scheduled`, `reserved`, `redeemed`, `expired`, `void`), `usage_mode`
(`single`, `balance`, …), `currency`, `amount_minor` (face value), `balance_minor` (remaining value as stored),
`order_id`, `product_id` (variation if any), `user_id` (owner of a store credit account) – each `null` when not set –,
`transferable` (bool), `expires_at`, `created_at`, `updated_at`, `send_to` (`buyer`, `recipient`, `both`, `other`),
`delivery_state` (`delivered`, `scheduled`, `pending`, `given_up`), `delivery_at` (planned), `delivered_at`,
`delivered_roles` (subset of `recipient`, `buyer`), `delivery_failures` (failed attempts of the current delivery),
`checkins`, `max_checkins`, `last_checkin_at` (tickets), `void_reason` (`refund` or `manual` for a void voucher, from
`Issuer::voided_after_refund()`, never from the note; else `null`), `redemption_counts` (external redemptions per
state: `reserved`, `committed`, `released`).

**Redemption keys:** `redemption_id`, `voucher_id`, `state` (`reserved`, `committed`, `released`), `channel`
(`external`), `amount_minor`, `currency` (of the voucher), `user_id` (`null` for none), `expires_at` (only while
`reserved`), `created_at`, `committed_at` (only when `committed`). The row is read as stored: an overdue reservation is
reported as `reserved` with its past `expires_at` (unlike `vcap()->redemptions->get()`, nothing is released here).

**Format.** Amounts are integers in the smallest unit of the currency (`*_minor` with `currency`, ISO 4217 minor units
from `config/currencies.php`: `12.50 EUR` → `1250`, `500 JPY` → `500`). Timestamps are RFC 3339 in UTC
(`2026-11-16T09:30:00Z`) or `null`. A string id must be a positive whole number; anything else (a code, a token) is
treated as unknown and answers `null`.

**Never returned:** the voucher code or any part, hash or mask of it, the token and download links, buyer and
recipient names and email addresses, the message, personalisation fields, conditions text, notes and reasons (free
text), the redemption key (`redemption_key`), the redemption `target`, `meta`, tills and receipts. The queries name
their columns, so these values are not even read. New keys may be added within contract 1.x (CONNECTORS.md → 6.);
existing keys keep their meaning.

**No side effects.** The functions write nothing (no released reservation, no repaired coupon, no opened store credit
account, no cache entry, no hook) and do not check the current user: the caller decides who may see the result.
Contact data and consent of a recipient are not part of them (open question F-13 of the suite specification, TODO.md
→ VOUCH-WF-15).

```php
add_action( 'vcap_voucher_event', function ( $voucher_id, $event ) {
    if ( 'voided' !== $event || ! function_exists( 'vcap_read_voucher' ) ) {
        return;
    }
    $state = vcap_read_voucher( $voucher_id );
    if ( $state && 'void' === $state['status'] && 'refund' === $state['void_reason'] ) {
        // e.g. start a follow-up: $state['balance_minor'], $state['currency'], $state['order_id'] …
    }
}, 10, 2 );
```

## Voucher commands (since 1.3.0, contract 1.3.0)

The four commands an automatic caller (a workflow automation, an add-on) may run on vouchers (suite specification 8.3,
decisions D11/D11b, requirement VC-1): issue, void, reactivate and redeem. Limits, protocol, idempotency, permission
and abuse protection are enforced **here, in the core**, for every caller; no licence is checked. Available once the
plugin booted (`vcap_loaded`); check `function_exists( 'vcap_command_issue' )` or
`version_compare( VCAP_CONTRACT_VERSION, '1.3.0', '>=' )`. The functions are thin wrappers of the service
`vcap()->commands` (`VCAP\API\V1\CommandService`, methods `issue()`, `void()`, `reactivate()`, `redeem()`,
`run( $command, $args )`, `protocol( $day )` and `limits( $currency )`).

| Function | Command | Fields besides the common ones | Capability of `authorized_by_user_id` | Since |
|---|---|---|---|---|
| `vcap_command_issue( array $args )` | `voucher.issue` | `recipient_user_id`, `amount_minor`, `currency`, optional `product_id` | `vcap_manage_vouchers` | 1.3.0 |
| `vcap_command_void( array $args )` | `voucher.void` | `voucher_id` | `vcap_manage_vouchers` | 1.3.0 |
| `vcap_command_reactivate( array $args )` | `voucher.reactivate` | `voucher_id` | `vcap_increase_balance` | 1.3.0 |
| `vcap_command_redeem( array $args )` | `voucher.redeem` | `voucher_id`, `amount_minor`, `currency`, `target` | `vcap_redeem_api` or `vcap_manage_vouchers` | 1.3.0 |

**Common fields** (all required, R-8.3): `idempotency_key` (1–100 printable ASCII characters without spaces; built by
the caller from workflow, workflow version, event id and step, R-8.4), `reason` (fixed key of the caller, e.g.
`loyalty_reward`; letters, digits, `_ . : -`, at most 64 characters – no free text), `workflow_id`, `run_id`,
`step_id` (int or key of the same form), `authorized_by_user_id` (the WordPress user who published the workflow).

**Rules.**
- A voucher is named by its `voucher_id`, never by its code; a recipient by `recipient_user_id`, never by an address
  (the core reads the address from the account and sends its own voucher mail). Money is given in minor units
  (`amount_minor`, integer, `12.50 EUR` → `1250`) with `currency`. Any field not listed for a command – an own `code`,
  `allow_weak`, `paid`, `discount_type`/percentages, `refund`/`after_refund`, a note, `recipient_email` – is refused as
  `invalid_request` with `field` naming it (VC-2).
- `voucher.issue` issues a fixed value (no percentage product, no ticket, `paid` = false) in the shop currency.
  `voucher.void` voids without refund and books nothing back; `voucher.reactivate` never reactivates a voucher voided
  because of a refund; `voucher.redeem` reserves and commits in one call through the redemption API, never more than
  the remaining value (a single-use voucher only as a whole), any voucher (not only one issued by a workflow) except
  store credit, tickets and percentage vouchers. `refund()` of the redemption API is not reachable.
- The capability of `authorized_by_user_id` is checked with `user_can()` at every call; the current user is neither
  used nor changed (no `wp_set_current_user()`), and the checks of `void()`/`reactivate()`/the REST layer are not
  relied on (VC-9).
- **Off by default.** Each command has its own switch in *WooCommerce → Settings → Vouchers → Integrations (API)*
  ("Automatic voucher commands").
- **Fixed limits** (owner's decision of 16.11.2026 on F-05; default and ceiling at once; the operator can only lower
  them in the same settings, the filter `vcap_command_limits` can only lower them or switch a command off):

  | Command | Maximum amount | Calls per calendar day and site | Further limit |
  |---|---|---|---|
  | `voucher.issue` | 100 EUR per voucher | 50 | 1 per calendar day and recipient |
  | `voucher.void` | – | 50 | – |
  | `voucher.reactivate` | – | 50 | – |
  | `voucher.redeem` | 100 EUR per redemption | 50 | – |

  A call above a limit is refused, not shortened. The calendar day is the site's time zone; all workflows count
  together; void and reactivate have separate counters. Only executed calls (`done`) count. **Other currencies
  (F-05a, decided 23.11.2026):** the core converts nothing. When the shop currency is CHF, USD or GBP
  (`CommandLimits::SIMILAR_CURRENCIES`), the same number 100 applies in that currency (the operator may lower it). Any
  other currency is refused (`currency_not_allowed`) unless it is the shop currency and the operator entered an own
  *Maximum amount* there (worth no more than 100 EUR – not checked, there is no exchange rate in the core); until then
  `voucher.issue` and `voucher.redeem` are effectively off in that shop. The own maximum amount is stored with the
  currency it was entered in (`vcap_command_<issue|redeem>_max_amount_currency`, read-only on the settings page,
  exported and imported with the amount; PV-P3-4): after a change of the shop currency, or an import from a shop in
  another currency, the amount is not used and amounts are refused until a new maximum is saved on the settings page
  (a value set with `wp vcap settings set` keeps the recorded currency; set both options there). An amount stored
  without a currency counts once in the current shop currency, which is then recorded. A count setting must be a whole
  number (also `5.0` or `1e1`); anything else is refused on the settings page and the stored value stays (PV-P2-4).
- **Reading the limits** (since contract 1.6.0, SET-WC-15): `vcap_read_command_limits( string $currency = '' )`
  (wrapper of `vcap()->commands->limits()`) returns the limits as they apply now, e.g. for a display in a workflow
  editor: `day` (site time zone), `currency` (the given one, default the shop currency), `shop_currency`,
  `fixed_currency` (`EUR`), `similar_currency` (CHF/USD/GBP), and per command under `commands['voucher.issue']` …:
  `enabled`, `fixed` (the ceilings above), `effective` (`per_day`, `per_recipient`, `max_amount_minor` in minor units
  of `currency` or null, `amount_allowed` – false when amounts in this currency are refused), `used_today` and
  `remaining_today` (executed calls of the site today). No codes, no personal data, no per-recipient counters; null
  before the plugin booted. Check `version_compare( VCAP_CONTRACT_VERSION, '1.6.0', '>=' )`.
- **Atomic.** Checking, counting and executing run under one named database lock per site, so two parallel calls
  never pass a limit together (R-8.3b).
- **Idempotent.** A second call with the same `idempotency_key` does nothing and returns the stored reply of the first
  (with `replayed` = true), even when the command was switched off since. The same key with other values (`reason`
  or the command fields) is refused as `idempotency_conflict`. Replies are kept 90 days. `failed` replies are not
  stored: the same key may be retried.
- **Protocol** (R-8.3c). Every call – also refusals, conflicts, replays and lock failures – is written to the protocol
  of the day (`vcap()->commands->protocol( 'Y-m-d' )`, kept 90 days, at most 500 entries a day) with `at`, `command`,
  `status`, `code`, `voucher_id`, `amount_minor`, `currency`, `workflow_id`, `run_id`, `step_id`,
  `authorized_by_user_id`, `reason`, `replayed`, `key_hash` (16 hex characters of a SHA-256 of the key). A call on a
  known voucher (not a replay) also gets the history entry "Automatic command" with the same data; the core's own
  entries (`issued`, `voided`, `reactivated`, `api_reserved` …), hooks and mails follow as for any other caller.

**Reply** (always all keys, in this order): `status` (`done`, `duplicate` – the target state already held, nothing
done –, `refused`, `failed`), `code`, `command`, `voucher_id`, `voucher_status`, `amount_minor`, `currency`,
`balance_minor` (remaining value of a stored-balance voucher), `field` (with `invalid_request`), `final` (true for
everything but `failed`: refusals are final, R-8.13 – do not retry), `replayed`, `at` (RFC 3339, UTC). Never a code,
an address or a name.

| `status` | `code` |
|---|---|
| `done` | `issued`, `voided`, `reactivated`, `redeemed` |
| `duplicate` | `already_issued` (core reference of this key already used), `already_void`, `already_active` |
| `refused` | `command_disabled`, `not_authorized`, `daily_limit_reached`, `recipient_daily_limit_reached`, `amount_over_limit`, `currency_not_allowed`, `currency_mismatch`, `invalid_request`, `idempotency_conflict`, `unknown_command`, `voucher_not_found`, `unsupported_voucher`, `invalid_product`, `percent_not_allowed`, `recipient_unknown`, `not_void`, `voided_after_refund`, `voucher_void`, `voucher_expired`, `voucher_redeemed`, `voucher_not_active`, `insufficient_balance`, `amount_mismatch`, `not_redeemable`, `reservation_released` |
| `failed` | `busy` (lock held by a parallel call), `issue_failed`, `void_failed`, `reactivate_failed`, `redeem_failed`, `unavailable` (plugin not booted) |

```php
$reply = vcap_command_issue( array(
    'idempotency_key'       => 'wf-12:v3:evt-9f2c:step-2',
    'reason'                => 'loyalty_reward',
    'workflow_id'           => 12,
    'run_id'                => 'run-881',
    'step_id'               => 'step-2',
    'authorized_by_user_id' => 1,
    'recipient_user_id'     => 57,
    'amount_minor'          => 1000, // 10.00 EUR
    'currency'              => 'EUR',
) );
if ( 'done' === $reply['status'] ) {
    // $reply['voucher_id'], $reply['voucher_status'] – the core mails the voucher itself.
} elseif ( ! $reply['final'] ) {
    // retry later with the same key
}
```

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

The voucher line snapshot (`vcap()->api_v1->line_snapshot( $order_item_id )` since contract 1.4.0, order item meta `_vcap_line`) is written once when the vouchers are issued: `face_value` and `voucher_currency` of each voucher, number of `vouchers`, `quantity`, `order_currency`, `subtotal`, `subtotal_tax`, `total`, `total_tax` (after discounts), `tax_class`, `tax_status`, `prices_include_tax`, `voucher_type`, `discount_type`, `amount_source`, `channels` and `recorded_at`.

### Connector access (since 1.3.0, contract 1.4.0)

Five methods of `vcap()->api_v1` cover what a connector plugin needs besides redeeming, so that it never reads `vcap()->vouchers`, the class `VCAP\Voucher`, `VCAP\Issuer::LINE_META` or the admin page slug (VOUCH-E17-02). Check `version_compare( VCAP_CONTRACT_VERSION, '1.4.0', '>=' )` before using them.

| Method | Returns |
| --- | --- |
| `voucher( int $voucher_id )` | The voucher as an array or `null`: `id`, `code`, `type`, `status`, `usage_mode`, `multi_use` (bool), `value`, `balance`, `currency`, `expires`, `order_id`, `order_item_id`, `product_id`, `variation_id`, `user_id`, `created` (`VoucherService::CONNECTOR_VOUCHER_KEYS`). Amounts are decimals in the voucher currency, times `Y-m-d H:i:s` UTC or `null`. Contains the code (a connector redeems with it), never the token, names, addresses, message, personalisation fields or terms. |
| `voucher_by_code( string $code )` | The same array for a code as a customer typed it (case-insensitive; `code#PIN` is reduced to the code, the PIN is not checked), or `null`. |
| `log_event( int $voucher_id, string $event, string $note = '', int $order_id = 0 )` | `true` or a `WP_Error` (`vcap_invalid_event`, `vcap_unknown_voucher`, `vcap_log_failed`). Writes an entry of the connector into the voucher history. The event name is `<prefix>_<name>` (lower case, letters, digits, underscores, at most 64 characters), not one of Voucher Captain's own events (`VoucherService::CORE_EVENTS`) and not starting with `vcap_`, `voucher_`, `api_`, `passcaptain_`, `wc_`, `woocommerce_` or `wp_`; `VoucherService::connector_event( $event )` tells beforehand. The note is plain text, at most 500 characters: no full code, address or secret. Label it with the filter `vcap_event_labels`. |
| `admin_url( int $voucher_id )` | Address of the voucher on the Voucher Captain admin screen (that screen checks the user's rights). |
| `line_snapshot( int $order_item_id )` | The voucher line snapshot of an order line (see "Per-line order and refund data" above; connector keys added through `vcap_line_snapshot` included), or `[]`. Same data as `voucher_line` of `order_lines()`, without loading the order. |

None of them checks the current user: the connector decides who may see or trigger what. Redeeming still goes through `vcap()->redemptions` (reserve → commit/release), which checks status, channel, user and PIN.

### Contact and consent (since 1.3.0, contract 1.5.0)

For add-ons that must reach the recipient (or buyer) of a voucher who has no WordPress account – e.g. Review Captain
sending a review invitation (suite spec F-13). Check `version_compare( VCAP_CONTRACT_VERSION, '1.5.0', '>=' )`.
**Off by default:** the operator switches it on under *Settings → Integrations (API) → Allow contact reads*, only after
the data protection review (D8) and with a matching note in the privacy policy. Recording consent works while it is off.

| Function (service `vcap()->contact`) | Returns |
| --- | --- |
| `vcap_record_contact_consent( $voucher_id, $purpose, array( 'role' => 'recipient', 'source' => 'form-captain', 'text' => 'review-optin-v1' ) )` (`record_consent()`) | `true` or `WP_Error` (`vcap_contact_invalid`, `vcap_unknown_voucher`, `vcap_contact_no_address`, `vcap_log_failed`). Records that the person of `role` (`recipient` or `buyer`) agreed to be contacted for `$purpose`; `source` names who asked, `text` the consent text shown (id or version, ≤ 64 characters). |
| `vcap_withdraw_contact_consent( $voucher_id, $purpose, $source )` (`withdraw_consent()`) | `true` or `WP_Error`. A withdrawal wins over every earlier consent for the purpose. |
| `vcap()->contact->consent( $voucher_id, $purpose )` | The valid consent (`role`, `source`, `text`, `given_at` in UTC) or `null`. |
| `vcap_read_voucher_contact( $voucher_id, $purpose )` (`contact()`) | `voucher_id`, `purpose`, `role`, `email`, `name`, `locale`, `consent` – or `WP_Error`: `vcap_contact_disabled` (403), `vcap_contact_not_allowed` (403), `vcap_contact_invalid` (400), `vcap_unknown_voucher` (404), `vcap_contact_no_consent` (409), `vcap_contact_opted_out` (409). |

- `$purpose` is a name of 3–40 lower-case letters, digits or underscores starting with a letter (`review_invitation`).
  A consent counts only for its own purpose.
- A consent is bound to the address it was given for: Voucher Captain stores a keyed hash of the address, never the
  address. When the recipient's address is corrected or erased (privacy erasure, retention), the consent no longer
  matches and `contact()` refuses.
- `contact()` answers voucher managers, shop managers and background runs (WP-Cron, WP-CLI) without a logged-in user
  (filter `vcap_contact_read_allowed`). Every answer is written to the voucher history (event `contact_read` with
  purpose, role and acting user – no address) and announced with the action `vcap_voucher_contact_read`
  (voucher id, purpose, user id). The filter `vcap_contact_opted_out` (address, purpose, voucher) can refuse an address.
- The history events `contact_consent`, `contact_consent_withdrawn` and `contact_read` are core events; connectors
  cannot write them through `log_event()`.
- Frequency limits, the unsubscribe link and the content of the message stay the add-on's duty.

### Refunds of completed redemptions

`vcap()->redemptions->refund( $key, $amount, $refund_key )` gives back (part of) a committed redemption, e.g. when the service it paid for is refunded. Gift cards get the amount back on their balance; a single-use voucher becomes usable again once the refunds reach the redeemed amount (partial refunds are recorded only). Idempotent per `$refund_key`; refunds are capped at the redeemed amount (`vcap_refund_exceeded`), only committed redemptions can be refunded (`vcap_not_committed`). Ledger event `api_refunded`, action `vcap_redemption_refunded`.

## Captain Suite (since 1.2.0)

Two in-process PHP contracts for the separate plugins Review Captain and Form Captain. Both are versioned
(`CONTRACT`: review source `1.1.0`, voucher requests `1.0.0`; a consumer checks the major version), carry no personal data in events, are idempotent and
never touch the other plugin's tables. Voucher Captain stays the only owner of codes, values, issuing, redemption,
refunds and voucher personal data.

### Review source (Review Captain), contract v1

`$source = apply_filters( 'vcap_review_source_v1', null )` returns `VCAP\Integrations\ReviewSource` (also
`vcap()->review_source`). Voucher Captain only reports that a redemption **confirmed a service**; invitations,
consent, frequency limits, sending, opt-out links and reviews belong to the review plugin.

Which redemptions count (all conditions):

| Condition | Default |
|---|---|
| Product setting *Redemption confirms a service (for review invitations)* (`_vcap_review_service`) | off; `vcap_review_service_voucher` ( `bool`, `Voucher` ) for vouchers without a product. Store credit and tickets never count. |
| Channel | `counter` (scanner, *Mark as redeemed (store)*: staff confirm on site). `external` (redemption API) only when added to `vcap_review_channels` **and** the target starts with a prefix of `vcap_review_external_target_prefixes` (e.g. `spa:`); the built-in `passcaptain:` targets never. `shop` never: the order is a purchase, which the review plugin judges by the order itself. |
| A review plugin listens | `vcap_review_redemption_eligible` has a listener (filter `vcap_review_source_active`). Without one nothing is recorded. |
| One service = one request | only the **first** confirmed redemption of a voucher becomes `eligible` – also for gift cards with a remaining value (`usage_mode` `balance`) redeemed in several visits. Further redemptions of the same voucher (counter or external) create no event and no reference record while that one is eligible. When the first one is revoked (`redeem_reversed`, `refunded`), the next confirmed redemption may become eligible again; redemptions made in between are not reported afterwards. Filter `vcap_review_first_redemption_only` ( `bool $only` = the setting, default `true`, `Voucher` ): `false` counts every redemption on its own (owner decision 16.11.2026). SET-VC-33: the shop sets it under Settings → Vouchers → Integrations (API) → *Integration rules* (shown while a review plugin listens); the filter wins. |

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

### Voucher requests from forms (Form Captain), contract v1

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
`user_id` ), `vcap_voucher_request_expired` ( `id` ), `vcap_voucher_request_forgotten` ( `id` (0 when none was stored),
`deleted`|`anonymized`|`none` ).

`forget_reference( string $reference )` → `bool` or `WP_Error` (VC-FC-06, for the form plugin when an entry is deleted or
its consent withdrawn; called from WP-Cron, retention or WP-CLI, so it checks **no** capability): an `open` request is
deleted; a `partial`, `issued` or `handed_over` one is anonymised like the privacy eraser (contact name, email and
phone, recipients, message, note and stored issue errors go; company, value, count and the issued vouchers stay). The
reference's claim becomes `rejected`, so a repeated `submit()` answers `state: rejected` and stores nothing. `true`
when a request was changed, `false` when none was stored for the reference, `vcap_invalid_args` for an empty one.
Runs under the request's lock (as confirm/hand-over/reject).

Retention (owner decision 16.11.2026): the daily maintenance (step `voucher_requests`, batches of 50 within the step's
time budget) deletes requests in the states `open` and `partial` 6 months after their receipt (a `partial` one after
the last confirmation – it was never completed and still holds recipient data, so it is treated like an open one) and
requests in the states `issued` and `handed_over` 24 months after the issuing or the hand-over. Rejected requests are
deleted at once by `reject()`. A deleted request takes its form-reference claim with it (the same form entry could be
submitted again afterwards). Filter `vcap_voucher_requests_retention_months` ( `array{open:int, closed:int}` ,
default `[ 'open' => 6, 'closed' => 24 ]`; `0` keeps that group). The suggested privacy policy text names both periods.
Since 1.3.0 (SET-VC-08) the default of the filter comes from the settings `vcap_voucher_requests_open_months` and
`vcap_voucher_requests_closed_months` (Privacy & advanced → *Retention*, at least 1 month); the filter still wins.

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
  `email`, `amount` (float > 0, the request's value per voucher; without a typed amount the price of the request's
  voucher product), `message`), `note` (message and note of the request, `''` when none). A request with neither
  amount nor priced product is not handed over: `vcap_handover_no_amount` (409) before the add-on is asked, the
  request stays `open` with its recipients. An add-on that cannot issue every taken-over row (its own limits, e.g.
  message length or highest amount) should answer a `WP_Error`, so the request stays open too.
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

## Settings: export, import, reset, change log (since 1.3.0)

One service, `VCAP\SettingsTransfer`, serves *WooCommerce → Settings → Vouchers → Privacy & advanced* and WP-CLI; it
works on the settings register (`config/settings.php`, `VCAP\SettingsRegistry`).

- **Export** (SET-VC-14): every global setting marked `export` – never a secret (`vcap_secret`,
  `vcap_customer_key_secret`, entries with `secret`), never "Remove all data", the support log or the default template
  ID, no product settings and no voucher data. File format:
  `{"format": "voucher-captain-settings", "version": 1, "plugin_version": "1.3.0", "exported_at": "…Z", "settings": {option: value}}`.
  Values are the stored values (the default where nothing is saved), not what a `vcap_setting_{key}` filter makes of them,
  normalised to what the shop runs on: `vcap_default_expiry_rule` while never saved is the rule that applies
  (`ProductConfig::default_expiry_rule()`), the country tables are rows (also a text value from before 1.3.0), an empty
  number field that may not be empty is its default (PV-P1-1, PV-P1-2). Rights per role are not part of the file.
- **Import**: the whole file is checked first – no JSON object of this format, no or a newer `version`, a secret or a
  never-exported setting, or one invalid value refuses the whole file and nothing changes. Options this site does not
  know (e.g. of an add-on that is not installed) are skipped and listed. The admin page shows a preview
  (setting, current value, new value) and applies it after a second click; an automatic backup is taken before.
  Settings missing in the file are left unchanged. A file of an earlier build is read like the settings page reads the
  stored values, with a note in the preview: a country table as old text becomes its readable rows (unreadable lines
  are left out), an empty number becomes its default, a never-saved validity rule (`''`) is skipped (PV-P1-2).
- **Checks** (`SettingsTransfer::validate( $option, $value )`, also `wp vcap settings set`): `bool` takes yes/no
  (also on/off, 1/0, true/false), `int`/`decimal` a number within min/max (a value outside is refused, not moved to the
  bound as on the settings page), `select` one of its choices, `list` an array or comma-separated text, code prefix,
  suffix and separator the code characters, reminder days are stored normalised, whole numbers as whole numbers
  (`"5.0"` → `"5"`), text with the cleaning of its field (HTML of the default conditions, custom CSS, logo URL; PV-P4-5),
  `vcap_default_expiry_rule` only `statutory` or `days`; the filter
  `vcap_settings_validate_value` (value or `WP_Error`, option, register entry) lets add-ons check their own options.
- **Reset** (SET-VC-15, PV-P1-3): per section or for all sections. A setting returns to its register `default` – what
  an existing shop runs on while it never saved it –, never to the `fresh` value of a new installation: the option is
  deleted (the autoloaded request settings are stored with their default). `SettingsTransfer::reset_preview( $section )`
  lists every changing setting with `from`, `to` and `legal`; the page shows it before the confirmation, WP-CLI before
  asking (`--dry-run` only lists). An automatic backup is taken when anything changes (the last ten are kept, a backup
  identical to the newest is not stored again; downloadable on the page or with `wp vcap settings export --backup`).
  Secrets, "Remove all data", the rights per role, the default template, internal state and the database (vouchers,
  history) are never touched.
- **Change log** (SET-VC-16, `VCAP\SettingsLog`): every change of a registered option – from the settings page, an
  import, a reset, WP-CLI or code (`added_option`, `updated_option`, `delete_option`) – with time, user, option,
  value from → to and source (`VCAP\Source` plus import/reset/settings command); secrets are recorded as `********`.
  Shown on *Privacy & advanced* (latest 50), part of the personal data export of the user who made the change, kept
  `vcap_settings_log_days` days (default 365, 0 = keep; at most 2,000 entries) and cleaned up by the daily maintenance
  step `settings_log`. Stored in the option `vcap_settings_log` (never autoloaded); the changes of one import, reset,
  WP-CLI command or save of the tab are written with one update (PV-P1-5). Rights per role changed on the page are
  logged as `role:<role>:<right>` (PV-P4-2).
- **Rights**: every action of the page needs `manage_woocommerce` and its nonce; the buttons post to `admin-post.php`
  (actions `vcap_settings_export`, `vcap_settings_backup`, `vcap_settings_import`, `vcap_settings_import_apply`,
  `vcap_settings_import_cancel`, `vcap_settings_reset_preview`, `vcap_settings_reset_cancel`, `vcap_settings_reset`), so
  *Save changes* never imports or resets; a second click on *Apply* / *Reset settings* keeps the message of the first.

### WP-CLI (SET-VC-17)

| Command | Does |
|---|---|
| `wp vcap settings list [--section=<section>] [--format=table\|json\|csv\|yaml]` | Every setting with section, type, value, default and export flag (secrets masked). Sections: `general`, `redemption`, `promotions`, `codes`, `delivery`, `privacy`, `api` and those of add-ons. |
| `wp vcap settings get <option> [--format=plain\|json]` | Stored value (default where nothing is saved); a table row by row (`AT 30 note; …`). Secrets are refused. |
| `wp vcap settings set <option> <value>` | Checked like the page (see *Checks*); tables and lists take a JSON array (lists also comma-separated values); refused values change nothing (exit code 1). "Remove all data" stays with `wp vcap data-removal`. |
| `wp vcap settings export [--file=<path>] [--backup[=<time>]]` | The export JSON (or an automatic backup) to the output or a file. |
| `wp vcap settings import <file> [--dry-run] [--yes]` | Lists the changes (`option: from -> to`), then applies them after a backup; `--dry-run` only lists. |
| `wp vcap settings reset <section>\|all [--dry-run] [--yes]` | Lists the changing settings (`option: from -> default`, `[legal/tax weight – check]` on legal ones), then resets them after a confirmation and a backup (see *Reset*); `--dry-run` only lists. |
| `wp vcap settings profile list [--format=…]` | The country profiles with their check state ("legal check pending" for DE/AT/CH, "neutral, not checked" for EU/OTHER) and the one applied last. |
| `wp vcap settings profile show [--country=<profile>]` | Preview of a profile (default: the profile of the shop's base country): per value `current -> suggested`, `*` = would be applied, `-` = kept (own value or link parameter), `=` = already set, `[Legal check pending]` on values with legal weight; plus the tax and accounting hints. Changes nothing. |
| `wp vcap settings profile apply [--country=<profile>] [--dry-run] [--yes] [--all]` | Applies the values marked `*` after a confirmation and a backup; `--all` also replaces own values and the link parameter; `--dry-run` only lists. |

Every change made this way is in the change log with the source "WP-CLI" (profile: "WP-CLI (country profile)").

### Country profiles (SET-VC-02)

`VCAP\CountryProfiles` reads `config/country-profiles.php` (filter `vcap_country_profiles`). A profile only suggests
values (owner decision E4 = a): validity rule (`vcap_default_expiry_rule`), the country's row of "Statutory validity per
country" (`vcap_expiry_by_country`, only that row is added or replaced; DE/AT from the market data), receipt number at
the counter (`vcap_counter_require_receipt`, DE/AT from the market flag `fiscal_receipt`), retention of recipient data
(`vcap_retention_days`; new installations keep the data, owner rule S9), date format (`vcap_date_format`) and link
parameter (`vcap_coupon_link_param` – suggestion only, never preselected, owner decision E10 open), plus hints on the
default tax type and the accounting format that change no setting. Profiles: DE, AT, CH and the neutral EU and OTHER
(nothing with legal weight). No profile is legally checked yet (owner rule S8): every value with legal weight carries the
mark "Legal check pending" until `checked` is set for that profile.

- `CountryProfiles::preview( $id )` – per value `from`, `to`, `changed`, `legal`, `review` (the mark), `opt_in`,
  `own` (the shop set its own value) and `preselected` (changed, not opt-in, no own value). Changes nothing.
- `CountryProfiles::apply( $id, $items )` – only the given items; each value is checked like an import
  (`SettingsTransfer::validate()`), one invalid value refuses all; then a backup ("profile") and
  `SettingsTransfer::apply()` with the change log source "country profile". The profile applied last is kept in the
  internal option `vcap_country_profile` (setup checklist).
- Admin: block "Country profile" at the top of *Settings → Vouchers → General* and the first step of the setup
  checklist; preview with one checkbox per value, *Apply ticked values* / *Cancel*. Actions `vcap_country_profile_preview`,
  `vcap_country_profile_apply`, `vcap_country_profile_cancel` (admin-post, `manage_woocommerce`, own nonce).

## Extension points for add-ons (public API since 1.2.0)

Voucher Captain is free and complete; paid features live in a separate add-on plugin (docs/FREE-PREMIUM.md, planned:
`voucher-captain-pro`). An add-on uses only the points below, the PHP API above and the REST namespace `vcap/v1`. They
are public API under the versioning rules of CONNECTORS.md → *API versioning policy*: within 1.x only additive changes
(new hooks, extra trailing arguments, new array keys); a hook is never renamed, removed or given a different meaning
without a deprecation period. `tests/run.php` ("A4: add-on extension points") checks that every hook of this table is
called in the code and listed in docs/HOOKS.md, the generated reference with each call site.

The hooks of the settings marked 1.3.0 below (register, validation, hint lines, country profiles, partner rules,
counter columns, payment methods, retention days) and the Form Captain hooks belong to contract **1.8.0**
(`VCAP_CONTRACT_VERSION`, CONNECTORS.md → 6.): an add-on that needs them checks
`version_compare( VCAP_CONTRACT_VERSION, '1.8.0', '>=' )`.

| Hook / function | Type | Arguments | Since | Use |
|---|---|---|---|---|
| `vcap_loaded` | action | `VCAP\Plugin` | 1.2.0 | Start of the add-on: the services (`vcap()->api_v1`, `->redemptions`, `->issuer` …) exist. Settings entries registered here (`vcap_settings_registry`) count: the register is cached for the request only from `init` and forgotten right after this action (PV-P6-1). |
| `vcap_admin_menu` | action | `string $parent_slug` (`vcap-vouchers`) | 1.2.0 | Own pages under *Vouchers*: `add_submenu_page( $parent_slug, … )` (shown before *Settings*). |
| `vcap_brand_name` | filter | `string $name`, `string $context` (`admin`, `notice`, `site_health`, `privacy`, `pdf`, `scanner`) | 1.2.0 | White label: the product name shown to people (admin notices, Site Health, support info, privacy text, PDF metadata). Technical identifiers (text domain, slugs, hooks, names in integration contracts) never change. |
| `vcap_brand_logo` | filter | `string $url` (default `''`), `string $context` | 1.2.0 | White label: a logo next to the name (scanner page); the core shows none. |
| `vcap_admin_menu_title` | filter | `string $title` (default "Vouchers") | 1.2.0 | White label: title of the main admin menu. |
| `vcap_admin_menu_icon` | filter | `string $icon` (default `dashicons-tickets-alt`) | 1.2.0 | White label: icon of the main admin menu, as accepted by `add_menu_page()` (dashicon, image URL or data URI). |
| `vcap_settings_sections` | filter | `array $sections` (id ⇒ label) | 1.2.0 | Own section of WooCommerce → Settings → Vouchers; core sections cannot be renamed or removed. |
| `vcap_settings_fields` | filter | `array $fields`, `string $section` | 1.0.0 | Fields of a section (WooCommerce settings API, saved by WooCommerce). An add-on section starts empty. |
| `vcap_settings_hints` | filter | `array $hints` (each `id`, `section`, `title`, `text`, `link`) | 1.3.0 | Hint lines on add-on functions at the end of a core section (decision S1, docs/FREE-PREMIUM.md rule 3): text only, no form control; list in `config/pro-hints.php` (`white_label`, `sms_delivery`, `wallet_passes`, `bulk_orders`). A field registered through `vcap_settings_fields` with `'replaces' => '<id>'` (string or array) removes its line. |
| `vcap_settings_registry` | filter | `array $settings` (option ⇒ `type`, `default`, `min`, `max`, `empty`, `choices`, `section`, `legal`, `export`, `secret`, `fresh`) | 1.3.0 | Settings register (`config/settings.php`, class `VCAP\SettingsRegistry`, SET-VC-01): one entry per option with default, type, bounds, section, legal weight, export and secret flag. Add-ons register their own options here (export without secrets, change log, WP-CLI); core entries cannot be removed. `SettingsRegistry::field( $option, $field )` completes a settings field with id, default and min/max; every number setting is also checked on the server when the tab is saved (out of range → nearest bound, no number → stored value kept). `Settings::defaults()` replaces the constant `Settings::DEFAULTS`, which stays as a deprecated copy of the 1.2.0 defaults (65 options, not maintained). |
| `vcap_customer_can_resend` | filter | `bool $allowed` (setting `vcap_customer_can_resend`, default yes), `Voucher` | 1.1.0 | SET-VC-18: whether the buyer may resend a delivered voucher from My Account; the filter gets the setting and wins. |
| `vcap_customer_can_change`, `vcap_recipient_fix_days`, `vcap_recipient_fix_max`, `vcap_topup_min_remaining_days`, `vcap_checkin_undo_minutes`, `vcap_counter_reversal_minutes` | filter | the setting of the same name, `Voucher` | 1.2.0 | SET-VC-18: customer self-service and deadlines are settings (Settings → Vouchers → Delivery & downloads "Customer self-service", Redemption & balance "Top-ups and counter"; defaults as in 1.2.0: yes, 30 days, 2, 30 days, 10 min, 15 min). Each filter gets the setting instead of the fixed value and wins; the floors of 1.2.0 stay (link at least 1 day, at most 2 corrections, windows not below 0). |
| `vcap_printed_link_grace_days` | filter | `int $days` (setting `vcap_printed_link_grace_days`, default 30), `Voucher` | 1.3.0 | SET-VC-18: days the download link printed on a voucher (QR code) stays valid after the voucher expired (`Downloads::printed_link_grace_days()`). |
| `vcap_affiliate_commission_on_voucher_sales`, `vcap_passcaptain_restore_on_revoke` | filter | the setting of the same name (`bool`, default yes) and the arguments as before | 1.1.0 | SET-VC-33 (1.12 no. 38, 39): see the next row. |
| `vcap_physical_activation_statuses`, `vcap_review_first_redemption_only`, `vcap_subscription_issue_on_switch` | filter | the setting of the same name (statuses `string[]` default `['completed']`; first redemption only `bool` default yes; subscription switch `bool` default no) and the arguments as before | 1.2.0 | SET-VC-33 (1.12 no. 12, 35, 38–40): rules of physical cards and partner plugins are settings (Settings → Vouchers → Integrations (API) → *Integration rules*); each filter gets the setting and wins. Physical cards: only paid statuses, empty = completed. |
| `vcap_settings_partner_active` | filter | `bool $active`, `string $partner` (`review`, `affiliate`, `passcaptain`, `subscriptions`) | 1.3.0 | SET-VC-33: whether the rules of a partner plugin are shown in *Integration rules* (a hidden rule keeps its value and still applies). |
| `vcap_counter_location_fields` | filter | `array $columns` (column id ⇒ `label`, `type` `text`/`checkbox`/`select`, `default`, `options`) | 1.3.0 | SET-VC-22: own columns of the table "Tills / branches" (Settings → Vouchers → Counter & locations); the value is stored with the row (`VCAP\CounterLocations::rows()`: `name`, `key`, `active` and the add-on columns; stored as a JSON list in `vcap_counter_locations`, the one-name-per-line text of 1.2.0 is still read). Core columns cannot be removed. Only active tills reach the scanner (`Counter::locations()`, filter `vcap_counter_locations`). |
| `vcap_review_retention_months`, `vcap_review_revoked_retention_days` | filter | `int` (setting of the same name, default 24 months / 90 days) | 1.2.0 | SET-VC-08: retention of the review invitation notes (all / withdrawn ones; `ReviewSource::retention()`); the filter gets the setting (block *Retention*, at least 1 month / 30 days) and wins, `0` keeps them. |
| `vcap_command_log_days` | filter | `int $days` (setting `vcap_command_log_days`, default 90) | 1.3.0 | SET-VC-08: days the daily counters and the protocol of the voucher commands are kept (`OptionCommandStore::log_days()`, cleaned up by the daily maintenance step `protocols`); `0` keeps them. Replies (idempotency) stay 90 days. |
| `vcap_scanner_failed_lookup_days` | filter | `int $days` (setting `vcap_scanner_failed_lookup_days`, default 92) | 1.3.0 | SET-VC-08: days the failed scanner lookups per staff member are kept (`Counter::failed_lookup_days()`, step `protocols`); `0` keeps them. |
| `vcap_settings_validate_value` | filter | value in the stored format or `WP_Error`, `string $option`, `array $entry` | 1.3.0 | Check a value of an own option before it is imported or set with `wp vcap settings set` (see *Settings: export, import, reset, change log*); return a `WP_Error` to refuse. |
| `vcap_country_profiles` | filter | `array $profiles` (id ⇒ `country`, `checked`, `settings`, `expiry_row`, `tax`, `accounting`, `label`) | 1.3.0 | SET-VC-02: add country profiles or change suggested values (see *Country profiles*). Only exportable settings can be suggested (never secrets or "Remove all data"); a profile is always applied after the preview. Set `checked` only after the legal and tax check for that country. |
| `vcap_pro_hints_hidden` | filter | `bool $hidden`, `int $user_id` | 1.3.0 | Whether the hint lines are hidden for the current user (default: the user's setting "Hide hints on extensions"). |
| `vcap_voucher_excluded_gateways` | filter | `string[] $ids` (setting `vcap_voucher_excluded_gateways` "Do not sell vouchers with", default none) | 1.3.0 | SET-VC-19: payment gateway ids not offered while the cart (or the order paid on "Pay for order") contains a voucher product (`PaymentMethods::excluded()`); the filter wins over the setting. |
| `vcap_product_settings_fields` / `vcap_product_settings_save` | action | – / `WC_Product`, `array $post` | 1.1.0 | Own fields in the product's *Voucher* tab and saving them. |
| `vcap_template_design` | filter | `array $design`, `int $template_id` | 1.0.0 | The design a PDF template prints with. |
| `vcap_sanitize_design` | filter | `array $clean`, `array $posted` | 1.0.0 | Keep own element keys when a design is saved. |
| `vcap_render_template_id` | filter | `int $template_id`, `Voucher` | 1.0.0 | Print a voucher with another template. |
| `vcap_placeholder_definitions` / `vcap_placeholders` | filter | `array $defs` / `array $values`, `Voucher` | 1.0.0 | Own placeholders in PDFs and email texts. |
| `vcap_report_tabs` | filter | `array $tabs` (view id ⇒ label) | 1.2.0 | Own tab under *Vouchers → Reports*; core views cannot be replaced. |
| `vcap_report_page` | action | `string $view` | 1.2.0 | Print the page of the own report tab (the capability is checked before). |
| `vcap_report_group` | filter | `array $group`, `string $code`, `int $coupon_id` | 1.1.0 | Group of a code in the campaign report. |
| `vcap_voucher_types`, `vcap_redemption_channels`, `vcap_redemption_allowed` | filter | see *Extension points for connector plugins* | 1.1.0 | Own voucher types, channels and rules. |
| `vcap_redemption_providers` / `VCAP\Contracts\RedemptionProvider` | filter / interface | see *Provider extension* | 1.0.0 | Own redemption backend. |
| `vcap_voucher_admin_actions` / `vcap_voucher_admin_action` | action / filter | see docs/HOOKS.md | 1.1.0 | Own buttons on the voucher page. |
| `vcap_voucher_links` | filter | `array $links`, `Voucher`, `string $context` (`email`, `email_plain`, `account` – also the order received page and *My Account → View order* –, `placeholder`) | 1.2.0 | Links such as "Add to Wallet" in the voucher email, the voucher lists and the PDF placeholder `{link:<id>}`. |
| `vcap_voucher_next_steps` | filter | `string[] $steps`, `Voucher` | 1.2.0 | Steps in the voucher email after receiving it. |
| `vcap()->issuer->issue_standalone( $args )` | method | see *Issuing vouchers without an order* | 1.2.0 | Vouchers without an order (company orders, goodwill); the caller checks the capability. Then `vcap_voucher_issued_standalone` (`Voucher`, `$args`). |
| `vcap_vouchers_issued` | action | `Voucher[]`, `WC_Order` | 1.0.0 | Vouchers of an order were issued. |
| `vcap_voucher_redeemed` | action | see *External redemptions* | 1.1.0 | An external redemption was completed (after COMMIT). |
| `vcap_voucher_event` | action | `$voucher_id`, `$event`, `$amount`, `$balance_after`, `$note`, `$order_id`, `$source`, `$user_id` | 1.0.0; `$source`/`$user_id` 1.2.0 | Every history entry (after COMMIT). Register with `add_action( 'vcap_voucher_event', $cb, 10, 8 )` for the last two; `$source` is `VCAP\Source::current()` (admin, cron, rest, scanner, checkout, cli, web), `$user_id` 0 for none. |

The core makes no request to a license or update server and needs no add-on (tests/license-guard.php, readme.txt →
External services). An add-on never locks or moves a core feature (docs/FREE-PREMIUM.md).

## Pass Captain

When Pass Captain is active, Voucher Captain registers itself as its voucher provider (`wp_pass_captain_voucher_provider_v1`, contract version 1): vouchers and gift cards can pay for tickets in Pass Captain's own checkout. Quote, reserve, commit, release and refund map to the redemption API (target `passcaptain:{order reference}`, channel `passcaptain` – tick it under *Redeemable via*, or leave all channels unticked). Amounts are converted between Pass Captain's minor units and the voucher currency; vouchers in another currency are refused. Ticket refunds give the voucher share back proportionally. Checked in CI with both plugins installed (`tests/passcaptain-integration.php`).

### Ticket vouchers (voucher → Pass Captain tickets)

A voucher product can name a Pass Captain ticket type – a fixed date – or a whole event, where the customer chooses one of its upcoming dates (*Pass Captain ticket*: "Fixed date: …" or "Customer chooses the date: …"; optional *Ticket name (date choice)* to offer only ticket types with that name; *Tickets per voucher* in the product's voucher settings; shown while Pass Captain ≥ the version with `wp_pass_captain_ticket_service_v1` is active). Each sold voucher stores that target with its order line (`_vcap_line` → `passcaptain_ticket`) and is exchanged for the tickets:

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

