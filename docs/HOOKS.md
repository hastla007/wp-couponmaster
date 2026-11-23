# Hook reference

Actions and filters of Voucher Captain (`vcap_*`), generated from the code by `php bin/hooks.php` – do not edit by hand.
CI checks that this file is current (`php bin/hooks.php --check`). Connector contracts (when hooks fire, transactions)
are described in CONNECTORS.md and API.md. Actions marked "after COMMIT" are buffered while a database transaction
is open and fired only once it was committed (CONNECTORS.md, "Event timing").

"Since" is the first plugin version with the hook (from the git history; 1.0.x was never released
with a public API contract, the contract starts with 1.1.0). Add-ons check the contract version instead of single hooks: `VCAP_CONTRACT_VERSION` (CONNECTORS.md, section 6).

## Actions

| Hook | Since | Where | Description |
|---|---|---|---|
| `vcap_account_voucher_actions` | 1.2.0 | `templates/myaccount/vouchers-table.php` | Extra actions of connectors (e.g. "Exchange for tickets", "Send tickets again"). |
| `vcap_account_voucher_status` | 1.2.0 | `templates/myaccount/vouchers-table.php` | Extra status lines of connectors (e.g. "2 tickets for … on …, sent to …"). |
| `vcap_account_vouchers_top` | 1.1.0 | `src/Downloads.php` | – |
| `vcap_admin_menu` | 1.2.0 | `src/Admin/Menu.php` | The plugin's admin menu is registered (arg: parent slug "vcap-vouchers"): add-ons add their submenu pages with add_submenu_page( $parent_slug, … ) here (before "Settings", which is added last). |
| `vcap_affiliate_campaign_partner_changed` | 1.1.0 | `src/Compat/AffiliateCaptain.php` | Fires after the partner of a voucher campaign was changed (for audit logs). |
| `vcap_before_fields` | 1.0.0 | `src/Fields.php` | – |
| `vcap_campaigns_table_cell` | 1.1.0 | `src/Admin/CampaignAdmin.php` | Extra cells of the campaign table (e.g. the partner of WP Affiliate Captain). |
| `vcap_campaigns_table_header` | 1.1.0 | `src/Admin/CampaignAdmin.php` | – |
| `vcap_card_moved_to_credit` | 1.2.0 | `src/CardToCredit.php` | A gift card was moved into a customer's store credit (args: card Voucher, user id, amount). |
| `vcap_form_captain_request_approved` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | A voucher request from Form Captain was approved and its vouchers issued. |
| `vcap_form_captain_request_created` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | A voucher request from Form Captain was stored (status pending). Nothing has been issued. |
| `vcap_form_captain_request_rejected` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | A voucher request from Form Captain was rejected. |
| `vcap_gift_card_topped_up` | 1.2.0 | `src/TopUp.php` | A gift card was topped up by an order (args: Voucher $card, float $amount, WC_Order $order, order item). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_loaded` | 1.2.0 | `voucher-captain.php` | Voucher Captain has booted: vcap() and its services (api_v1, redemptions …) can be used from here on. |
| `vcap_no_cache` | 1.2.0 | `src/Shortcodes.php` | Fires when a page must not be cached (hook for cache plugins without DONOTCACHEPAGE support). |
| `vcap_order_metabox_after` | 1.1.0 | `src/Admin/OrderAdmin.php` | – |
| `vcap_passcaptain_tickets_issued` | 1.1.0 | `src/Compat/PassCaptain/Exchange.php` | – |
| `vcap_product_availability_changed` | 1.2.0 | `src/ProductChecks.php` | A voucher product became purchasable or stopped being purchasable (date passed, batch empty or refilled, discount setup). Its WordPress and WooCommerce caches have been cleared; full-page caches can purge its URL here. Arguments: the product, the new unsellable reason ('' = purchasable again; date_past, date_missing, codes_empty, discount_invalid) and the previous reason ('' = was purchasable). |
| `vcap_product_settings_fields` | 1.1.0 | `src/ProductSettings.php` | Extra product fields of connectors (saved in vcap_product_settings_save). |
| `vcap_product_settings_save` | 1.1.0 | `src/ProductSettings.php` | A voucher product's settings are being saved (args: WC_Product, posted data): save the fields added in vcap_product_settings_fields. |
| `vcap_rate_limit_hit` | 1.2.0 | `src/Plugin.php` | Fires the first time a visitor exceeds the limit of a bucket in a window. |
| `vcap_redeem_form_fields` | 1.1.0 | `src/Shortcodes.php` | Extra fields of connectors (e.g. the date for a ticket voucher), posted with the code. |
| `vcap_redemption_refunded` | 1.1.0 | `src/API/V1/RedemptionService.php` | Fires once per refund of a completed external redemption. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_released` | 1.1.0 | `src/API/V1/RedemptionService.php` | Fires when a reservation was released (cancelled by the caller or expired). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_reserved` | 1.1.0 | `src/API/V1/RedemptionService.php` | Fires after a voucher was reserved for an external redemption. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_reversed` | 1.2.0 | `src/Tickets.php` | A counter redemption was cancelled (amount booked back). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_report_page` | 1.2.0 | `src/Admin/ReportsAdmin.php` | Prints the page of an add-on's report view (arg: view id from "vcap_report_tabs"), below the tabs. |
| `vcap_review_recipient_resolved` | 1.2.0 | `src/Integrations/ReviewSource.php` | A recipient was given to a review plugin (for audit logs; no personal data). |
| `vcap_review_redemption_eligible` | 1.2.0 | `src/Integrations/ReviewSource.php` | A redemption confirmed a service and may be used for a review invitation (once per reference). Payload (contract 1.x, no personal data): contract, event, event_id, reference, source, site_id, channel, experience_id (voucher product), occurred_at (UTC), reason. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_review_redemption_revoked` | 1.2.0 | `src/Integrations/ReviewSource.php` | A review source was withdrawn (once per reference): reason redeem_reversed, refunded, voided, erased (personal data of the voucher erased), deleted or no_recipient (no address left). Same payload as vcap_review_redemption_eligible with event "revoked". *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_store_credit_changed` | 1.1.0 | `src/StoreCredit.php` | The store credit of a customer changed (args: user id, amount, balance after, event, reason, order id). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_ticket_checked_in` | 1.0.0 | `src/Tickets.php` | – *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_topup_held` | 1.2.0 | `src/TopUp.php` | – |
| `vcap_topup_without_code` | 1.2.0 | `src/TopUp.php` | A top-up line without card code was held (no card issued, no top-up booked). |
| `vcap_voucher_admin_actions` | 1.1.0 | `src/Admin/VoucherAdmin.php` | Extra buttons for connectors: call $form( $do, $label, $class, $extra_fields, $confirm_text ) and handle $do in vcap_voucher_admin_action. |
| `vcap_voucher_bounced` | 1.2.0 | `src/Delivery.php` | A voucher email came back (Delivery::mark_bounced()). Args: Voucher, role ('recipient'\|'buyer'), reason. |
| `vcap_voucher_created` | 1.0.0 | `src/VoucherRepository.php` | A voucher row was inserted (args: Voucher). If its issuing fails afterwards, vcap_voucher_deleted follows. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_deleted` | 1.2.0 | `src/VoucherRepository.php` | A voucher was removed again (its issuing failed after "vcap_voucher_created"). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_delivered` | 1.0.0 | `src/Delivery.php` | A voucher was delivered to everyone it is meant for (args: Voucher $voucher, string[] $addresses). |
| `vcap_voucher_delivery_given_up` | 1.2.0 | `src/Delivery.php` | A delivery was given up after $failures failed attempts. |
| `vcap_voucher_double_use` | 1.1.0 | `src/Coupons/SingleUse.php` | – |
| `vcap_voucher_event` | 1.0.0 | `src/VoucherRepository.php` | A history entry was written (args: voucher id, event, amount, balance after, note as text, order id, source (Source::current(): admin, cron, rest, scanner, checkout, cli, web, workflow), acting user id (0 = none), the ledger row (array: id, created_at UTC, note = coded note {code, args} or null)). The last three were added later: register with 8 or 9 accepted args to get them. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_expired` | 1.2.0 | `src/VoucherRepository.php` | Vouchers whose validity ended were marked as expired (one call per batch of the daily maintenance). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_issued_standalone` | 1.2.0 | `src/Issuer.php` | A voucher was issued without an order (args: Voucher, the arguments of Issuer::issue_standalone()). |
| `vcap_voucher_redeemed` | 1.1.0 | `src/API/V1/RedemptionService.php`, `src/VoucherRepository.php` | Fires once when an external redemption is completed. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_reissued` | 1.2.0 | `src/Reissue.php` | Fires after a voucher was reissued with a new code and token (V10). The old code is no longer redeemable. |
| `vcap_voucher_request_confirmed` | 1.2.0 | `src/Integrations/VoucherRequests.php` | A manager confirmed a voucher request (vouchers were issued through the voucher service). |
| `vcap_voucher_request_expired` | 1.2.0 | `src/Integrations/VoucherRequests.php` | A voucher request was deleted by the retention rule (D10). No personal data in the arguments. |
| `vcap_voucher_request_forgotten` | 1.2.0 | `src/Integrations/VoucherRequests.php` | VC-FC-06: a request was forgotten because its form entry was deleted or its consent withdrawn. No personal data in the arguments. |
| `vcap_voucher_request_handed_over` | 1.2.0 | `src/Integrations/VoucherRequests.php` | A voucher request was handed over to a company order of an add-on (no personal data). |
| `vcap_voucher_request_received` | 1.2.0 | `src/Integrations/VoucherRequests.php` | A voucher request was stored (nothing was issued). No personal data in the arguments. |
| `vcap_voucher_request_rejected` | 1.2.0 | `src/Integrations/VoucherRequests.php` | A manager rejected (deleted) a voucher request. |
| `vcap_voucher_updated` | 1.0.0 | `src/Tickets.php`, `src/VoucherRepository.php` | A voucher was saved (args: Voucher as stored, see CONNECTORS.md "Event timing"). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_vouchers_issued` | 1.0.0 | `src/Issuer.php` | Vouchers of an order were issued (args: Voucher[] $created, WC_Order $order). |

## Filters

| Hook | Since | Where | Description |
|---|---|---|---|
| `vcap_active_integrations` | 1.2.0 | `src/Diagnostics.php` | Active integrations for the diagnostics (id => version or label), added by the integrations themselves. |
| `vcap_admin_menu_icon` | 1.2.0 | `src/Brand.php` | Icon of the main admin menu (white label), as accepted by add_menu_page(). |
| `vcap_admin_menu_title` | 1.2.0 | `src/Brand.php` | Title of the main admin menu (white label). |
| `vcap_affiliate_commission_on_voucher_sales` | 1.1.0 | `src/Compat/AffiliateCaptain.php` | – |
| `vcap_allow_refund_of_spent_top_ups` | 1.2.0 | `src/TopUp.php` | – |
| `vcap_allow_refund_of_spent_vouchers` | 1.1.0 | `src/Issuer.php` | – |
| `vcap_allow_refund_of_used_bonus` | 1.2.0 | `src/Discounts/Bonus.php` | – |
| `vcap_amount_to_base_currency` | 1.0.0 | `src/Compat/MultiCurrency.php`, `src/Coupons/CartCoupons.php`, `src/Issuer.php` | – |
| `vcap_async_issue_threshold` | 1.2.0 | `src/Issuer.php` | Vouchers still to be issued for an order above which they are created in Action Scheduler batches, so the payment request (gateway webhook, checkout) returns quickly (default 20). The order shows a note and its vouchers are delivered once all of them exist. A very high value issues everything right away. |
| `vcap_balance_covers_tax` | 1.1.0 | `src/Coupons/CartCoupons.php`, `src/Coupons/OrderCoupons.php`, `src/Coupons/Settlement.php` | – |
| `vcap_balance_deduct_statuses` | 1.0.0 | `src/Coupons/Settlement.php` | – |
| `vcap_balance_deduct_tax` | 1.0.0 | `src/Coupons/CartCoupons.php`, `src/Coupons/OrderCoupons.php` | – |
| `vcap_balance_restore_statuses` | 1.0.0 | `src/Coupons/Settlement.php` | – |
| `vcap_balance_result_parts` | 1.2.0 | `src/Downloads.php` | Parts of the [vcap_balance] result line (status, value, expiry), plain text. |
| `vcap_balance_rounding_tolerance` | 1.1.0 | `src/Coupons/Balances.php` | – |
| `vcap_bonus_code_prefix` | 1.1.0 | `src/Discounts/Bonus.php` | – |
| `vcap_bonus_coupon` | 1.1.0 | `src/Discounts/Bonus.php` | – |
| `vcap_brand_logo` | 1.2.0 | `src/Brand.php` | Logo shown next to the product name (white label); '' shows none. |
| `vcap_brand_name` | 1.2.0 | `src/Brand.php` | Product name shown to people (white label). Not used for technical identifiers. |
| `vcap_builtin_fields` | 1.0.0 | `src/Fields.php` | – |
| `vcap_bundle_pdf_limit` | 1.2.0 | `src/Delivery.php` | Most vouchers a bundled email attaches the PDF for; above it, download links only (0 = never a PDF). |
| `vcap_bundle_threshold` | 1.2.0 | `src/Delivery.php` | Vouchers of one order line and recipient from which they are bundled into one email (0 = never). |
| `vcap_checkin_undo_minutes` | 1.2.0 | `src/Tickets.php` | – |
| `vcap_claim_retention_days` | 1.2.0 | `src/Claim.php` | – |
| `vcap_client_ip` | 1.2.0 | `src/Health.php`, `src/Plugin.php` | – |
| `vcap_command_limits` | 1.3.0 | `src/API/V1/CommandLimits.php` | Lowers the limits of an automatic voucher command or switches it off (args: limits {enabled, per_day, per_recipient, max_amount_minor, amount_allowed}, command, currency of the amount). Values above the given ones, and enabled = true for a command the operator switched off, have no effect. |
| `vcap_counter_limit_applies` | 1.2.0 | `src/Counter.php` | Whether the daily counter limits apply (default: not for staff who manage vouchers). Args: bool, user id, Voucher. |
| `vcap_counter_limits` | 1.2.0 | `src/Counter.php` | Daily limits of counter redemptions for a staff member at a till (0 = unlimited), e.g. other limits per branch. Args: limits {user: {amount, count}, till: {amount, count}}, user id, till, Voucher\|null. |
| `vcap_counter_locations` | 1.2.0 | `src/Counter.php` | The managed tills/branches offered on the scanner. Args: string[] list. |
| `vcap_counter_require_receipt` | 1.2.0 | `src/Counter.php` | Whether a counter redemption needs a receipt number. Args: bool. |
| `vcap_counter_reversal_minutes` | 1.2.0 | `src/Tickets.php` | – |
| `vcap_coupon_allowed_on_voucher_products` | 1.1.0 | `src/VoucherPurchaseRules.php` | – |
| `vcap_coupon_before_save` | 1.0.0 | `src/Coupons/CouponFactory.php` | – |
| `vcap_create_coupon` | 1.0.0 | `src/Issuer.php` | – |
| `vcap_csv_format` | 1.2.0 | `src/Csv.php` | CSV format of the exports. |
| `vcap_currency_decimals` | 1.2.0 | `src/API/V1/RedemptionService.php` | Decimal places used for voucher amounts of a currency. |
| `vcap_currency_health` | 1.2.0 | `src/Health.php` | Currency conversion state for Site Health, reported by the currency integration: "unknown" = currencies without exchange rate in the last 30 days (code => time), "switcher" = name of an active currency switcher nothing converts ('' = none). |
| `vcap_currency_rate_known` | 1.2.0 | `src/Compat/MultiCurrency.php` | Whether amounts in this currency can be converted to the shop base currency (bool, currency, order). |
| `vcap_custom_amount_price` | 1.0.0 | `src/CustomAmount.php` | – |
| `vcap_customer_can_change` | 1.2.0 | `src/Downloads.php` | – |
| `vcap_customer_can_resend` | 1.1.0 | `src/Downloads.php` | – |
| `vcap_customer_daily_limit` | 1.2.0 | `src/VoucherPurchaseRules.php` | – |
| `vcap_customer_may_refund_redemption` | 1.2.0 | `src/API/V1/RestController.php` | Filters whether a customer account may refund a completed redemption through the REST API (default false). The access rules of commit (own redemption only) still apply afterwards. |
| `vcap_customer_preview_design` | 1.0.0 | `src/Preview.php` | – |
| `vcap_delivery_hold_until` | 1.2.0 | `src/Delivery.php` | Until when (Unix time, null = not held) the vouchers of a new customer's order are held. |
| `vcap_delivery_hour` | 1.0.0 | `src/Delivery.php` | – |
| `vcap_delivery_max_attempts` | 1.2.0 | `src/Delivery.php` | – |
| `vcap_delivery_min_validity_days` | 1.2.0 | `src/Delivery.php` | – |
| `vcap_delivery_retry_delay` | 1.2.0 | `src/Delivery.php` | – |
| `vcap_delivery_timestamp` | 1.0.0 | `src/Delivery.php` | – |
| `vcap_dompdf_options` | 1.0.0 | `src/PdfRenderer.php` | Dompdf options used to render voucher PDFs (args: Dompdf\\Options). |
| `vcap_dynamic_placeholder` | 1.0.0 | `src/Placeholders.php` | – |
| `vcap_email_colors` | 1.2.0 | `src/Emails/VoucherEmail.php` | Colours of the voucher emails (base, on_base, accent, muted, border), from the WooCommerce email colours. |
| `vcap_email_intro` | 1.2.0 | `src/Emails/VoucherEmail.php` | Intro text of a voucher email (e.g. a ticket voucher's reminder speaks of tickets, not of a remaining value). |
| `vcap_email_locale` | 1.2.0 | `src/Compat/Multilingual.php`, `src/Integrations/ReviewSource.php` | – |
| `vcap_event_labels` | 1.1.0 | `src/VoucherRepository.php` | Labels of connector events in the voucher history. |
| `vcap_field_definitions` | 1.0.0 | `src/Fields.php` | – |
| `vcap_field_group_applies` | 1.0.0 | `src/Fields.php` | – |
| `vcap_first_order_counting_statuses` | 1.1.0 | `src/FirstOrderCoupons.php` | – |
| `vcap_first_order_counts_undone_order` | 1.2.0 | `src/FirstOrderCoupons.php` | Whether a refunded/cancelled order counts as earlier order for "first order only" (bool, WC_Order). |
| `vcap_form_captain_available` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | Filters whether Voucher Captain accepts voucher requests from Form Captain right now. False makes the Form Captain job retry later (code `voucher_captain_unavailable`). |
| `vcap_form_captain_max_quantity` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | Most vouchers one Form Captain request may ask for. |
| `vcap_form_captain_max_value` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | Highest value of one voucher requested through Form Captain (shop base currency). |
| `vcap_hold_velocity` | 1.2.0 | `src/Delivery.php` | Maximum voucher orders of new customers per address (IPv6 /64) within the hold window before further ones are held (default 0 = no velocity limit; rate limit bucket "voucher_buy"). |
| `vcap_import_max_unzipped_bytes` | 1.2.0 | `src/SpreadsheetReader.php` | – |
| `vcap_internal_redemption_key_prefixes` | 1.1.0 | `src/API/V1/RestController.php` | Key prefixes reserved for built-in integrations (never created or driven over REST). |
| `vcap_internal_redemption_target_prefixes` | 1.2.0 | `src/API/V1/RestController.php` | Target prefixes reserved for built-in integrations (never used over REST). |
| `vcap_is_editor_preview` | 1.2.0 | `src/Shortcodes.php` | Whether shortcodes and blocks render an editor's preview (example data, no side effects). |
| `vcap_is_new_customer` | 1.2.0 | `src/VoucherPurchaseRules.php` | – |
| `vcap_is_voucher_product` | 1.0.0 | `src/ProductConfig.php` | – |
| `vcap_issue_batch_size` | 1.2.0 | `src/Issuer.php` | Vouchers created per Action Scheduler batch when an order is issued in the background (default 25). |
| `vcap_issue_requires_payment` | 1.2.0 | `src/Delivery.php`, `src/Issuer.php` | – |
| `vcap_issue_retry_delay` | 1.2.0 | `src/Issuer.php` | – |
| `vcap_job_step_seconds` | 1.2.0 | `src/Jobs.php` | Seconds one background action works on a bulk job (code generation, import) before it queues the next. |
| `vcap_keep_status_on_partial_return` | 1.2.0 | `src/OrderReturn.php` | Whether an order paid partly with vouchers keeps its status after a refund of all its money (default true). |
| `vcap_ledger_money_events` | 1.2.0 | `src/Ledger.php` | – |
| `vcap_legacy_claim_retention_days` | 1.2.0 | `src/Claim.php` | – |
| `vcap_line_snapshot` | 1.1.0 | `src/Issuer.php` | Extra snapshot keys of connectors (e.g. the ticket a voucher is exchanged for); core keys always win. |
| `vcap_maintenance_time_budget` | 1.2.0 | `src/Delivery.php` | – |
| `vcap_markets` | 1.2.0 | `src/Markets.php` | – |
| `vcap_max_attachment_bytes` | 1.2.0 | `src/Delivery.php` | Largest PDF attachment (bytes) of a voucher email; above it download links are sent instead. |
| `vcap_min_code_bits` | 1.2.0 | `src/Admin/Import.php`, `src/Campaigns.php`, `src/Issuer.php`, `src/Reissue.php` | This filter is documented in src/Issuer.php |
| `vcap_minimum_db_versions` | 1.2.0 | `src/Health.php` | Filters the oldest database server versions Voucher Captain reports as supported in Site Health. |
| `vcap_next_steps_emails` | 1.2.0 | `src/NextSteps.php` | WooCommerce order emails that show the delivery of the order's vouchers. |
| `vcap_note_text` | 1.2.0 | `src/Note.php` | Text of a coded history note (connectors can translate their own codes). |
| `vcap_offline_payment_methods` | 1.2.0 | `src/Issuer.php` | – |
| `vcap_open_order_statuses` | 1.2.0 | `src/Coupons/SingleUse.php` | – |
| `vcap_order_codes_visible` | 1.2.0 | `src/Downloads.php` | Whether the current visitor may see the full voucher codes and PDF links of an order (default: order key or own order). |
| `vcap_order_language` | 1.2.0 | `src/Compat/Multilingual.php` | Language code of an order (e.g. for another multilingual plugin); '' = the site language. |
| `vcap_order_lines` | 1.1.0 | `src/API/V1/VoucherService.php` | Filters the per-line order data handed to connector plugins. |
| `vcap_order_voucher_payments` | 1.2.0 | `src/PaymentVouchers.php` | Voucher payments after tax of an order (code => amount, order currency), e.g. for an accounting export. |
| `vcap_orphaned_hold_days` | 1.2.0 | `src/Coupons/Reservations.php` | – |
| `vcap_page_has_redeem_form` | 1.2.0 | `src/Shortcodes.php` | Whether the page shows the redeem form (e.g. true when a theme template renders it). |
| `vcap_passcaptain_can_resend_tickets` | 1.2.0 | `src/Compat/PassCaptain/Customer.php` | – |
| `vcap_passcaptain_check_fixed_date_capacity` | 1.2.0 | `src/Compat/PassCaptain/ProductSetup.php` | – |
| `vcap_passcaptain_notify_restored` | 1.2.0 | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_reconcile_orders` | 1.2.0 | `src/Compat/PassCaptainReconcile.php` | Rows of Pass Captain orders with a voucher (objects with the columns of self::COLUMNS; voucher_snapshot as JSON or array). Null uses Pass Captain's reconciliation API, or its order table on older versions. |
| `vcap_passcaptain_reservation_minutes` | 1.1.0 | `src/Compat/PassCaptainProvider.php` | – |
| `vcap_passcaptain_restore_on_revoke` | 1.1.0 | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_revoke_on_void` | 1.1.0 | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_ticket_reservation_minutes` | 1.1.0 | `src/Compat/PassCaptain/Exchange.php` | – |
| `vcap_passcaptain_ticket_target` | 1.1.0 | `src/Compat/PassCaptain/TicketContract.php` | Ticket target of a voucher (e.g. for imported or generated codes); return null for none. |
| `vcap_passcaptain_ticket_voucher_as_value` | 1.2.0 | `src/Compat/PassCaptain/ChannelRules.php` | – |
| `vcap_pdf_element_html` | 1.0.0 | `src/PdfRenderer.php` | – |
| `vcap_pdf_fallback_fonts` | 1.2.0 | `src/PdfRenderer.php` | – |
| `vcap_pdf_fonts` | 1.0.0 | `src/PdfRenderer.php` | – |
| `vcap_pdf_html` | 1.0.0 | `src/PdfRenderer.php` | HTML a voucher PDF is rendered from (args: string $html, Voucher $voucher, array $design). |
| `vcap_pdf_image_mimes` | 1.2.0 | `src/PdfRenderer.php` | – |
| `vcap_pdf_remote_hosts` | 1.2.0 | `src/PdfRenderer.php` | – |
| `vcap_pdf_title` | 1.2.0 | `src/PdfRenderer.php` | Title of a voucher PDF (args: string $title, Voucher $voucher). |
| `vcap_personal_voucher_email_verified` | 1.2.0 | `src/PersonalVoucher.php` | Whether the account email of a customer counts as verified for personal vouchers (V6). Default: the customer proved the mailbox (password reset through the emailed link, or the confirmation link) or the account is grandfathered (registered before the proof of mailbox was introduced, email unchanged since; AP-S1-1). |
| `vcap_physical_activation_statuses` | 1.2.0 | `src/PhysicalCards.php` | Order statuses at which physical gift cards are activated (default: completed). |
| `vcap_placeholder_definitions` | 1.0.0 | `src/Placeholders.php` | Placeholders offered in the PDF designer and the email texts (arg: array of token => label); fill them in vcap_placeholders. |
| `vcap_placeholders` | 1.0.0 | `src/Placeholders.php` | Values of the placeholders for a voucher (args: array of token => text, Voucher); texts are escaped when printed. |
| `vcap_product_config` | 1.0.0 | `src/ProductConfig.php` | – |
| `vcap_protect_voucher_coupons` | 1.2.0 | `src/Coupons/CouponSync.php` | Whether trashing/deleting a voucher's WooCommerce coupon is refused (default: in wp-admin and REST requests). |
| `vcap_purgeable_claim_kinds` | 1.2.0 | `src/Claim.php` | – |
| `vcap_rate_limit_backend` | 1.2.0 | `src/Plugin.php` | – |
| `vcap_rate_limit_brake` | 1.2.0 | `src/Plugin.php` | Quota per visitor while the site-wide counter is full (default: a fifth, at least 1). |
| `vcap_rate_limit_brake_exempt` | 1.2.0 | `src/Plugin.php` | Whether the current user keeps the full rate-limit quota while the site-wide brake is on. |
| `vcap_rate_limit_ipv6_48_factor` | 1.2.0 | `src/Plugin.php` | – |
| `vcap_recipient_email_locale` | 1.2.0 | `src/Emails/VoucherEmail.php` | Language of a voucher email to the gift recipient (args: locale chosen by the buyer, Voucher, email). |
| `vcap_recipient_fix_days` | 1.2.0 | `src/Downloads.php` | Days the "Correct recipient" link of a gift stays valid. |
| `vcap_recipient_fix_max` | 1.2.0 | `src/Downloads.php` | – |
| `vcap_recipient_locales` | 1.2.0 | `src/Fields.php` | Languages offered in the "Language of the recipient email" field (locale => name). |
| `vcap_redeem_code` | 1.1.0 | `src/Shortcodes.php` | Connectors may handle a code themselves (e.g. exchange it for tickets): return a result string. |
| `vcap_redeem_instructions` | 1.2.0 | `src/Channels.php` | – |
| `vcap_redeem_page_needed` | 1.2.0 | `src/Health.php` | Whether the shop needs the voucher redeem page (an integration that exchanges vouchers there, e.g. ticket vouchers, says so). Default false: then the check passes. |
| `vcap_redeem_prefill` | 1.1.0 | `src/Shortcodes.php` | – |
| `vcap_redeem_url` | 1.2.0 | `src/Shortcodes.php` | Redeem page link of a code ('' = none). |
| `vcap_redemption_allowed` | 1.1.0 | `src/API/V1/RedemptionService.php` | Filters whether a voucher may be redeemed for a target (e.g. "only course vouchers unlock courses"). Return false or a WP_Error to refuse. Runs before the voucher row is locked (D6), but may still run inside a caller's transaction: no remote calls that can take long, no own transaction (CONNECTORS.md). |
| `vcap_redemption_channel_allowed` | 1.1.0 | `src/Channels.php` | – |
| `vcap_redemption_channel_for_target` | 1.1.0 | `src/Channels.php` | – |
| `vcap_redemption_channels` | 1.1.0 | `src/Channels.php` | Redemption channels (arg: array of channel key => label): connectors add where their vouchers can be redeemed (API.md). |
| `vcap_redemption_providers` | 1.0.0 | `src/API/V1/VoucherService.php` | Filters the redemption providers available for a voucher code. |
| `vcap_redemption_site_wide_failures` | 1.2.0 | `src/API/V1/RedemptionService.php` | Invalid codes per 15 minutes across all callers; above it every caller's quota shrinks (brake). |
| `vcap_redemption_target_available` | 1.1.0 | `src/API/V1/RedemptionService.php` | Filters whether the redemption target is currently available (e.g. the course still exists). Same timing rules as vcap_redemption_allowed. |
| `vcap_referral_code_prefix` | 1.1.0 | `src/Referrals.php` | – |
| `vcap_referral_min_spend` | 1.2.0 | `src/Referrals.php` | Minimum order value of referral codes (V3). |
| `vcap_referral_same_address` | 1.2.0 | `src/Referrals.php` | – |
| `vcap_refund_guest_as_gift_card` | 1.2.0 | `src/StoreCredit.php` | Whether a guest order can be refunded as a new gift card instead of store credit (default true). |
| `vcap_render_template_id` | 1.0.0 | `src/PdfRenderer.php` | The PDF template a voucher is printed with (args: template id, Voucher), e.g. a translation of the template. |
| `vcap_report_group` | 1.1.0 | `src/Reports.php` | Group of a coupon code in the campaign report (args: array{type, name}, code, coupon id), e.g. an add-on's own source. |
| `vcap_report_statuses` | 1.1.0 | `src/Reports.php` | Order statuses (without "wc-") whose orders count in the campaign report (default: WooCommerce's paid statuses and refunded). |
| `vcap_report_tabs` | 1.2.0 | `src/Admin/ReportsAdmin.php` | Extra tabs of Vouchers → Reports (arg: array of view id => label). Add-ons print the page of their view in the action "vcap_report_page" (arg: view id); the page checks the voucher capability first. Core views cannot be renamed, removed or replaced. |
| `vcap_rest_can_access_redemption` | 1.1.0 | `src/API/V1/RestController.php` | Filters whether the current user may read/commit/release a redemption. |
| `vcap_rest_can_redeem` | 1.1.0 | `src/API/V1/RestController.php` | Filters whether the current user may redeem vouchers through the REST API. |
| `vcap_rest_redemption_target_prefixes` | 1.2.0 | `src/API/V1/RestController.php` | Target prefixes customers may redeem for over REST (connectors that let customers redeem in the browser add theirs, e.g. "learnboating:"). Default none. |
| `vcap_restore_extension_days` | 1.2.0 | `src/Coupons/BookBack.php` | – |
| `vcap_review_channels` | 1.2.0 | `src/Integrations/ReviewSource.php` | Redemption channels that confirm a service for reviews ("counter", "external"). |
| `vcap_review_external_target_prefixes` | 1.2.0 | `src/Integrations/ReviewSource.php` | Target prefixes of external redemptions that confirm a delivered service (e.g. "spa-booking:"). |
| `vcap_review_first_redemption_only` | 1.2.0 | `src/Integrations/ReviewSource.php` | Whether only the first confirmed redemption of a voucher becomes a review source ("eligible"). Further redemptions of the same voucher create no event while that one is eligible; after it was revoked (redeem_reversed, refunded) the next confirmed redemption may become eligible. False: every redemption counts on its own. |
| `vcap_review_opted_out` | 1.2.0 | `src/Integrations/ReviewSource.php` | Whether the address must not be invited (e.g. an opt-out list). An opted-out address is never returned. |
| `vcap_review_recipient_allowed` | 1.2.0 | `src/Integrations/ReviewSource.php` | Whether the current caller may resolve the recipient of a review reference. |
| `vcap_review_recipient_locale` | 1.2.0 | `src/Integrations/ReviewSource.php` | Locale of a review invitation answered by recipient() (contract 1.1.0). |
| `vcap_review_retention_months` | 1.2.0 | `src/Integrations/ReviewSource.php` | – |
| `vcap_review_revoked_retention_days` | 1.2.0 | `src/Integrations/ReviewSource.php` | – |
| `vcap_review_service_voucher` | 1.2.0 | `src/Integrations/ReviewSource.php` | Whether a redemption of this voucher confirms a service (default: its product is marked so). |
| `vcap_review_source_active` | 1.2.0 | `src/Integrations/ReviewSource.php` | Whether redemptions are recorded as review sources. Default: something listens to vcap_review_redemption_eligible. |
| `vcap_sample_voucher` | 1.0.0 | `src/Preview.php` | – |
| `vcap_sanitize_design` | 1.0.0 | `src/TemplatePostType.php` | A design after it was cleaned for saving (args: clean design, design as posted); add-on element types keep their own keys here. |
| `vcap_scanner_checkin` | 1.1.0 | `src/Tickets.php` | – |
| `vcap_scanner_describe` | 1.1.0 | `src/Tickets.php` | Connector details for the scanner (e.g. "ticket voucher – exchange first", redeemable false). |
| `vcap_scanner_lookup` | 1.1.0 | `src/Tickets.php` | Codes Voucher Captain does not know may belong to another plugin (shared scanner, e.g. Pass Captain tickets). |
| `vcap_scanner_redeem_refused` | 1.1.0 | `src/Tickets.php` | Vouchers that must not be redeemed at the counter (e.g. ticket vouchers: exchange them for tickets). |
| `vcap_scanner_show_holder` | 1.1.0 | `src/Tickets.php` | – |
| `vcap_sells_ticket_vouchers` | 1.2.0 | `src/Compat/PassCaptain/TicketContract.php` | – |
| `vcap_send_expiry_reminder` | 1.1.0 | `src/Delivery.php` | – |
| `vcap_setting_` | 1.0.0 | `src/Settings.php` | – |
| `vcap_settings_fields` | 1.0.0 | `src/Settings.php` | Fields of a section of the "Vouchers" settings tab (args: WooCommerce settings fields, section id – '' is "General"). Add-ons add fields to a core section or fill their own section from "vcap_settings_sections". |
| `vcap_settings_sections` | 1.2.0 | `src/Settings.php` | Extra sections of the "Vouchers" settings tab (arg: array of section id => label). Add-ons add their own section here and its fields with "vcap_settings_fields" (second argument: the section id); WooCommerce saves them. Core sections cannot be renamed or removed. |
| `vcap_shop_channel_refused_message` | 1.2.0 | `src/Coupons/CartCoupons.php` | Message for a voucher that is not redeemable in the shop (e.g. a ticket voucher: where to use it instead). |
| `vcap_should_issue_for_order` | 1.0.0 | `src/Issuer.php` | – |
| `vcap_site_has_redeem_form` | 1.2.0 | `src/Shortcodes.php` | Whether a site-wide template (theme file, builder layout, widget) shows the redeem form. |
| `vcap_smtp_plugin_active` | 1.2.0 | `src/Health.php` | Whether the site sends its mails through an SMTP or mail service plugin (Site Health sender check). |
| `vcap_stale_reservation_minutes` | 1.1.0 | `src/Coupons/Reservations.php`, `src/Discounts/Usage.php`, `src/FirstOrderCoupons.php` | – |
| `vcap_statutory_expiry` | 1.1.0 | `src/ProductConfig.php` | – |
| `vcap_subscription_issue_on_switch` | 1.2.0 | `src/Compat/Subscriptions.php` | Whether a subscription switch or resubscribe order issues vouchers (default false; args: order, "switch"\|"resubscribe"). |
| `vcap_suspend_on_payment_reversal` | 1.2.0 | `src/Issuer.php` | Whether vouchers of an order are suspended when its payment is reversed or held (default: "void on refund"). |
| `vcap_template_design` | 1.0.0 | `src/TemplatePostType.php` | The design (JSON layout: page, elements) of a PDF template as it is printed (args: design, template id). |
| `vcap_topup_amount` | 1.2.0 | `src/TopUp.php` | Value added to the card for a top-up line (default: its face value like a new gift card, in the base currency). |
| `vcap_topup_customer_note` | 1.2.0 | `src/TopUp.php` | Whether the customer gets an order note (email) confirming the top-up (default true). |
| `vcap_topup_expires_at` | 1.2.0 | `src/TopUp.php` | – |
| `vcap_topup_min_remaining_days` | 1.2.0 | `src/TopUp.php` | – |
| `vcap_translation_fallback_locales` | 1.2.0 | `src/I18n.php` | – |
| `vcap_voucher_admin_action` | 1.1.0 | `src/Admin/VoucherAdmin.php` | Connector actions: return the message key to show ('' = not handled). |
| `vcap_voucher_admin_messages` | 1.1.0 | `src/Admin/VoucherAdmin.php` | Extra messages for connector actions (keys ending in "_failed" are shown as errors). |
| `vcap_voucher_amount` | 1.0.0 | `src/Issuer.php` | – |
| `vcap_voucher_code` | 1.0.0 | `src/Issuer.php` | – |
| `vcap_voucher_expires_at` | 1.2.0 | `src/Issuer.php` | Expiry (UTC "Y-m-d H:i:s" or null) of a voucher being issued, e.g. no later than the event it is for. |
| `vcap_voucher_is_means_of_payment` | 1.2.0 | `src/Coupons/CartCoupons.php` | – |
| `vcap_voucher_links` | 1.2.0 | `src/Downloads.php` | Extra links of a voucher in its email and the customer's voucher list, e.g. "Add to Wallet". Args: list of array{label: string, url: string, id?: string, image?: string, width?: int, alt?: string}, Voucher, context ("email", "email_plain", "account" or "placeholder"). |
| `vcap_voucher_next_steps` | 1.2.0 | `templates/emails/plain/voucher.php`, `templates/emails/voucher.php` | Steps after receiving the voucher (e.g. "exchange it for tickets on the redeem page"); connectors add them for their voucher types. Empty: the usual "enter the code at checkout" hint. |
| `vcap_voucher_pays_after_tax` | 1.2.0 | `src/PaymentVouchers.php` | – |
| `vcap_voucher_pays_shipping_and_fees` | 1.2.0 | `src/PaymentVouchers.php` | Whether a voucher paying after tax may also pay shipping and fees (default: when it is not limited to products or categories). |
| `vcap_voucher_request_handover` | 1.2.0 | `src/Integrations/VoucherRequests.php` | Hands a voucher request over to a company order (D5, contract 1.0.0). Return `array{url: string, order_id: int}` (admin URL of the order's preview) or a WP_Error; null means no extension took it. The same reference again must answer the same order. |
| `vcap_voucher_request_handover_available` | 1.2.0 | `src/Integrations/VoucherRequests.php` | Whether an add-on takes this voucher request over as a company order (D5, contract 1.0.0). Default false: without an add-on the request view stays as it is. |
| `vcap_voucher_request_lock_wait` | 1.2.0 | `src/Integrations/VoucherRequests.php` | Seconds a state change of a voucher request waits for another one on the same request (R9). |
| `vcap_voucher_request_service_v1` | 1.3.0 | `src/Integrations/FormCaptain/RequestService.php` | The voucher request service of the core (VoucherRequests::service()), when this Voucher Captain has it. |
| `vcap_voucher_requests_max_open` | 1.2.0 | `src/Integrations/VoucherRequests.php` | Most open requests at a time (protection against floods of form entries); above it submit() answers `vcap_request_limit` (429). 0 accepts no new request. |
| `vcap_voucher_requests_retention_months` | 1.2.0 | `src/Integrations/VoucherRequests.php` | Retention of voucher requests in months (D10): `open` – open and partly issued requests (default 6), `closed` – issued and handed-over requests (default 24). 0 keeps them. Rejected requests are deleted at once. |
| `vcap_voucher_tax_treatment` | 1.1.0 | `src/ProductConfig.php` | – |
| `vcap_voucher_types` | 1.1.0 | `src/Voucher.php` | Extra voucher types of connectors and add-ons (arg: array of type key => label). Core types cannot be replaced; see API.md → Extension points. |
| `vcap_vouchers_per_item` | 1.0.0 | `src/Issuer.php` | – |
