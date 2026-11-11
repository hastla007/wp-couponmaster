# Voucher Captain PHP API v1

Voucher Captain exposes an in-process PHP service at `vcap()->api_v1`. This is a plugin integration contract, not a public REST API. Calls must run after the plugin has booted and WooCommerce is available.

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
2. `reserve( $code, [ 'key' => …, 'target' => …, 'amount' => …, 'meta' => [] ] )` – locks the voucher (single-use: status `reserved`; gift card: the amount is deducted). The reservation expires after the configured lifetime (WooCommerce → Settings → Vouchers → Integrations, default 15 minutes) and is then released automatically.
3. Deliver your service (grant the course, issue the ticket …).
4. `commit( $key )` makes the redemption final and fires `vcap_voucher_redeemed` exactly once – or `release( $key )` gives the value back (fires `vcap_redemption_released`).

`get( $key )` returns the current state. All methods return an array or a `WP_Error`.

### Idempotency

The caller supplies the key (8–64 characters `A–Z a–z 0–9 . _ : -`, e.g. a UUID or `learnboating:grant:1234`). Repeating `reserve()` with the same key and the same request returns the existing redemption; the same key with a different voucher, target, amount or user returns `vcap_idempotency_conflict`. `commit()` and `release()` are idempotent. Store the key before calling `reserve()` so a timeout can be retried safely.

### Identity

PHP callers may pass `user_id`; otherwise the logged-in user is used. The REST API always uses the authenticated user and ignores any `user_id` in the request. Only the reserving user or a voucher manager may read, commit or release a redemption (`vcap_rest_can_access_redemption` filter).

### REST

| Method | Route | Body | Success |
|---|---|---|---|
| POST | `/wp-json/vcap/v1/redemptions/check` | `code`, `target`, `amount` | 200 voucher data |
| POST | `/wp-json/vcap/v1/redemptions` | `code`, `key`, `target`, `amount`, `meta` | 201 redemption |
| GET | `/wp-json/vcap/v1/redemptions/{key}` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/commit` | – | 200 redemption |
| POST | `/wp-json/vcap/v1/redemptions/{key}/release` | – | 200 redemption |

Authentication: browsers of logged-in users send the WordPress REST nonce (`X-WP-Nonce`, from `wp_create_nonce( 'wp_rest' )`); external servers use WordPress Application Passwords for a dedicated technical user. Anonymous requests receive `401`. Narrow access with the `vcap_rest_can_redeem` filter.

Redemption data: `redemption_key`, `state` (`reserved`, `committed`, `released`), `voucher_id`, `code`, `user_id`, `target`, `amount`, `currency`, `balance_after` (gift cards), `expires_at` (while reserved), `created_at`, `committed_at` (UTC), `meta`.

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
| `vcap_target_unavailable` | 409 | Refused by `vcap_redemption_target_available` |
| `vcap_idempotency_conflict` | 409 | Key reused for a different request |
| `vcap_expired` / `vcap_cancelled` | 410 | Voucher expired / voided |
| `vcap_not_redeemable` | 422 | Tickets and shop-only (reusable) vouchers |
| `vcap_rate_limited` | 429 | Too many invalid codes (setting, default 10 per 15 minutes) |
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

// React once per completed redemption.
add_action( 'vcap_voucher_redeemed', function ( array $redemption ) { /* audit, notify … */ } );
```
