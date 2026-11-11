# Product scope: Voucher Captain

## Goal

Build an original WooCommerce plugin with functional breadth comparable to Flexible PDF Coupons: merchants can turn products into gift cards, vouchers, or tickets, design printable PDFs, issue redeemable codes, personalize delivery, and manage redemption. Use original naming, code, UI, and visual assets; this is a functional reference, not a code or brand copy.

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

## Open implementation decisions

- PDF engine and minimum PHP/WordPress/WooCommerce versions.
- Whether custom denominations require native support or integration with a product-price extension.
- Whether commercial licensing/update delivery is required, or the first release is a single GPL plugin.
- Supported ticket check-in workflows and whether scanning is single-use only or supports partial admission.
