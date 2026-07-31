# Gift Vouchers for Amelia

Sell gift vouchers for your [Amelia](https://wpamelia.com/) services through WooCommerce. Every paid order automatically creates a **100 % Amelia coupon** restricted to the purchased service, emails the code to the buyer, and lists it on the order.

> Requires **WooCommerce** and the **Amelia** booking plugin.

---

## How it works

```
Customer buys a voucher product   ──►  WooCommerce order (paid)
        │
        ▼
For each purchased service, the plugin creates an Amelia coupon:
   • 100 % discount
   • restricted to that service
   • single use (limit = 1)
   • expires after N months (default 6)
        │
        ▼
Code emailed to the buyer  +  added to the admin "New order" email  +  saved on the order
        │
        ▼
Recipient books the service in Amelia and enters the code  ──►  0 € to pay
```

Amelia natively enforces the expiry date, the single-use limit (it counts the bookings that reference the coupon) and the service restriction, so the plugin only has to create the coupon rows correctly.

### Design notes

- **One code per service purchased** (and per quantity unit). An Amelia coupon is consumed by a single booking and holds no running balance, so covering two services requires two codes.
- **100 % discount** (not a fixed amount): the voucher always covers the whole service, regardless of later price changes.
- Voucher products are **virtual** and **sold individually** (quantity locked to 1, enforced server-side via the `woocommerce_is_sold_individually` filter).
- Amelia stores/compares coupon dates in **UTC**; the plugin writes `expirationDate` in UTC and leaves `startDate` null (valid immediately).

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| WooCommerce | active |
| Amelia | active |
| Payment | a configured WooCommerce gateway |

## Installation

1. Download the latest release ZIP (or clone this repo into `wp-content/plugins/`).
2. Activate **Gift Vouchers for Amelia** in *Plugins*.
3. Open **WooCommerce → Gift Vouchers** and:
   - set the **validity duration** (default 6 months) and the **booking page URL**;
   - tick the services you want to sell as vouchers and **save** (this creates/publishes one WooCommerce product per checked service).
4. The public listing page is created automatically at `/bon-cadeau/`.

## Configuration

All settings live under **WooCommerce → Gift Vouchers**:

- **Validity (months)** — how long a generated code stays valid after purchase.
- **Booking page URL** — the link included in the buyer email so the recipient can redeem the code.
- **Service checklist** — checked = a published voucher product exists; unchecking sets the product back to *draft* (reversible, non-destructive).

### Filters

| Filter | Default | Description |
|---|---|---|
| `gvfa_code_prefix` | `GIFT-` | Prefix used for generated coupon codes. |

## Development

```bash
composer install          # install dev tooling
composer phpcs            # WordPress + VIP coding standards
composer phpstan          # static analysis
composer lint             # php -l on every file
```

CI runs the same checks on every push / pull request (see `.github/workflows/ci.yml`) across the supported PHP versions.

### Coding standards

- **PHPCS** with `WordPress-Extra` + `WordPress-Docs` + `WordPress-VIP-Go`.
  The plugin reads and writes Amelia's custom tables directly (Amelia exposes no public API for coupons); those unavoidable direct queries are annotated with justified `phpcs:ignore` comments.
- **PHPStan** (level in `phpstan.neon.dist`) with WordPress and WooCommerce stubs.

## Uninstall

Removing the plugin deletes only its own option. Products, the vouchers page and any generated Amelia coupons are intentionally left intact.

## License

[GPL-2.0-or-later](LICENSE).
