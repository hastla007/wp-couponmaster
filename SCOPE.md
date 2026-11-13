# Product scope: Voucher Captain

## Goal

Build an original WooCommerce plugin for gift vouchers, gift cards, stored-value balances, discount coupons, personalisation, PDF delivery, and voucher redemption. It can issue voucher-like service passes, but it is not the event catalogue, seat inventory, checkout, or event-entry system. Use original naming, code, UI, and visual assets; Flexible PDF Coupons is a functional reference, not a code or brand copy.


## Boundary with WP Pass Captain (formerly called "Ticket Captain")

Voucher Captain and WP Pass Captain are separate plugins with separate WordPress plugin entries, activation, updates, admin menus, and data ownership. When both are active, a versioned bridge connects their functions; neither plugin requires the other to operate.

- Voucher Captain owns voucher codes, discount rules, gift-card balances, voucher delivery, voucher-level redemption, and voucher history.
- WP Pass Captain owns event/occurrence data, ticket categories and capacities, event orders, seat assignments, event admission tickets, check-in, and ticket-specific refunds/status.
- A Voucher Captain ticket voucher is exchanged for one or more WP Pass Captain tickets (implemented). WP Pass Captain uses a Voucher Captain code in its checkout only through reserve/commit/release/refund services (implemented, API.md).
- Voucher Captain's scanner remains available for its own voucher/pass operations. Event admission uses WP Pass Captain's ticket status and check-in records. The shared scanner (implemented: Vouchers → Scanner) looks up voucher codes first and checks WP Pass Captain tickets in at Pass Captain; it never confuses voucher redemption with event check-in.
- Customer-specific course access remains in the separate Bootsschule/Learnboating connector.

## Feature inventory

### Core selling and issuance
- Mark simple and variable WooCommerce products/variations as voucher products.
- Issue one WooCommerce coupon per purchased item, with configurable prefix, suffix, random code length, and optional order reference.
- Configure coupon amount, expiry, free shipping, product/category restrictions, and one-time or balance-tracked multi-use redemption.
- Generate vouchers automatically for selected order statuses or manually from order administration.
- Show downloadable PDFs in the order confirmation and My Account order details.
- Support guest purchases and retain secure, order-scoped download links.

### Product-page personalization and delivery
- Optional recipient name, recipient email, message, buyer-defined delivery date, and merchant-defined custom fields.
- Field groups assignable globally or to selected products, categories, and tags; configurable required state, character limits, tooltips, and position around Add to Cart.
- Send to buyer or recipient, optionally suppressing recipient notices; attach PDF to emails.
- Delivery modes: immediate, relative delay, fixed date, or customer-selected date, scheduled reliably with Action Scheduler.
- Configurable subject, body, template, and additional recipients.

### PDF template designer
- Multiple reusable templates with A4/A5/A6 and custom dimensions, portrait/landscape, background color/image, layers, alignment guides, and image rotation.
- Drag-and-drop placement of text, headings, images, coupon QR/barcode elements, and dynamic placeholders.
- Typography controls: font, size, color, alignment, bold, italic.
- Built-in placeholders for coupon code/value, product, expiry, buyer/recipient details, message, order, and site; extensible custom placeholders.
- Preview before purchase and optional customer preview of their personalized voucher.

### Management and extensions
- Admin list/search/filter of issued vouchers, balances, expiry, and redemption state.
- QR ticket generation and mobile/desktop scanning with check-in state changes.
- Import pre-generated coupon codes from CSV/XLS/XLSX/ODS, with validation and duplicate handling.
- Multiple PDFs/tickets per line item/order.
- Compatibility targets: WooCommerce Subscriptions, WPML, multi-currency, Flexible Product Fields, and Flexible Checkout Fields.
- Translation-ready strings, accessibility, privacy-conscious retention, capability checks, nonces, escaping, and audit-friendly redemption records.

## Delivery stages

1. **Foundation:** plugin bootstrap, WooCommerce compatibility, product/variation voucher settings, data model, settings UI, and migration/versioning strategy.
2. **Issuance:** robust coupon creation tied idempotently to order items/statuses, manual issue action, and coupon restrictions/expiry.
3. **PDF delivery:** secure PDF generation, initial template and placeholders, email attachment/link, order and My Account download.
4. **Designer:** reusable templates and visual editor with preview, layers, image assets, and layout controls.
5. **Personalization:** recipient/custom fields, field assignment, buyer/recipient routing, custom email content, and scheduled delivery.
6. **Advanced redemption:** stored balance/multi-use vouchers, QR ticket scanner, bulk code import, and multi-PDF output.
7. **Compatibility and release:** subscriptions/multilingual/multicurrency integrations, accessibility/security review, documentation, translations, and packaging.

## Architecture choices

- Keep WooCommerce coupon records as the source of truth for coupon restrictions; use plugin-owned metadata for voucher lifecycle, delivery, template selection, and redemption history.
- Store issued voucher records separately from templates so refunds, retries, and partial redemptions are auditable.
- Make order processing idempotent and queue long-running PDF/email/scheduled delivery work through Action Scheduler.
- Use a maintained PDF library with a clear Composer/autoload strategy; keep HTML/template rendering separate from coupon lifecycle logic.
- Treat imported codes, QR tokens, download URLs, and recipient data as untrusted or sensitive input; use capabilities, nonces, validation, escaping, and expiring signed links.

## Implementation decisions

| Question | Status |
| --- | --- |
| PDF engine and minimum versions | Decided: dompdf (bundled, scoped where needed); WordPress 6.4, WooCommerce 8.9, PHP 8.1 (plugin header, checked on load, CI job `minimum-versions`). |
| Custom denominations | Decided: native custom amount per product (minimum, maximum, step, suggested amounts). |
| Commercial licensing and update delivery | Implemented: free and premium edition from one code base; the premium edition gates its features fail-closed through WP License Captain (signed entitlements, 14-day offline cache) and updates from the license server's release storage with SHA-256 checks (LICENSING.md, RELEASE.md). **Open: the product owner still has to confirm which features are premium** (`config/premium-features.php`) and how the free edition is distributed and updated. |
| Ticket check-in workflows | Decided: Voucher Captain redeems its own vouchers and service passes (single use or balance); event tickets are checked in by WP Pass Captain, reached through the shared scanner. Partial admission belongs to WP Pass Captain. |

## Optional feature expansions

These features extend the core voucher lifecycle. They are product options, not prerequisites for the first usable release. They must remain configurable and must not introduce surprising automatic discounts.

### Promotion and coupon automation

- Define rule-based campaigns with conditions such as first purchase, next order, minimum spend, eligible products/categories, customer group, date window, and purchase quantity.
- Support fixed discounts, percentage discounts, tiered thresholds, and buy-X-get-Y campaigns where WooCommerce can represent the resulting order discount reliably.
- Allow an eligible code to be applied from a signed or validated URL parameter. Offer automatic application only when the merchant explicitly enables it.
- Provide explicit priority, exclusions, stacking rules, per-customer limits, total limits, and a clear preview of which rule wins when multiple campaigns match.
- Keep discounts visible in cart/checkout line calculations. Do not create artificial catalog strike-through prices.

### Customer store credit

- Optionally maintain customer-linked credit balances with an auditable ledger, manual grants/adjustments, partial use, and clear order/refund references.
- Show available credit in My Account and allow it to be applied at checkout. Define whether credit can be used with other coupons and whether it is applied before or after tax according to the WooCommerce integration and merchant configuration.
- Offer refund-to-credit as an explicit refund choice; never silently replace a cash refund with store credit.
- Specify multi-currency behavior: keep balances currency-specific by default unless a supported currency integration provides a safe conversion policy.

### Lifecycle campaigns and product integrations

- Add configurable expiry reminders for unused vouchers and customer-controlled resend options, with delivery status and suppression controls.
- Optionally issue a bonus voucher with a qualifying purchase or gift-card purchase.
- Support customer-selected fixed denominations and custom amounts where the product-price integration permits; preserve tax and order-item values.
- Offer reusable shortcodes/blocks for voucher balances, redemption links, and voucher details, plus optional customer preview before purchase.
- Expand compatibility through documented adapters for multilingual, multi-currency, subscription, and product-field extensions rather than hard-coding their internal APIs.

### Expansion order

1. Coupon URL application, expiry reminders, and campaign reporting.
2. Customer store-credit wallet and explicit refund-to-credit flow.
3. Rule-based automatic promotions, bonus vouchers, and broader shortcode/block integrations.

Each expansion needs acceptance criteria for limits, stacking, refunds, currencies, privacy retention, and order idempotency before implementation.

## EU target market and compatibility baseline

**Target market:** European Union, with country-specific configuration rather than DACH-only assumptions.

### Test baseline (snapshot: 11 November 2026)

- WordPress 7.1.4.
- WooCommerce 11.2.2.
- PHP 8.4, with current security patch level (8.4.27 at the snapshot date).
- MySQL 8.4 LTS or MariaDB 11.4 LTS.
- Yoast SEO 28.8 is an optional compatibility-test component, not a dependency. Voucher Captain must also work when Yoast SEO is absent.

These are release-test targets, not permanently fixed minimum versions. Publish and review minimum supported WordPress, WooCommerce, PHP, and database versions separately for each plugin release. Keep CI forward-compatibility coverage for the next supported PHP/WordPress releases where available.

### EU product requirements

- Model VAT treatment, country, currency, tax-inclusive prices, refunds, and invoice references at order-line level; support country-specific configuration and OSS-related workflows without embedding tax rates in code.
- Make voucher terms, expiry, delivery, redemption, and customer notices configurable and translatable; obtain country-specific legal review before marketing legal compliance.
- Make multilingual and multi-currency integrations optional adapters. Do not assume every EU customer uses EUR or one checkout language.
- Include privacy export/deletion and retention controls for buyer and recipient records; keep required financial records separate from voucher personalization data.
- Include accessibility and keyboard/screen-reader checks for product forms, account flows, checkout extensions, PDF preview, and admin workflows.
