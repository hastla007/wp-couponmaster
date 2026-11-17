# Workflow provider contract v1

Voucher Captain offers a versioned provider contract to workflow plugins – Workflow Captain or any other plugin that implements the consumer side described here. A workflow plugin receives voucher **events** and may call a few **services**; it may also take over selected **optional emails**. It never changes balances or the voucher history itself: every change goes through Voucher Captain's own logic.

The contract is optional. Without a workflow plugin (or with a deactivated or incompatible one) nothing is built, nothing waits and every native flow – issuing, delivery, redemption, refunds, reminders – runs exactly as before.

Contract version: **1.0.0** (`VCAP\Workflow\Provider::CONTRACT_VERSION`). Code: `src/Workflow/` (`Provider`, `Services`, `Handover`), tests in `tests/run.php` (section "Workflow provider contract") and `tests/workflow-integration.php` (CI).

## 1. Registration

**Provider (Voucher Captain).** Available from the action `vcap_loaded` on:

| Way | What it does |
|---|---|
| `vcap()->workflow` | The provider object (`VCAP\Workflow\Provider`). |
| `apply_filters( 'vcap_workflow_provider', null )` | Returns the provider object (for plugins that do not call `vcap()`). |
| Action `workflow_captain_register_providers` ( `$registry` ) | Voucher Captain calls `$registry->register( 'voucher-captain', $provider )` when the registry has such a method. |
| Filter `workflow_captain_providers` ( `array $providers` ) | Voucher Captain adds `'voucher-captain' => $provider`. |
| `VCAP\Workflow\Provider::descriptor()` | The contract: `id`, `name`, `version`, `contract_version`, `supported_contracts` (majors, `["1"]`), `event_hook`, `events`, `services`, `communications`, `envelope_fields`, `data_fields`, `deprecated`, `blog_id`. |

**Consumer (the workflow plugin).** It announces itself with the filter `vcap_workflow_consumer`:

```php
add_filter( 'vcap_workflow_consumer', fn() => array(
	'id'             => 'workflow-captain',
	'name'           => 'Workflow Captain',
	'version'        => WORKFLOW_CAPTAIN_VERSION,
	'contract'       => '1.0.0',          // contract version the plugin was built for
	'edition'        => 'pro',            // core | pro
	'entitlement'    => 'active',         // active | expired | missing (licence of the edition that needs one)
	'communications' => array( 'expiry_reminder' ), // optional emails it sends now (section 7)
) );
```

Compatibility (`Provider::compatibility()`): `missing` (no consumer), `incompatible` (no version, or another major), `compatible` (same major; a consumer built for a newer minor version is compatible and reads `descriptor()` to see what this provider offers). Events are fired whenever a listener is registered on `vcap_workflow_event` – unless a workflow plugin is registered as a consumer and is `incompatible`: then no events are built at all. Communications are handed over only to a compatible consumer (section 7).

## 2. Versioning and deprecation

- **SemVer.** Major = breaking change (removed or renamed field, event type or service, changed meaning, stricter validation of an existing argument). Minor = additions (new event type, new `data` field, new service, new optional argument, new error code for a new situation). Patch = fixes without a contract change.
- **Consumers must ignore** unknown envelope fields, `data` fields, event types and error data keys, and must not rely on the order of keys.
- **Deprecation.** A field, event type or service that will be removed is listed in `descriptor()['deprecated']` and in the changelog below at least **two minor versions and six months** before it disappears; it is removed only with the next major version. When a major version 2 arrives, version 1 is still offered (both majors in `supported_contracts`) for at least **twelve months**.
- Voucher Captain's own PHP API v1 (API.md) and the hooks (docs/HOOKS.md) follow their own policy (CONNECTORS.md → API versioning policy).

## 3. Capabilities

| Kind | Contract v1 |
|---|---|
| Events (`descriptor()['events']`) | `voucher.created`, `voucher.delivered`, `voucher.delivery_failed`, `voucher.redeemed`, `voucher.redemption_refunded`, `voucher.refunded`, `voucher.voided`, `voucher.reactivated`, `voucher.expired`, `voucher.communication_handed_over` |
| Services (`descriptor()['services']`) | `issue`, `resend`, `void`, `reactivate`, `redeem`, `contact`, `replay`, `status` |
| Communications (`descriptor()['communications']`) | `expiry_reminder`, `gift_delivered`, `gift_used` |

## 4. Events

### Envelope

Each event is fired as `do_action( 'vcap_workflow_event', array $envelope )`:

| Field | Type | Meaning |
|---|---|---|
| `contract_version` | string | `1.0.0` |
| `provider` | string | `voucher-captain` |
| `event_id` | string (UUID format) | Stable and unique: derived from the site (random id in the option `vcap_workflow_instance`), the blog, the ledger row and the type. The same ledger entry always gives the same id – also in `replay()`. The instance is bound to the site address (see *Copies and staging sites* below). |
| `type` | string | One of the event types (table below). |
| `occurred_at` | string | When the change was written, UTC ISO 8601 (`2026-11-16T09:30:00Z`). |
| `source_type` | string | `voucher` |
| `source_id` | int | Voucher id. |
| `sequence` | int | Id of the ledger row (voucher history) the event comes from – ascending per blog, the cursor of `replay()`. |
| `blog_id` | int | Blog of a multisite network (1 on a single site). |
| `status` | string | Voucher status when the event was built: `active`, `scheduled`, `reserved`, `redeemed`, `expired`, `void`. |
| `replayed` | bool | `true` for events from `replay()`. |
| `data` | object | Allowlisted fields (next table). |

### Payload allowlist (`data`)

Only these keys, only scalars or `null` (`Provider::DATA_FIELDS`); `Provider::redact()` drops everything else and, as a second line of defence, any string that contains an `@`, the voucher's code or token.

| Key | Meaning |
|---|---|
| `voucher_type` | `voucher`, `giftcard`, `ticket` or a connector type |
| `usage_mode` | `single`, `balance`, `unlimited` |
| `currency` | ISO 4217 (`null` for percentage vouchers) |
| `value_minor` | Face value in minor units (`null` for percentage vouchers) |
| `percent` | Percentage of a percentage voucher, else `null` |
| `balance_minor` | Remaining balance in minor units (stored-value cards), else `null` |
| `amount_minor` | Amount of this event in minor units (redemption, refund, expired value), where it has one |
| `order_id` | WooCommerce order of the event, else the order the voucher was bought with, else `null` |
| `product_id` | Voucher product, or `null` |
| `expires_at` | Expiry, UTC ISO 8601, or `null` |
| `origin` | Where the change came from: `admin`, `cron`, `rest`, `scanner`, `checkout`, `cli`, `web`, `workflow` (a service call of the workflow plugin – use it to avoid loops) |
| `channel` | Redemptions: `shop`, `counter`, `external` |
| `reason` | `voucher.delivery_failed`: coded reason (`delivery_error`, `delivery_not_sent`, `delivery_partial`, `resend_failed`, `reminder_failed`, `delivery_given_up`; `unknown` for an entry without coded reason, e.g. a bounce); `voucher.voided`: `refund` or `other` |
| `scope` | `voucher.refunded`: `partial` or `full` |
| `final` | `voucher.delivery_failed`: `true` when no more automatic attempts follow |
| `resend` | `voucher.delivered`: `true` for a repeated sending |
| `communication`, `role`, `days` | `voucher.communication_handed_over`: which email, for whom (`recipient`, `buyer`), days before the expiry |

Never in an event: codes, PIN, token, download or PDF links, email addresses, names, messages, notes or other free text, user ids, IP addresses.

### Event types

| Type | Ledger entry (voucher history) | Data |
|---|---|---|
| `voucher.created` | `issued` (order, standalone, import) | – |
| `voucher.delivered` | `delivered`, `resent` | `resend` |
| `voucher.delivery_failed` | `delivery_failed`, `delivery_given_up` (also bounces reported with `mark_bounced()`) | `reason`, `final` |
| `voucher.redeemed` | `redeemed`, `committed` (shop or counter), `api_committed` (redemption API) | `channel`, `amount_minor` |
| `voucher.redemption_refunded` | `restored` (shop order refunded/cancelled), `redeem_reversed` (counter cancellation), `api_refunded` (refund of an external redemption) | `channel`, `amount_minor` |
| `voucher.refunded` | `purchase_refunded` (part of the purchased value taken off: `scope` `partial`); `voided` because the purchase was refunded or cancelled (`scope` `full`) | `scope`, `amount_minor` |
| `voucher.voided` | `voided` | `reason` (`refund` – then a `voucher.refunded` with `scope` `full` comes first, from the same ledger row with its own id – or `other`) |
| `voucher.reactivated` | `reactivated` | – |
| `voucher.expired` | `expired` (daily maintenance) | `amount_minor` (value lost) |
| `voucher.communication_handed_over` | `workflow_handover` (section 7) | `communication`, `role`, `days` |

A full refund of a gift card can be preceded by a `partial` event of the same refund (the value taken off before the card is voided). Store credit accounts (type `credit`) are customer accounts, not vouchers: they fire no workflow events.

### Delivery semantics

- **After COMMIT.** Events are built from `vcap_voucher_event`, which fires only after the database transaction of the change was committed (D6, CONNECTORS.md → Event timing); a rolled-back change never fires one.
**Copies and staging sites** (owner decision 16.11.2026): the instance belongs to the address of the website. The
option `vcap_workflow_instance_site` (autoloaded) keeps a signature of the normalised `home_url()` – without scheme
and trailing slash, host in lower case, so a switch between `http` and `https` or a `/` at the end changes nothing –,
the previous instance ids (at most 5, with the old signature and the time) and whether the admin notice is due. When
the database runs under another address (a copy, a staging site, a moved shop), the next event derives a new instance
id from the old id and the new address (two requests at the same moment agree on it), notes the old one and fires
`vcap_workflow_instance_changed( string $new_instance, string $old_instance )`. From then on the copy's event ids
differ from the original's, so a workflow plugin connected to both never takes an event of the copy for one of the
live shop. Site Health → *Workflow plugin (provider contract)* shows "New workflow instance because the address of
the website changed (copy/staging)" with the time and the previous id, and shop managers (`manage_woocommerce`) see
the same once as an admin notice. A site updated from a version without the binding keeps its instance and binds it
to its current address. Moving the live shop to a new domain also gives a new instance: deduplicate by `event_id`
and re-sync with `replay()` after such a move.

- **At least once, deduplicate by `event_id`.** Store the ids you processed. Retries, replays and two listeners can deliver an event again; the id stays the same.
- **Order.** `sequence` ascends with the ledger; within one request events arrive in that order. Do not assume a gap-free sequence (most ledger rows are no workflow event).
- **A failing listener never undoes the change.** An exception thrown by a `vcap_workflow_event` listener is caught, logged (WooCommerce → Status → Logs, source `voucher-captain`) and counted in the admin status with the sequence it failed at. Catch up with `replay()`.
- **Replay.** `vcap()->workflow->replay( int $after_sequence = 0, int $limit = 100 )` returns `array( 'events' => envelope[], 'next' => int, 'more' => bool )`: the events of the ledger rows after `$after_sequence` (at most 500 per call), with the same ids, `replayed: true` and the voucher state as it is now. Continue with `next` while `more` is true. Replay holds the same data as the live events and needs no capability; on a multisite network it reads the current blog.
- **Cost.** While no listener is registered on `vcap_workflow_event`, Voucher Captain neither reads nor builds anything.

## 5. Services

Call them on the provider: `vcap()->workflow->void( array( … ) )`. Each returns an array or a `WP_Error` (section 6).

| Service | Arguments | Capability (any) | Result |
|---|---|---|---|
| `issue` | `idempotency_key`; like `Issuer::issue_standalone()` (API.md): `product_id`, `amount`, `type`, `usage`, `discount_type`, `template_id`, `expires_at`, `expiry_days`, `recipient_name`, `recipient_email`, `buyer_name`, `buyer_email`, `buyer_copy`, `message`, `send`, `delivery_at`, `paid` (default `false`), `reason`. No own `code`. | `vcap_manage_vouchers` or `manage_woocommerce`, and the right to raise values (`vcap_increase_balance` or `manage_woocommerce`) | `voucher_id`, `status` – never the code; the voucher is delivered by Voucher Captain's own email |
| `resend` | `idempotency_key`, `voucher_id`, `role` (`recipient`, `buyer` or empty = as routed) | `vcap_manage_vouchers`, `manage_woocommerce` | `voucher_id`, `status`, `roles` |
| `void` | `idempotency_key`, `voucher_id`, `reason` (required, in the history) | `vcap_manage_vouchers`, `manage_woocommerce` | `voucher_id`, `status`, `changed` (`false` when it was void already) |
| `reactivate` | `idempotency_key`, `voucher_id` | `vcap_increase_balance`, `manage_woocommerce` | `voucher_id`, `status`; never for a voucher voided because of a refund (`vcap_voided_after_refund`) |
| `redeem` | `idempotency_key`, `code` (as the customer entered it, also `code#PIN`), `target` (prefixed with `workflow:`), `amount` (gift cards), `currency`, `user_id` / `email` + `email_verified` (personal vouchers, API.md → Identity; only voucher managers may pass them for another person – for a till with `vcap_redeem_api` the current user counts) | `vcap_redeem_api`, `vcap_manage_vouchers`, `manage_woocommerce` | `voucher_id`, `redemption_key`, `state` (`committed`), `target`, `amount_minor`, `balance_after_minor`, `currency`, `committed_at` |
| `contact` | `voucher_id`, `communication` | `vcap_manage_vouchers`, `manage_woocommerce` | Only for a communication handed over now (section 7): `role`, `email`, `name`, `code_hint` (`…7KQ2`), `balance_minor`, `currency`, `expires_at`. Each successful call writes the history entry `workflow_contact_read` (section 7). |
| `replay` | `after`, `limit` | – | see section 4 |
| `status` | – | – | see section 8 |

Every result carries `contract_version`, `changed` and `replayed`.

**Permission.** The current user needs one of the capabilities. Workflow runs in the background (WP-Cron, Action Scheduler) have no user: run the service calls as the workflow plugin's technical user (`wp_set_current_user()`), or allow them with the filter `vcap_workflow_service_allowed` ( `bool $allowed`, `string $service`, `array $args` ).

**What the services do.** They use the plugin's own paths – `Issuer::issue_standalone()`, `Delivery::send()` (like the bulk action "Send again"), `Issuer::void()`, `Issuer::reactivate()`, `RedemptionService::reserve()` + `commit()` – with their validation and refusal rules (e.g. no resending of scheduled, void, expired, used-up or reserved vouchers). Every booking they cause carries the source `workflow` in the history; each call adds a history entry "Workflow action" (`workflow_command`: service, hash of key and request – no personal data). Balances and the ledger are never written directly.

**Not offered on purpose:** refunds and payments, balance corrections, codes or PDFs, editing personal data. Refunds of external redemptions stay with the shop (`vcap()->redemptions->refund()`, API.md).

### Idempotency

- Every changing service needs `idempotency_key`: 8–64 characters `A–Z a–z 0–9 . _ : -` (e.g. `wfc:run-4711:step-3`). Derive it from the workflow run and step and store it before the call.
- First call: acts once. **Repeat with the same request:** the stored outcome (`replayed: true`), nothing is done again – no second voucher, email, void or redemption. **Same key, other request** (other voucher, service or arguments): `vcap_idempotency_conflict`. **Repeat while the first call still runs:** `vcap_in_progress` (retry later with the same key; a call that stopped without an outcome is taken over after two minutes). **A refused or failed call** frees its key, so the retry is checked again.
- `issue` uses the issue reference `workflow:{hash of key}` (one voucher per key, even when two calls race); `redeem` derives the redemption key `workflow:{hash of key}` (one redemption per key, RedemptionService's own idempotency).
- The outcome is kept in the voucher history (the entry "Workflow action"), so a key stays used after the claim behind it is removed by the daily maintenance.

## 6. Errors

`WP_Error` with a stable code and data `status` (HTTP-like), `retryable` (bool: a retry with the same key may succeed) and `contract_version`. Errors of the underlying services (API.md: `vcap_invalid_product`, `vcap_invalid_args`, `vcap_invalid_amount`, `vcap_no_address`, `vcap_not_found`, `vcap_reserved`, `vcap_insufficient_balance`, `vcap_expired`, `vcap_cancelled`, `vcap_currency_mismatch`, `vcap_login_required`, `vcap_temporary_failure`, …) are passed on with the same data added.

| Code | Status | Retryable | Meaning |
|---|---|---|---|
| `vcap_not_allowed` | 403 | no | Capability missing, or `contact` for a communication that is not handed over |
| `vcap_invalid_request` | 400 | no | Idempotency key missing or malformed, required argument missing |
| `vcap_not_found` | 404 | no | Unknown voucher (store credit accounts are not vouchers) |
| `vcap_idempotency_conflict` | 409 | no | Key already used for another request |
| `vcap_in_progress` | 409 | yes | The first call with this key is still running |
| `vcap_invalid_state` | 409 | no | Not possible in this state (e.g. reactivate a voucher that is not void, resend a void voucher) |
| `vcap_voided_after_refund` | 409 | no | Voided because its purchase was refunded – only the admin can reactivate it |
| `vcap_delivery_failed` | 502 | yes | The email was not sent (reason in the voucher history) |
| `vcap_action_failed` | 500 | yes | The change was not completed (e.g. the WooCommerce coupon could not be switched) |

## 7. Optional emails handed over (communications)

Voucher Captain's own emails stay the default. Under **WooCommerce → Settings → Vouchers → Integrations (API) → Workflow plugin** the shop can leave single optional emails to the workflow plugin (each switch off by default):

| Communication | Native email | Recipient |
|---|---|---|
| `expiry_reminder` | Expiry reminder (setting "Expiry reminder", days before) | the holder (recipient when the voucher is sent to the recipient, else the buyer) |
| `gift_delivered` | Notice to the buyer: gift delivered | buyer |
| `gift_used` | Notice to the buyer: gift used (once per voucher) | buyer |

A communication is handed over only while its switch is on **and** a compatible consumer is registered **and** lists it in `communications` **and** its `entitlement` is `active` (not `expired` or `missing`). Otherwise Voucher Captain sends its own email as usual, so a missing, deactivated, incompatible or unlicensed workflow plugin never leaves a customer without the message. When handed over, Voucher Captain sends nothing, writes the history entry "Handed over to the workflow" (`workflow_handover`) at the moment it would have sent the email – with the same rules (reminder days, at most once, only gifts with a buyer address) – and fires `voucher.communication_handed_over`. The workflow plugin then gets address and name with `contact()`.

Every successful `contact()` call is recorded in the voucher history (owner decision 16.11.2026): event
`workflow_contact_read` with the time, the user id of the caller, the workflow plugin (the `name` of the registered
consumer), the `communication` and the `role` – never the address, name or code. The entry is shown as "Contact data
read by {plugin} (communication: {communication}, role: {role})", maps to **no** workflow event (reading contact data never starts a
workflow again), carries no amount and is therefore in neither the liabilities nor the journal. The WordPress personal
data export of the voucher lists these entries (group *Contact data read by the workflow plugin*). A refused call
(communication not handed over, no permission) writes nothing.

Never handed over: the voucher email with code and PDF, order emails, refunds, payments, bookings. Sales, refunds and redemptions are booked once, by Voucher Captain; workflow events are notifications, not sales – an affiliate plugin must not pay commission from them (WP Affiliate Captain reads orders).

## 8. Admin status

Tools → Site Health → Info → *Voucher Captain* → "Workflow plugin (provider contract)" and the settings section above show: contract version, the registered workflow plugin (version, edition, licence) and its compatibility, the communications handed over now and those that fall back to the native email, the last event id and type, the number of events delivered and of listener errors, and the last error with the history entry to replay from. `vcap()->workflow->status()` returns the same as an array (`contract_version`, `consumer`, `compatibility`, `listening`, `switched`, `handed_over`, `last_event_id`, `last_event_type`, `last_sequence`, `last_event_at`, `delivered`, `failures`, `last_error`). The counters are kept in the option `vcap_workflow_status` (not autoloaded, written once per request).

## 9. Multisite and HPOS

Each blog has its own vouchers, ledger and sequence; envelopes carry `blog_id`, and event ids include the blog, so the same ledger row id on two blogs gives two ids. Services and `replay()` work on the current blog (`switch_to_blog()` first). Order ids are WooCommerce order ids and the same with HPOS and the classic order tables.

## 10. Tests

- `tests/run.php` (section "Workflow provider contract"): envelope schema, payload allowlist and redaction (no code, token, email or name), stable and unique event ids (replay, multisite, other shop), post-commit timing (nothing on ROLLBACK), refund events (partial/full), store credit excluded, failing listener counted, consumer compatibility and the hand-over matrix (plugin missing, core/pro, licence expired, other major), service permission, idempotency (replay, conflict, freed key after a refusal) and audit.
- `tests/workflow-integration.php` (CI, WordPress + WooCommerce): the services end to end – issue, resend, void and reactivate once per key, redeem without double redemption on retry, the expiry reminder handed over once and sent natively without a compatible plugin.

## Changelog

- **1.0.0** (Voucher Captain 1.2.0): first version.
