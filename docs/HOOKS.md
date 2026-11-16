# Hook reference

Actions and filters of Voucher Captain (`vcap_*`), generated from the code by `php bin/hooks.php` – do not edit by hand.
CI checks that this file is current (`php bin/hooks.php --check`). Connector contracts (when hooks fire, transactions)
are described in CONNECTORS.md and API.md. Actions marked "after COMMIT" are buffered while a database transaction
is open and fired only once it was committed (CONNECTORS.md, "Event timing").

## Actions

| Hook | Where | Description |
|---|---|---|
| `vcap_account_voucher_actions` | `templates/myaccount/vouchers-table.php` | Extra actions of connectors (e.g. "Exchange for tickets", "Send tickets again"). |
| `vcap_account_voucher_status` | `templates/myaccount/vouchers-table.php` | Extra status lines of connectors (e.g. "2 tickets for … on …, sent to …"). |
| `vcap_account_vouchers_top` | `src/Downloads.php` | – |
| `vcap_admin_menu` | `src/Admin/Menu.php` | The plugin's admin menu is registered (arg: parent slug "vcap-vouchers"): add-ons add their submenu pages with add_submenu_page( $parent_slug, … ) here (before "Settings", which is added last). |
| `vcap_affiliate_campaign_partner_changed` | `src/Compat/AffiliateCaptain.php` | Fires after the partner of a voucher campaign was changed (for audit logs). |
| `vcap_before_fields` | `src/Fields.php` | – |
| `vcap_campaigns_table_cell` | `src/Admin/CampaignAdmin.php` | Extra cells of the campaign table (e.g. the partner of WP Affiliate Captain). |
| `vcap_campaigns_table_header` | `src/Admin/CampaignAdmin.php` | – |
| `vcap_card_moved_to_credit` | `src/CardToCredit.php` | A gift card was moved into a customer's store credit (args: card Voucher, user id, amount). |
| `vcap_gift_card_topped_up` | `src/TopUp.php` | A gift card was topped up by an order (args: Voucher $card, float $amount, WC_Order $order, order item). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_loaded` | `voucher-captain.php` | Voucher Captain has booted: vcap() and its services (api_v1, redemptions …) can be used from here on. |
| `vcap_no_cache` | `src/Shortcodes.php` | Fires when a page must not be cached (hook for cache plugins without DONOTCACHEPAGE support). |
| `vcap_order_metabox_after` | `src/Admin/OrderAdmin.php` | – |
| `vcap_passcaptain_tickets_issued` | `src/Compat/PassCaptain/Exchange.php` | – |
| `vcap_product_availability_changed` | `src/ProductChecks.php` | A voucher product became purchasable or stopped being purchasable (date passed, batch empty or refilled, discount setup). Its WordPress and WooCommerce caches have been cleared; full-page caches can purge its URL here. Arguments: the product, the new unsellable reason ('' = purchasable again; date_past, date_missing, codes_empty, discount_invalid) and the previous reason ('' = was purchasable). |
| `vcap_product_settings_fields` | `src/ProductSettings.php` | Extra product fields of connectors (saved in vcap_product_settings_save). |
| `vcap_product_settings_save` | `src/ProductSettings.php` | – |
| `vcap_rate_limit_hit` | `src/Plugin.php` | Fires the first time a visitor exceeds the limit of a bucket in a window. |
| `vcap_redeem_form_fields` | `src/Shortcodes.php` | Extra fields of connectors (e.g. the date for a ticket voucher), posted with the code. |
| `vcap_redemption_refunded` | `src/API/V1/RedemptionService.php` | Fires once per refund of a completed external redemption. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_released` | `src/API/V1/RedemptionService.php` | Fires when a reservation was released (cancelled by the caller or expired). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_reserved` | `src/API/V1/RedemptionService.php` | Fires after a voucher was reserved for an external redemption. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_redemption_reversed` | `src/Tickets.php` | A counter redemption was cancelled (amount booked back). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_store_credit_changed` | `src/StoreCredit.php` | The store credit of a customer changed (args: user id, amount, balance after, event, reason, order id). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_ticket_checked_in` | `src/Tickets.php` | – *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_topup_held` | `src/TopUp.php` | – |
| `vcap_topup_without_code` | `src/TopUp.php` | A top-up line without card code was held (no card issued, no top-up booked). |
| `vcap_voucher_admin_actions` | `src/Admin/VoucherAdmin.php` | Extra buttons for connectors: call $form( $do, $label, $class, $extra_fields, $confirm_text ) and handle $do in vcap_voucher_admin_action. |
| `vcap_voucher_bounced` | `src/Delivery.php` | A voucher email came back (Delivery::mark_bounced()). Args: Voucher, role ('recipient'\|'buyer'), reason. |
| `vcap_voucher_created` | `src/VoucherRepository.php` | A voucher row was inserted (args: Voucher). If its issuing fails afterwards, vcap_voucher_deleted follows. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_deleted` | `src/VoucherRepository.php` | A voucher was removed again (its issuing failed after "vcap_voucher_created"). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_delivered` | `src/Delivery.php` | A voucher was delivered to everyone it is meant for (args: Voucher $voucher, string[] $addresses). |
| `vcap_voucher_delivery_given_up` | `src/Delivery.php` | A delivery was given up after $failures failed attempts. |
| `vcap_voucher_double_use` | `src/Coupons/SingleUse.php` | – |
| `vcap_voucher_event` | `src/VoucherRepository.php` | A history entry was written (args: voucher id, event, amount, balance after, note as text, order id, source (Source::current(): admin, cron, rest, scanner, checkout, cli, web, workflow), acting user id (0 = none), the ledger row (array: id, created_at UTC, note = coded note {code, args} or null)). The last three were added later: register with 8 or 9 accepted args to get them. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_expired` | `src/VoucherRepository.php` | Vouchers whose validity ended were marked as expired (one call per batch of the daily maintenance). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_issued_standalone` | `src/Issuer.php` | A voucher was issued without an order (args: Voucher, the arguments of Issuer::issue_standalone()). |
| `vcap_voucher_redeemed` | `src/API/V1/RedemptionService.php`, `src/VoucherRepository.php` | Fires once when an external redemption is completed. *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_voucher_reissued` | `src/Reissue.php` | Fires after a voucher was reissued with a new code and token (V10). The old code is no longer redeemable. |
| `vcap_voucher_updated` | `src/Tickets.php`, `src/VoucherRepository.php` | A voucher was saved (args: Voucher as stored, see CONNECTORS.md "Event timing"). *Inside a transaction: after COMMIT, dropped on ROLLBACK (D6).* |
| `vcap_vouchers_issued` | `src/Issuer.php` | Vouchers of an order were issued (args: Voucher[] $created, WC_Order $order). |
| `vcap_workflow_event` | `src/Workflow/Provider.php` | Workflow event of contract v1 (WORKFLOW.md): an envelope with contract_version, provider, event_id (stable, the same on a replay), type, occurred_at (UTC), source_type, source_id (voucher id), sequence (ledger row id), blog_id, status, replayed and data (allowlisted fields, no personal data, no codes). Fired after the COMMIT of the change. |

## Filters

| Hook | Where | Description |
|---|---|---|
| `vcap_active_integrations` | `src/Diagnostics.php` | Active integrations for the diagnostics (id => version or label), added by the integrations themselves. |
| `vcap_affiliate_commission_on_voucher_sales` | `src/Compat/AffiliateCaptain.php` | – |
| `vcap_allow_refund_of_spent_top_ups` | `src/TopUp.php` | – |
| `vcap_allow_refund_of_spent_vouchers` | `src/Issuer.php` | – |
| `vcap_allow_refund_of_used_bonus` | `src/Discounts/Bonus.php` | – |
| `vcap_amount_to_base_currency` | `src/Compat/MultiCurrency.php`, `src/Coupons/CartCoupons.php`, `src/Issuer.php` | – |
| `vcap_async_issue_threshold` | `src/Issuer.php` | Vouchers still to be issued for an order above which they are created in Action Scheduler batches, so the payment request (gateway webhook, checkout) returns quickly (default 20). The order shows a note and its vouchers are delivered once all of them exist. A very high value issues everything right away. |
| `vcap_balance_covers_tax` | `src/Coupons/CartCoupons.php`, `src/Coupons/OrderCoupons.php`, `src/Coupons/Settlement.php` | – |
| `vcap_balance_deduct_statuses` | `src/Coupons/Settlement.php` | – |
| `vcap_balance_deduct_tax` | `src/Coupons/CartCoupons.php`, `src/Coupons/OrderCoupons.php` | – |
| `vcap_balance_restore_statuses` | `src/Coupons/Settlement.php` | – |
| `vcap_balance_result_parts` | `src/Downloads.php` | Parts of the [vcap_balance] result line (status, value, expiry), plain text. |
| `vcap_balance_rounding_tolerance` | `src/Coupons/Balances.php` | – |
| `vcap_bonus_code_prefix` | `src/Discounts/Bonus.php` | – |
| `vcap_bonus_coupon` | `src/Discounts/Bonus.php` | – |
| `vcap_builtin_fields` | `src/Fields.php` | – |
| `vcap_bundle_pdf_limit` | `src/Delivery.php` | Most vouchers a bundled email attaches the PDF for; above it, download links only (0 = never a PDF). |
| `vcap_bundle_threshold` | `src/Delivery.php` | Vouchers of one order line and recipient from which they are bundled into one email (0 = never). |
| `vcap_checkin_undo_minutes` | `src/Tickets.php` | – |
| `vcap_claim_retention_days` | `src/Claim.php` | – |
| `vcap_client_ip` | `src/Health.php`, `src/Plugin.php` | – |
| `vcap_counter_limit_applies` | `src/Counter.php` | Whether the daily counter limits apply (default: not for staff who manage vouchers). Args: bool, user id, Voucher. |
| `vcap_counter_limits` | `src/Counter.php` | Daily limits of counter redemptions for a staff member at a till (0 = unlimited), e.g. other limits per branch. Args: limits {user: {amount, count}, till: {amount, count}}, user id, till, Voucher\|null. |
| `vcap_counter_locations` | `src/Counter.php` | The managed tills/branches offered on the scanner. Args: string[] list. |
| `vcap_counter_require_receipt` | `src/Counter.php` | Whether a counter redemption needs a receipt number. Args: bool. |
| `vcap_counter_reversal_minutes` | `src/Tickets.php` | – |
| `vcap_coupon_allowed_on_voucher_products` | `src/VoucherPurchaseRules.php` | – |
| `vcap_coupon_before_save` | `src/Coupons/CouponFactory.php` | – |
| `vcap_create_coupon` | `src/Issuer.php` | – |
| `vcap_csv_format` | `src/Csv.php` | CSV format of the exports. |
| `vcap_currency_decimals` | `src/API/V1/RedemptionService.php` | Decimal places used for voucher amounts of a currency. |
| `vcap_currency_health` | `src/Health.php` | Currency conversion state for Site Health, reported by the currency integration: "unknown" = currencies without exchange rate in the last 30 days (code => time), "switcher" = name of an active currency switcher nothing converts ('' = none). |
| `vcap_currency_rate_known` | `src/Compat/MultiCurrency.php` | Whether amounts in this currency can be converted to the shop base currency (bool, currency, order). |
| `vcap_custom_amount_price` | `src/CustomAmount.php` | – |
| `vcap_customer_can_change` | `src/Downloads.php` | – |
| `vcap_customer_can_resend` | `src/Downloads.php` | – |
| `vcap_customer_daily_limit` | `src/VoucherPurchaseRules.php` | – |
| `vcap_customer_preview_design` | `src/Preview.php` | – |
| `vcap_delivery_hold_until` | `src/Delivery.php` | Until when (Unix time, null = not held) the vouchers of a new customer's order are held. |
| `vcap_delivery_hour` | `src/Delivery.php` | – |
| `vcap_delivery_max_attempts` | `src/Delivery.php` | – |
| `vcap_delivery_min_validity_days` | `src/Delivery.php` | – |
| `vcap_delivery_retry_delay` | `src/Delivery.php` | – |
| `vcap_delivery_timestamp` | `src/Delivery.php` | – |
| `vcap_dompdf_options` | `src/PdfRenderer.php` | Dompdf options used to render voucher PDFs (args: Dompdf\\Options). |
| `vcap_dynamic_placeholder` | `src/Placeholders.php` | – |
| `vcap_email_colors` | `src/Emails/VoucherEmail.php` | Colours of the voucher emails (base, on_base, accent, muted, border), from the WooCommerce email colours. |
| `vcap_email_intro` | `src/Emails/VoucherEmail.php` | Intro text of a voucher email (e.g. a ticket voucher's reminder speaks of tickets, not of a remaining value). |
| `vcap_email_locale` | `src/Compat/Multilingual.php` | – |
| `vcap_event_labels` | `src/VoucherRepository.php` | Labels of connector events in the voucher history. |
| `vcap_field_definitions` | `src/Fields.php` | – |
| `vcap_field_group_applies` | `src/Fields.php` | – |
| `vcap_first_order_counting_statuses` | `src/FirstOrderCoupons.php` | – |
| `vcap_first_order_counts_undone_order` | `src/FirstOrderCoupons.php` | Whether a refunded/cancelled order counts as earlier order for "first order only" (bool, WC_Order). |
| `vcap_hold_velocity` | `src/Delivery.php` | Maximum voucher orders of new customers per address (IPv6 /64) within the hold window before further ones are held (default 0 = no velocity limit; rate limit bucket "voucher_buy"). |
| `vcap_import_max_unzipped_bytes` | `src/SpreadsheetReader.php` | – |
| `vcap_internal_redemption_key_prefixes` | `src/API/V1/RestController.php` | Key prefixes reserved for built-in integrations (never created or driven over REST). |
| `vcap_internal_redemption_target_prefixes` | `src/API/V1/RestController.php` | Target prefixes reserved for built-in integrations (never used over REST). |
| `vcap_is_editor_preview` | `src/Shortcodes.php` | Whether shortcodes and blocks render an editor's preview (example data, no side effects). |
| `vcap_is_new_customer` | `src/VoucherPurchaseRules.php` | – |
| `vcap_is_voucher_product` | `src/ProductConfig.php` | – |
| `vcap_issue_batch_size` | `src/Issuer.php` | Vouchers created per Action Scheduler batch when an order is issued in the background (default 25). |
| `vcap_issue_requires_payment` | `src/Delivery.php`, `src/Issuer.php` | – |
| `vcap_issue_retry_delay` | `src/Issuer.php` | – |
| `vcap_job_step_seconds` | `src/Jobs.php` | Seconds one background action works on a bulk job (code generation, import) before it queues the next. |
| `vcap_keep_status_on_partial_return` | `src/OrderReturn.php` | Whether an order paid partly with vouchers keeps its status after a refund of all its money (default true). |
| `vcap_ledger_money_events` | `src/Ledger.php` | – |
| `vcap_legacy_claim_retention_days` | `src/Claim.php` | – |
| `vcap_line_snapshot` | `src/Issuer.php` | Extra snapshot keys of connectors (e.g. the ticket a voucher is exchanged for); core keys always win. |
| `vcap_maintenance_time_budget` | `src/Delivery.php` | – |
| `vcap_markets` | `src/Markets.php` | – |
| `vcap_max_attachment_bytes` | `src/Delivery.php` | Largest PDF attachment (bytes) of a voucher email; above it download links are sent instead. |
| `vcap_min_code_bits` | `src/Admin/Import.php`, `src/Campaigns.php`, `src/Issuer.php`, `src/Reissue.php` | This filter is documented in src/Issuer.php |
| `vcap_next_steps_emails` | `src/NextSteps.php` | WooCommerce order emails that show the delivery of the order's vouchers. |
| `vcap_note_text` | `src/Note.php` | Text of a coded history note (connectors can translate their own codes). |
| `vcap_offline_payment_methods` | `src/Issuer.php` | – |
| `vcap_open_order_statuses` | `src/Coupons/SingleUse.php` | – |
| `vcap_order_codes_visible` | `src/Downloads.php` | Whether the current visitor may see the full voucher codes and PDF links of an order (default: order key or own order). |
| `vcap_order_language` | `src/Compat/Multilingual.php` | Language code of an order (e.g. for another multilingual plugin); '' = the site language. |
| `vcap_order_lines` | `src/API/V1/VoucherService.php` | Filters the per-line order data handed to connector plugins. |
| `vcap_order_voucher_payments` | `src/PaymentVouchers.php` | Voucher payments after tax of an order (code => amount, order currency), e.g. for an accounting export. |
| `vcap_orphaned_hold_days` | `src/Coupons/Reservations.php` | – |
| `vcap_page_has_redeem_form` | `src/Shortcodes.php` | Whether the page shows the redeem form (e.g. true when a theme template renders it). |
| `vcap_passcaptain_can_resend_tickets` | `src/Compat/PassCaptain/Customer.php` | – |
| `vcap_passcaptain_check_fixed_date_capacity` | `src/Compat/PassCaptain/ProductSetup.php` | – |
| `vcap_passcaptain_notify_restored` | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_reconcile_orders` | `src/Compat/PassCaptainReconcile.php` | Rows of Pass Captain orders with a voucher (objects with the columns of self::COLUMNS; voucher_snapshot as JSON or array). Null uses Pass Captain's reconciliation API, or its order table on older versions. |
| `vcap_passcaptain_reservation_minutes` | `src/Compat/PassCaptainProvider.php` | – |
| `vcap_passcaptain_restore_on_revoke` | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_revoke_on_void` | `src/Compat/PassCaptain/Revocation.php` | – |
| `vcap_passcaptain_ticket_reservation_minutes` | `src/Compat/PassCaptain/Exchange.php` | – |
| `vcap_passcaptain_ticket_target` | `src/Compat/PassCaptain/TicketContract.php` | Ticket target of a voucher (e.g. for imported or generated codes); return null for none. |
| `vcap_passcaptain_ticket_voucher_as_value` | `src/Compat/PassCaptain/ChannelRules.php` | – |
| `vcap_pdf_element_html` | `src/PdfRenderer.php` | – |
| `vcap_pdf_fallback_fonts` | `src/PdfRenderer.php` | – |
| `vcap_pdf_fonts` | `src/PdfRenderer.php` | – |
| `vcap_pdf_html` | `src/PdfRenderer.php` | HTML a voucher PDF is rendered from (args: string $html, Voucher $voucher, array $design). |
| `vcap_pdf_image_mimes` | `src/PdfRenderer.php` | – |
| `vcap_pdf_remote_hosts` | `src/PdfRenderer.php` | – |
| `vcap_pdf_title` | `src/PdfRenderer.php` | Title of a voucher PDF (args: string $title, Voucher $voucher). |
| `vcap_personal_voucher_email_verified` | `src/PersonalVoucher.php` | Whether the account email of a customer counts as verified for personal vouchers (V6). Default: the customer proved the mailbox (password reset through the emailed link, or the confirmation link) or the account is grandfathered (registered before the proof of mailbox was introduced, email unchanged since; AP-S1-1). |
| `vcap_physical_activation_statuses` | `src/PhysicalCards.php` | Order statuses at which physical gift cards are activated (default: completed). |
| `vcap_placeholder_definitions` | `src/Placeholders.php` | – |
| `vcap_placeholders` | `src/Placeholders.php` | – |
| `vcap_product_config` | `src/ProductConfig.php` | – |
| `vcap_protect_voucher_coupons` | `src/Coupons/CouponSync.php` | Whether trashing/deleting a voucher's WooCommerce coupon is refused (default: in wp-admin and REST requests). |
| `vcap_purgeable_claim_kinds` | `src/Claim.php` | – |
| `vcap_rate_limit_backend` | `src/Plugin.php` | – |
| `vcap_rate_limit_brake` | `src/Plugin.php` | Quota per visitor while the site-wide counter is full (default: a fifth, at least 1). |
| `vcap_rate_limit_brake_exempt` | `src/Plugin.php` | Whether the current user keeps the full rate-limit quota while the site-wide brake is on. |
| `vcap_rate_limit_ipv6_48_factor` | `src/Plugin.php` | – |
| `vcap_recipient_email_locale` | `src/Emails/VoucherEmail.php` | Language of a voucher email to the gift recipient (args: locale chosen by the buyer, Voucher, email). |
| `vcap_recipient_fix_days` | `src/Downloads.php` | Days the "Correct recipient" link of a gift stays valid. |
| `vcap_recipient_fix_max` | `src/Downloads.php` | – |
| `vcap_recipient_locales` | `src/Fields.php` | Languages offered in the "Language of the recipient email" field (locale => name). |
| `vcap_redeem_code` | `src/Shortcodes.php` | Connectors may handle a code themselves (e.g. exchange it for tickets): return a result string. |
| `vcap_redeem_instructions` | `src/Channels.php` | – |
| `vcap_redeem_page_needed` | `src/Health.php` | Whether the shop needs the voucher redeem page (an integration that exchanges vouchers there, e.g. ticket vouchers, says so). Default false: then the check passes. |
| `vcap_redeem_prefill` | `src/Shortcodes.php` | – |
| `vcap_redeem_url` | `src/Shortcodes.php` | Redeem page link of a code ('' = none). |
| `vcap_redemption_allowed` | `src/API/V1/RedemptionService.php` | Filters whether a voucher may be redeemed for a target (e.g. "only course vouchers unlock courses"). Return false or a WP_Error to refuse. Runs before the voucher row is locked (D6), but may still run inside a caller's transaction: no remote calls that can take long, no own transaction (CONNECTORS.md). |
| `vcap_redemption_channel_allowed` | `src/Channels.php` | – |
| `vcap_redemption_channel_for_target` | `src/Channels.php` | – |
| `vcap_redemption_channels` | `src/Channels.php` | – |
| `vcap_redemption_providers` | `src/API/V1/VoucherService.php` | Filters the redemption providers available for a voucher code. |
| `vcap_redemption_site_wide_failures` | `src/API/V1/RedemptionService.php` | Invalid codes per 15 minutes across all callers; above it every caller's quota shrinks (brake). |
| `vcap_redemption_target_available` | `src/API/V1/RedemptionService.php` | Filters whether the redemption target is currently available (e.g. the course still exists). Same timing rules as vcap_redemption_allowed. |
| `vcap_referral_code_prefix` | `src/Referrals.php` | – |
| `vcap_referral_min_spend` | `src/Referrals.php` | Minimum order value of referral codes (V3). |
| `vcap_referral_same_address` | `src/Referrals.php` | – |
| `vcap_refund_guest_as_gift_card` | `src/StoreCredit.php` | Whether a guest order can be refunded as a new gift card instead of store credit (default true). |
| `vcap_render_template_id` | `src/PdfRenderer.php` | – |
| `vcap_report_group` | `src/Reports.php` | – |
| `vcap_report_statuses` | `src/Reports.php` | – |
| `vcap_rest_can_access_redemption` | `src/API/V1/RestController.php` | Filters whether the current user may read/commit/release a redemption. |
| `vcap_rest_can_redeem` | `src/API/V1/RestController.php` | Filters whether the current user may redeem vouchers through the REST API. |
| `vcap_rest_redemption_target_prefixes` | `src/API/V1/RestController.php` | Target prefixes customers may redeem for over REST (connectors that let customers redeem in the browser add theirs, e.g. "learnboating:"). Default none. |
| `vcap_restore_extension_days` | `src/Coupons/BookBack.php` | – |
| `vcap_sample_voucher` | `src/Preview.php` | – |
| `vcap_sanitize_design` | `src/TemplatePostType.php` | – |
| `vcap_scanner_checkin` | `src/Tickets.php` | – |
| `vcap_scanner_describe` | `src/Tickets.php` | Connector details for the scanner (e.g. "ticket voucher – exchange first", redeemable false). |
| `vcap_scanner_lookup` | `src/Tickets.php` | Codes Voucher Captain does not know may belong to another plugin (shared scanner, e.g. Pass Captain tickets). |
| `vcap_scanner_redeem_refused` | `src/Tickets.php` | Vouchers that must not be redeemed at the counter (e.g. ticket vouchers: exchange them for tickets). |
| `vcap_scanner_show_holder` | `src/Tickets.php` | – |
| `vcap_sells_ticket_vouchers` | `src/Compat/PassCaptain/TicketContract.php` | – |
| `vcap_send_expiry_reminder` | `src/Delivery.php` | – |
| `vcap_setting_` | `src/Settings.php` | – |
| `vcap_settings_fields` | `src/Settings.php` | – |
| `vcap_shop_channel_refused_message` | `src/Coupons/CartCoupons.php` | Message for a voucher that is not redeemable in the shop (e.g. a ticket voucher: where to use it instead). |
| `vcap_should_issue_for_order` | `src/Issuer.php` | – |
| `vcap_site_has_redeem_form` | `src/Shortcodes.php` | Whether a site-wide template (theme file, builder layout, widget) shows the redeem form. |
| `vcap_smtp_plugin_active` | `src/Health.php` | Whether the site sends its mails through an SMTP or mail service plugin (Site Health sender check). |
| `vcap_stale_reservation_minutes` | `src/Coupons/Reservations.php`, `src/Discounts/Usage.php` | – |
| `vcap_statutory_expiry` | `src/ProductConfig.php` | – |
| `vcap_subscription_issue_on_switch` | `src/Compat/Subscriptions.php` | Whether a subscription switch or resubscribe order issues vouchers (default false; args: order, "switch"\|"resubscribe"). |
| `vcap_suspend_on_payment_reversal` | `src/Issuer.php` | Whether vouchers of an order are suspended when its payment is reversed or held (default: "void on refund"). |
| `vcap_template_design` | `src/TemplatePostType.php` | – |
| `vcap_topup_amount` | `src/TopUp.php` | Value added to the card for a top-up line (default: its face value like a new gift card, in the base currency). |
| `vcap_topup_customer_note` | `src/TopUp.php` | Whether the customer gets an order note (email) confirming the top-up (default true). |
| `vcap_topup_expires_at` | `src/TopUp.php` | – |
| `vcap_topup_min_remaining_days` | `src/TopUp.php` | – |
| `vcap_translation_fallback_locales` | `src/I18n.php` | – |
| `vcap_voucher_admin_action` | `src/Admin/VoucherAdmin.php` | Connector actions: return the message key to show ('' = not handled). |
| `vcap_voucher_admin_messages` | `src/Admin/VoucherAdmin.php` | Extra messages for connector actions (keys ending in "_failed" are shown as errors). |
| `vcap_voucher_amount` | `src/Issuer.php` | – |
| `vcap_voucher_code` | `src/Issuer.php` | – |
| `vcap_voucher_expires_at` | `src/Issuer.php` | Expiry (UTC "Y-m-d H:i:s" or null) of a voucher being issued, e.g. no later than the event it is for. |
| `vcap_voucher_is_means_of_payment` | `src/Coupons/CartCoupons.php` | – |
| `vcap_voucher_links` | `src/Downloads.php` | Extra links of a voucher in its email and the customer's voucher list, e.g. "Add to Wallet". Args: list of array{label: string, url: string, id?: string, image?: string, width?: int, alt?: string}, Voucher, context ("email", "email_plain", "account" or "placeholder"). |
| `vcap_voucher_next_steps` | `templates/emails/plain/voucher.php`, `templates/emails/voucher.php` | Steps after receiving the voucher (e.g. "exchange it for tickets on the redeem page"); connectors add them for their voucher types. Empty: the usual "enter the code at checkout" hint. |
| `vcap_voucher_pays_after_tax` | `src/PaymentVouchers.php` | – |
| `vcap_voucher_pays_shipping_and_fees` | `src/PaymentVouchers.php` | Whether a voucher paying after tax may also pay shipping and fees (default: when it is not limited to products or categories). |
| `vcap_voucher_tax_treatment` | `src/ProductConfig.php` | – |
| `vcap_voucher_types` | `src/Voucher.php` | – |
| `vcap_vouchers_per_item` | `src/Issuer.php` | – |
| `vcap_workflow_consumer` | `src/Workflow/Provider.php` | A workflow plugin registers itself for the provider contract: return an array with id, name, version, contract (the contract version it was built for, e.g. "1.0.0"), edition (core\|pro), entitlement (active\|expired\|missing) and communications (those it handles now, see WORKFLOW.md). Null = none. |
| `vcap_workflow_service_allowed` | `src/Workflow/Services.php` | Whether the current user may call a workflow service (issue, resend, void, reactivate, redeem, contact). Default: one of the capabilities in Workflow\Services::CAPS. Args: bool, service, the call's arguments. |
