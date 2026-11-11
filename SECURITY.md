# Security and privacy controls

This document records the controls exercised by the current integration suite. It is not a security certification.

## Admin and scanner access

Voucher administration and import/export handlers check capabilities and WordPress nonces. PDF admin downloads require both a nonce and voucher-management capability. Scanner REST routes require a scanner capability (or WooCommerce management capability); the integration suite checks unauthenticated lookup, check-in, and redemption requests.

The public balance shortcode requires a nonce and applies per-visitor and site-wide fixed-window rate limits before voucher lookup. Rate limits reduce code enumeration; they are not a substitute for edge-level abuse protection under distributed traffic.

## Guest PDF links

Guest PDF links are signed with an HMAC tied to the site secret and the voucher token. Page links expire after one day. Email-link lifetime follows the merchant's download-link setting; zero means no expiry. A changed token, expiry, or signature makes the URL invalid. Admin-generated links use a nonce and capability check.

## Personal data and retention

Privacy export and erasure paginate records in WordPress-sized batches. Erasure removes direct buyer and recipient identifiers and redacts free-text event notes while preserving voucher and event rows. Retention anonymization clears voucher personalization and redacts event notes in a transaction. Failed database operations are reported so erasure can be retried.

Delivery and resend audit entries record recipient counts or recipient roles, not email addresses. Do not put customer data, tokens, or full signed URLs in application debug logs.

## CI coverage

The security integration script exercises 501-record export and erasure, retention anonymization, scanner permissions, rate limiting, guest-link signing, selected admin nonce/capability guards, and the PHP redemption-service idempotency contract. CI also runs Composer vulnerability auditing, scoped WordPress Coding Standards, and PHPStan over the new API contract.

External gateway redirect/webhook flows, browser accessibility, multi-node rate-limit behavior, and connectors installed in separate repositories remain release-time checks.
