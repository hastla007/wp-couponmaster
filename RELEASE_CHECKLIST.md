# Release acceptance checklist

## Automated checks

- [ ] CI is green on PHP 8.1 and 8.4.
- [ ] Integration matrix is green for MySQL 8.4 and MariaDB 11.4, each with HPOS and legacy order storage.
- [ ] Composer audit, scoped PHPCS, and PHPStan checks pass.
- [ ] Release ZIP installs and activates from a clean WordPress 7.1.4 site with WooCommerce 11.2.2.
- [ ] Forced ZIP reinstall/update preserves plugin tables and voucher rows.
- [ ] Default uninstall preserves plugin data; explicit `vcap_delete_on_uninstall=yes` removes plugin tables.

## Manual release checks

- [ ] Complete a real gateway payment, redirect return, webhook retry, and refund on the supported gateway set.
- [ ] Check Yoast SEO admin, checkout, and customer account pages in a browser.
- [ ] Review keyboard and screen-reader flows for voucher purchase, balance check, account downloads, and scanner actions.
- [ ] Verify translations and customer-facing voucher expiry, transferability, residual value, and redemption terms for the store's legal policy.
- [ ] Have the merchant's tax/accounting adviser approve voucher tax and refund treatment for each voucher type.

## Cross-plugin release gate

| Installation | Required check | Status |
| --- | --- | --- |
| Voucher Captain alone | Activate, issue, deliver, redeem, refund, export/erase privacy data, uninstall | CI core coverage; real gateway/browser checks remain |
| Ticket Captain alone | Activate and complete its normal ticket lifecycle | Run in the Ticket Captain repository |
| Both plugins | Install/activate together; exchange a voucher/ticket through the versioned provider contract; retry and rollback | Blocked until the shared adapter is implemented and contract-tested |

Do not advertise a Ticket Captain or Learnboating connector as supported until the corresponding external contract and combined installation pass.
