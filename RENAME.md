# Fallback rename plan (only if WordPress.org rejects the "Amelia" name/slug)

WordPress.org can refuse a plugin whose **name or slug contains a trademark**
("Amelia", "WooCommerce"). This repo keeps the current name on purpose — apply
this recipe **only if the review team asks for a generic name**.

Suggested generic identity:

| Item        | Current                     | Generic fallback          |
|-------------|-----------------------------|---------------------------|
| Plugin name | Gift Vouchers for Amelia    | Service Gift Vouchers     |
| Slug        | `gift-vouchers-for-amelia`  | `service-gift-vouchers`   |
| Text domain | `gift-vouchers-for-amelia`  | `service-gift-vouchers`   |
| Code prefix | `GVFA_` / `gvfa_`           | `SGV_` / `sgv_`           |

## Steps

1. **Rename class files** `includes/class-gvfa-*.php` → `includes/class-sgv-*.php`.

2. **Rewrite identifiers** across the code:
   ```bash
   find . -name '*.php' -not -path './vendor/*' -exec sed -i '' \
     -e 's/GVFA_/SGV_/g' \
     -e 's/gvfa_/sgv_/g' \
     -e 's/gvfa-/sgv-/g' \
     -e 's/class-gvfa-/class-sgv-/g' \
     -e 's/GiftVouchersForAmelia/ServiceGiftVouchers/g' \
     -e 's/gift-vouchers-for-amelia/service-gift-vouchers/g' \
     {} +
   ```
   Then update the same text-domain / name strings in `readme.txt`, `README.md`,
   `composer.json`, `phpcs.xml.dist` (the `gvfa`/`GVFA` prefixes),
   `.github/workflows/*` (SLUG), and rename `gift-vouchers-for-amelia.php`.

3. **Keep** the product meta key `_amelia_service_id` (it links product↔service).
   Referencing "Amelia" in the *description* text is fine; only the **name/slug**
   are the trademark concern.

4. **Regenerate translations** (msgids change):
   ```bash
   wp i18n make-pot . languages/service-gift-vouchers.pot --domain=service-gift-vouchers
   # port the fr_FR translations into languages/service-gift-vouchers-fr_FR.po, then:
   msgfmt languages/service-gift-vouchers-fr_FR.po -o languages/service-gift-vouchers-fr_FR.mo
   ```

5. **Re-run quality gates**: `composer phpcs && composer phpstan`.

6. **New home**: use a new SVN slug `service-gift-vouchers` on WordPress.org, and
   (optionally) rename the GitHub repo to match.
