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

PHP callers may pass `user_id`; otherwise the logged-in user is used. Personal vouchers (restricted to the buyer's or recipient's email) are redeemed only for a user whose account email matches (V6, see SECURITY.md → Personal vouchers); a guest is refused with `vcap_login_required` (403, "Please log in with j***@example.com …"), another account with `vcap_not_allowed`. PHP callers may pass the customer's `email` together with `email_verified => true` when they vouch that the customer controls that address (their own authenticated customer account, staff who checked the holder's ID); an `email` without `email_verified` – e.g. an address a guest typed in a checkout – never unlocks a personal voucher. Store credit (type `credit`) is never redeemable through this API – it belongs to one customer account and is used in the shop. The REST API always uses the authenticated user and ignores any `user_id` in the request. Only the reserving user or a voucher manager may read, commit or release a redemption (`vcap_rest_can_access_redemption` filter).

### REST

| Method | Route | Body | Success |
|---|---|---|---|
| POST | `/wp-json/vcap/v1/redemptions/check` | `code`, `target`, `amount`, `currency` | 200 voucher data |
| POST | `/wp-json/vcap/v1/redemptions` | `code`, `key`, `target`, `amount`, `currency`, `meta` | 201 redemption (200 for a repeated request) |
| GET | `/wp-json/vcap/v1/redemptions/{key}` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/commit` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/release` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/refund` | `amount`, `refund_key` | 200 redemption with `refunded` (total) and `restored` (this call) |

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
| `vcap_not_found` | 404 | Unknown code (counts towards the invalid-code limit) |
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
| `code` | Own, unused code; default a generated one. |
| `paid` | Whether the voucher was sold (paid outside WooCommerce, e.g. a company order on invoice). Default `false`: a voucher without a sale (goodwill, prize) is a price reduction for VAT (treatment `discount`) and is listed as free of charge in the liability report (TAX.md 2.8, M12-42). |
| `source`, `reference` | Free label of the caller and an idempotency key (up to 100 characters, e.g. `order-4711/3`), both shown in the history. A second call with the same `reference` issues nothing and answers `vcap_reference_used` with `voucher_id` in the error data (0 while the first call is still running). |
| `reason` | Why it was issued (e.g. "goodwill"), shown in the history with source and reference. |
| `balance` | Remaining value below `amount` (stored balance only), e.g. an existing card taken over with what is left of it. Default: `amount`. |
| `paid` | Whether money was received for the voucher. Vouchers → New voucher and `wp vcap issue --amount=…` pass `false` (goodwill, prize), the card import passes `true`. The core passes it on to `vcap_voucher_issued_standalone`; the tax handling of sold/unsold vouchers reads it. |

The admin screens Vouchers → New voucher and Vouchers → Import cards and the commands `wp vcap issue --amount=<amount> [--email=<email>] [--product=<id>] [--send]` and `wp vcap import <file> [--dry-run]` are built on this method (sources `admin`, `cli`, `import`; imported cards get the history entry `imported`).

Errors: `vcap_invalid_product`, `vcap_invalid_args`, `vcap_invalid_amount`, `vcap_no_address`, `vcap_code_taken`, `vcap_reference_used`, `vcap_issue_failed` (the error data carries an HTTP-like `status`). Afterwards the action `vcap_voucher_issued_standalone` (`Voucher`, `$args`) fires (not `vcap_vouchers_issued`, whose listeners expect an order).

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

