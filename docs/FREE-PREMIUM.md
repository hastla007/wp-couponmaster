# Freier Kern und getrenntes Premium-Add-on

Entscheidung des Betreibers vom 13.11.2026 (verbindlich). Dieses Dokument ersetzt das frühere LICENSING.md.

## Regeln

1. **Der Kern ist frei.** Voucher Captain steht unter GPL-2.0-or-later und wird über WordPress.org verteilt. Jede
   Funktion, die heute im Code steht, gehört zum Kern und bleibt dauerhaft frei – auch Aktionen, Freunde werben,
   Aufladung, Auswertungen und die WP-Pass-Captain-Integration. Im Kern ist nichts gesperrt, abgestuft oder „in
   Premium enthalten“.
2. **Kein Lizenz- oder Update-Code im Kern.** Kein Lizenz-Client, keine Aufrufe von `wplc/v1`, kein `Update URI`-Header,
   keine eigenen Filter auf `pre_set_site_transient_update_plugins` oder `plugins_api`, keine Berechtigungsprüfungen
   (`allows_entitlement` o. Ä.), keine Lizenzseite und kein Lizenzmenü, keine Upgrade-Hinweise („Upgrade to Premium“),
   keine Konstanten `VCAP_EDITION`/`VCAP_LICENSE_*`, keine Aufteilung in Free- und Premium-Build. Es gibt **einen** Build
   (`bin/build.sh` → `build/voucher-captain.zip`); Updates kommen von WordPress.org.
3. **WordPress.org-Richtlinie 11:** keine gesperrten Bedienelemente. Erlaubt wäre höchstens ein ausblendbarer Hinweis
   auf den eigenen Admin-Seiten; der Kern verzichtet ganz darauf.
4. **Bestandsdaten.** Ein Update räumt einmal je Site auf, was Vorabversionen mit Lizenzcode hinterlassen haben
   (`VCAP\Install::remove_license_data()`, Merker `vcap_license_data_removed`): die Optionen `vcap_wplc_*` (auch als
   Netzwerk-Optionen), das WP-Cron-Ereignis und die Action-Scheduler-Aktion `vcap_license_refresh` sowie den
   Update-Cache `vcap_update_check`. `uninstall.php` entfernt dasselbe bei jeder Deinstallation, auch wenn die Daten
   behalten werden. Gutscheindaten werden dabei nicht angefasst.
5. **Premium ist ein getrenntes Add-on** (eigenes Plugin, eigenes Repository, eigener Slug). Es nutzt ausschließlich
   die öffentlichen Erweiterungspunkte unten und bringt seinen Lizenz- und Update-Code selbst mit. Der frühere
   Lizenzcode des Kerns liegt dafür im Branch `addon-source/licensing-from-youthful-bardeen`. Ein Add-on darf keine
   Kernfunktion nachträglich sperren.
6. **Absicherung:** `tests/license-guard.php` (CI-Job `smoke`) durchsucht die ausgelieferten Dateien
   (`voucher-captain.php`, `uninstall.php`, `readme.txt`, `src/`, `templates/`, `assets/` ohne `assets/vendor/`,
   `examples/`, `config/`) nach Lizenz- und Update-Code und schlägt mit Datei:Zeile fehl. `bin/build.sh` verweigert
   einen Plugin-Kopf mit `Update URI` oder Editions-/Lizenzkonstanten.

## Funktionsumfang „Kern, dauerhaft frei“

| Bereich | Umfang |
|---|---|
| Verkauf & Ausstellung | Einfache und variable Produkte als Gutschein, ein WooCommerce-Coupon je Stück, Code-Format, Ausstellung je Bestellstatus oder manuell, idempotent |
| Wert & Einlösung | Produktpreis, fixer Wert oder Wunschbetrag; Prozent-/Fixrabatt; einmalig, Geschenkkarte mit Restwert oder wiederverwendbar; Ablauf, Einschränkungen; Guthaben-Reservierung, Verbuchung und Rückbuchung |
| Personalisierung | Empfänger, Nachricht, Wunsch-Zustelldatum, eigene Feldgruppen |
| Zustellung | Sofort, verzögert, festes Datum oder Kundenwunsch über Action Scheduler; eigene E-Mails mit PDF-Anhang |
| PDF-Designer | Mehrere Vorlagen und Formate, Drag & Drop, Ebenen, QR-Code, Barcode, Platzhalter, Vorschau |
| Downloads | Bestellbestätigung, Mein Konto, E-Mails, signierte Gast-Links |
| Verwaltung | Gutscheinliste, Detailansicht, Guthabenkorrektur, erneuter Versand, Stornieren, Verlauf; Shortcodes und Blöcke |
| Kundenguthaben | Guthabenkonto je Kunde, Erstattung als Guthaben mit Zustimmung |
| Scanner & Pässe | QR-Prüfung und Einlösung, Kassiererrolle, Storno einer Schaltereinlösung |
| Code-Import & Code-Kampagnen | CSV/XLS/XLSX/ODS, Batches, Duplikaterkennung, Kampagnen mit Partnern |
| Einlöse-API & Connectoren | `vcap()->api_v1`, `vcap()->redemptions`, REST `vcap/v1`, Kanäle, eigene Gutscheintypen |
| Steuer & Recht | Steuerbehandlung je Gutscheinart, Mehrzweckgutscheine als Zahlung, gesetzliche Gültigkeit je Land |
| Berichte | Offener Gutscheinwert zum Stichtag, Einlösejournal (CSV), Pass-Captain-Abgleich, **Auswertung nach Kampagnen und Partnern** (früher Premium) |
| **Aktionen und Bonusgutscheine** (früher Premium) | Regelbasierte Rabatte im Warenkorb, Bonusgutscheine, Nutzungsgrenzen |
| **Freunde werben** (früher Premium) | Persönlicher Empfehlungscode, Prämien, Rücknahme bei Storno, `[vcap_referral]` |
| **Aufladung von Geschenkkarten** (früher Premium) | Aufladeprodukt bucht den Wert auf eine bestehende Karte, Rückbuchung bei Erstattung |
| **WP-Pass-Captain-Integration** (früher Premium) | Ticketgutscheine, gemeinsamer Scanner; dazu wie bisher Bezahlen mit Gutscheinen in der Pass-Captain-Kasse |
| Kompatibilität | WooCommerce Subscriptions, WPML/WCML, Multi-Currency (WCML, Aelia, WOOCS, CURCY), HPOS, Block-Warenkorb/-Kasse, Multisite |
| Sicherheit & Datenschutz | Capabilities, Nonces, signierte Links, Datenexport/-löschung, Anonymisierung, Rate-Limit |

## Öffentliche Erweiterungspunkte für das Add-on

Ein Add-on registriert sich in der Action `vcap_loaded` (Argument: das laufende `VCAP\Plugin`) und nutzt nur die
öffentliche API: `vcap()->api_v1`, `vcap()->redemptions`, den REST-Namespace `vcap/v1`, die Schnittstellen
`VCAP\Contracts\*`, die dokumentierte Order-/Coupon-Meta und die Hooks mit Präfix `vcap_` (API.md, CONNECTORS.md).

**Versionierung** (CONNECTORS.md → 6. API versioning policy): Innerhalb von v1 nur additive Änderungen (neue Methoden,
optionale Argumente, Array-Schlüssel, Hooks, Fehlercodes); bestehende Schlüssel, Fehlercodes und Hook-Argumente
behalten ihre Bedeutung. Brechende Änderungen bekommen eine neue Version daneben (`api_v2`, `vcap/v2`); die alte
bleibt mindestens zwei Minor-Releases und sechs Monate mit Deprecation-Hinweis erhalten. Alles andere (Klassen ohne
dokumentierten Vertrag, Tabellen, private Meta) ist intern – ein Add-on fragt nie die Tabellen von Voucher Captain
direkt ab.

Besonders nützlich für ein Add-on: `vcap_settings_fields` (eigene Einstellungen im Tab *Gutscheine*),
`vcap_product_settings_fields`/`vcap_product_settings_save` (Produktoptionen), `vcap_voucher_types` und
`vcap_redemption_channels` (eigene Gutscheinarten und Kanäle), `vcap_redemption_allowed` (eigene Einlöseregeln),
`vcap_placeholder_definitions`/`vcap_placeholders` (PDF-Platzhalter), `vcap_report_group` (Berichte),
`vcap_voucher_admin_actions`/`vcap_voucher_admin_action` (Aktionen in der Gutscheinansicht) und die Ereignisse
`vcap_vouchers_issued`, `vcap_voucher_redeemed`, `vcap_voucher_event`.

Vollständige Liste der vorhandenen Hooks (Stand dieses Commits; erzeugt aus `apply_filters( 'vcap_…' )` und
`do_action( 'vcap_…' )` in `src/`, `templates/` und `voucher-captain.php`):

| Hook | Art | Stelle (`src/` bzw. `templates/`) |
|---|---|---|
| `vcap_account_voucher_actions` | Action | `templates/myaccount/vouchers-table` |
| `vcap_account_voucher_status` | Action | `templates/myaccount/vouchers-table` |
| `vcap_account_vouchers_top` | Action | `Downloads` |
| `vcap_affiliate_campaign_partner_changed` | Action | `Compat/AffiliateCaptain` |
| `vcap_affiliate_commission_on_voucher_sales` | Filter | `Compat/AffiliateCaptain` |
| `vcap_allow_refund_of_spent_top_ups` | Filter | `TopUp` |
| `vcap_allow_refund_of_spent_vouchers` | Filter | `Issuer` |
| `vcap_amount_to_base_currency` | Filter | `Issuer`, `CouponManager`, `Compat/MultiCurrency` |
| `vcap_balance_covers_tax` | Filter | `CouponManager` |
| `vcap_balance_deduct_statuses` | Filter | `CouponManager` |
| `vcap_balance_deduct_tax` | Filter | `CouponManager` |
| `vcap_balance_restore_statuses` | Filter | `CouponManager` |
| `vcap_balance_result_parts` | Filter | `Downloads` |
| `vcap_balance_rounding_tolerance` | Filter | `CouponManager` |
| `vcap_before_fields` | Action | `Fields` |
| `vcap_bonus_code_prefix` | Filter | `Discounts/Bonus` |
| `vcap_bonus_coupon` | Filter | `Discounts/Bonus` |
| `vcap_builtin_fields` | Filter | `Fields` |
| `vcap_campaigns_table_cell` | Action | `Admin/CampaignAdmin` |
| `vcap_campaigns_table_header` | Action | `Admin/CampaignAdmin` |
| `vcap_checkin_undo_minutes` | Filter | `Tickets` |
| `vcap_claim_retention_days` | Filter | `Claim` |
| `vcap_client_ip` | Filter | `Plugin` |
| `vcap_counter_reversal_minutes` | Filter | `Tickets` |
| `vcap_coupon_allowed_on_voucher_products` | Filter | `VoucherPurchaseRules` |
| `vcap_coupon_before_save` | Filter | `CouponManager` |
| `vcap_create_coupon` | Filter | `Issuer` |
| `vcap_csv_format` | Filter | `Csv` |
| `vcap_currency_decimals` | Filter | `API/V1/RedemptionService` |
| `vcap_currency_rate_known` | Filter | `Compat/MultiCurrency` |
| `vcap_custom_amount_price` | Filter | `CustomAmount` |
| `vcap_customer_can_resend` | Filter | `Downloads` |
| `vcap_customer_preview_design` | Filter | `Preview` |
| `vcap_delivery_hour` | Filter | `Delivery` |
| `vcap_delivery_max_attempts` | Filter | `Delivery` |
| `vcap_delivery_min_validity_days` | Filter | `Delivery` |
| `vcap_delivery_retry_delay` | Filter | `Delivery` |
| `vcap_delivery_timestamp` | Filter | `Delivery` |
| `vcap_dompdf_options` | Filter | `PdfRenderer` |
| `vcap_dynamic_placeholder` | Filter | `Placeholders` |
| `vcap_email_intro` | Filter | `Emails/VoucherEmail` |
| `vcap_email_locale` | Filter | `Compat/Multilingual` |
| `vcap_event_labels` | Filter | `VoucherRepository` |
| `vcap_field_definitions` | Filter | `Fields` |
| `vcap_field_group_applies` | Filter | `Fields` |
| `vcap_first_order_counting_statuses` | Filter | `FirstOrderCoupons` |
| `vcap_gift_card_topped_up` | Action | `TopUp` |
| `vcap_import_max_unzipped_bytes` | Filter | `SpreadsheetReader` |
| `vcap_internal_redemption_key_prefixes` | Filter | `API/V1/RestController` |
| `vcap_internal_redemption_target_prefixes` | Filter | `API/V1/RestController` |
| `vcap_is_editor_preview` | Filter | `Shortcodes` |
| `vcap_is_voucher_product` | Filter | `ProductConfig` |
| `vcap_issue_requires_payment` | Filter | `Issuer` |
| `vcap_ledger_money_events` | Filter | `Ledger` |
| `vcap_line_snapshot` | Filter | `Issuer` |
| `vcap_loaded` | Action | `voucher-captain` |
| `vcap_maintenance_time_budget` | Filter | `Delivery` |
| `vcap_markets` | Filter | `Markets` |
| `vcap_min_code_bits` | Filter | `Issuer`, `Admin/Import`, `Campaigns` |
| `vcap_next_steps_emails` | Filter | `NextSteps` |
| `vcap_no_cache` | Action | `Shortcodes` |
| `vcap_offline_payment_methods` | Filter | `Issuer` |
| `vcap_open_order_statuses` | Filter | `CouponManager` |
| `vcap_order_codes_visible` | Filter | `Downloads` |
| `vcap_order_language` | Filter | `Compat/Multilingual` |
| `vcap_order_lines` | Filter | `API/V1/VoucherService` |
| `vcap_order_metabox_after` | Action | `Admin/OrderAdmin` |
| `vcap_page_has_redeem_form` | Filter | `Shortcodes` |
| `vcap_passcaptain_can_resend_tickets` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_check_fixed_date_capacity` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_notify_restored` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_reconcile_orders` | Filter | `Compat/PassCaptainReconcile` |
| `vcap_passcaptain_reservation_minutes` | Filter | `Compat/PassCaptainProvider` |
| `vcap_passcaptain_restore_on_revoke` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_revoke_on_void` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_ticket_reservation_minutes` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_ticket_target` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_ticket_voucher_as_value` | Filter | `Compat/PassCaptainTickets` |
| `vcap_passcaptain_tickets_issued` | Action | `Compat/PassCaptainTickets` |
| `vcap_pdf_element_html` | Filter | `PdfRenderer` |
| `vcap_pdf_fonts` | Filter | `PdfRenderer` |
| `vcap_pdf_html` | Filter | `PdfRenderer` |
| `vcap_placeholder_definitions` | Filter | `Placeholders` |
| `vcap_placeholders` | Filter | `Placeholders` |
| `vcap_product_config` | Filter | `ProductConfig` |
| `vcap_product_settings_fields` | Action | `ProductSettings` |
| `vcap_product_settings_save` | Action | `ProductSettings` |
| `vcap_purgeable_claim_kinds` | Filter | `Claim` |
| `vcap_rate_limit_brake_exempt` | Filter | `Plugin` |
| `vcap_rate_limit_brake` | Filter | `Plugin` |
| `vcap_redeem_code` | Filter | `Shortcodes` |
| `vcap_redeem_form_fields` | Action | `Shortcodes` |
| `vcap_redeem_prefill` | Filter | `Shortcodes` |
| `vcap_redeem_url` | Filter | `Shortcodes` |
| `vcap_redemption_allowed` | Filter | `API/V1/RedemptionService` |
| `vcap_redemption_channel_allowed` | Filter | `Channels` |
| `vcap_redemption_channel_for_target` | Filter | `Channels` |
| `vcap_redemption_channels` | Filter | `Channels` |
| `vcap_redemption_refunded` | Action | `API/V1/RedemptionService` |
| `vcap_redemption_released` | Action | `API/V1/RedemptionService` |
| `vcap_redemption_reserved` | Action | `API/V1/RedemptionService` |
| `vcap_redemption_reversed` | Action | `Tickets` |
| `vcap_redemption_site_wide_failures` | Filter | `API/V1/RedemptionService` |
| `vcap_redemption_target_available` | Filter | `API/V1/RedemptionService` |
| `vcap_referral_code_prefix` | Filter | `Referrals` |
| `vcap_render_template_id` | Filter | `PdfRenderer` |
| `vcap_report_group` | Filter | `Reports` |
| `vcap_report_statuses` | Filter | `Reports` |
| `vcap_rest_can_access_redemption` | Filter | `API/V1/RestController` |
| `vcap_rest_can_redeem` | Filter | `API/V1/RestController` |
| `vcap_rest_redemption_target_prefixes` | Filter | `API/V1/RestController` |
| `vcap_restore_extension_days` | Filter | `CouponManager` |
| `vcap_sample_voucher` | Filter | `Preview` |
| `vcap_scanner_checkin` | Filter | `Tickets` |
| `vcap_scanner_describe` | Filter | `Tickets` |
| `vcap_scanner_lookup` | Filter | `Tickets` |
| `vcap_scanner_redeem_refused` | Filter | `Tickets` |
| `vcap_scanner_show_holder` | Filter | `Tickets` |
| `vcap_sells_ticket_vouchers` | Filter | `Compat/PassCaptainTickets` |
| `vcap_send_expiry_reminder` | Filter | `Delivery` |
| `vcap_setting_{id}` | Filter | `Settings` |
| `vcap_settings_fields` | Filter | `Settings` |
| `vcap_shop_channel_refused_message` | Filter | `CouponManager` |
| `vcap_should_issue_for_order` | Filter | `Issuer` |
| `vcap_site_has_redeem_form` | Filter | `Shortcodes` |
| `vcap_stale_reservation_minutes` | Filter | `CouponManager`, `Discounts/Usage` |
| `vcap_statutory_expiry` | Filter | `ProductConfig` |
| `vcap_store_credit_changed` | Action | `StoreCredit` |
| `vcap_template_design` | Filter | `TemplatePostType` |
| `vcap_ticket_checked_in` | Action | `Tickets` |
| `vcap_topup_amount` | Filter | `TopUp` |
| `vcap_topup_customer_note` | Filter | `TopUp` |
| `vcap_topup_expires_at` | Filter | `TopUp` |
| `vcap_topup_held` | Action | `TopUp` |
| `vcap_topup_min_remaining_days` | Filter | `TopUp` |
| `vcap_topup_without_code` | Action | `TopUp` |
| `vcap_translation_fallback_locales` | Filter | `I18n` |
| `vcap_voucher_admin_actions` | Action | `Admin/VoucherAdmin` |
| `vcap_voucher_admin_action` | Filter | `Admin/VoucherAdmin` |
| `vcap_voucher_admin_messages` | Filter | `Admin/VoucherAdmin` |
| `vcap_voucher_amount` | Filter | `Issuer` |
| `vcap_voucher_code` | Filter | `Issuer` |
| `vcap_voucher_created` | Action | `VoucherRepository` |
| `vcap_voucher_delivered` | Action | `Delivery` |
| `vcap_voucher_delivery_given_up` | Action | `Delivery` |
| `vcap_voucher_double_use` | Action | `CouponManager` |
| `vcap_voucher_event` | Action | `VoucherRepository` |
| `vcap_voucher_expires_at` | Filter | `Issuer` |
| `vcap_voucher_next_steps` | Filter | `templates/emails/voucher`, `templates/emails/plain/voucher` |
| `vcap_voucher_pays_after_tax` | Filter | `PaymentVouchers` |
| `vcap_voucher_pays_shipping_and_fees` | Filter | `PaymentVouchers` |
| `vcap_voucher_redeemed` | Action | `API/V1/RedemptionService` |
| `vcap_voucher_tax_treatment` | Filter | `ProductConfig` |
| `vcap_voucher_types` | Filter | `Voucher` |
| `vcap_voucher_updated` | Action | `VoucherRepository`, `Tickets` |
| `vcap_vouchers_issued` | Action | `Issuer` |
| `vcap_vouchers_per_item` | Filter | `Issuer` |
