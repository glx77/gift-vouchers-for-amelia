=== Gift Vouchers for Amelia ===
Contributors: glx77
Tags: woocommerce, amelia, gift, voucher, coupon, booking
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell gift vouchers for your Amelia services through WooCommerce. Each paid order automatically creates a 100% Amelia coupon restricted to the purchased service.

== Description ==

Gift Vouchers for Amelia bridges WooCommerce and the Amelia booking plugin so you can sell gift vouchers for your services:

* Mirror any visible Amelia service into a virtual WooCommerce product (name + price) from a checkbox list under **WooCommerce → Gift Vouchers**. Unchecking a service sets its product back to draft (reversible).
* Products live in a "Gift Vouchers" product category and are listed on an auto-created page.
* Each voucher product is limited to one per order (quantity locked server-side).
* When an order is paid, the plugin generates — for each purchased service — an **Amelia coupon**: 100% discount, restricted to that service, single-use, with an expiry date (6 months by default, configurable).
* The code(s) are emailed to the buyer, added to the admin "New order" email, and stored on the order.

The recipient books the service in Amelia and enters the code: the discount covers 100% of the price. Amelia natively enforces expiry, single-use and the service restriction.

== Requirements ==

* WooCommerce (active)
* Amelia booking plugin (active)
* A configured WooCommerce payment gateway
* PHP 7.4+

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **WooCommerce → Gift Vouchers**: set the validity duration and the booking page URL, then tick the services to offer and save.
4. The public vouchers page is available at `/bon-cadeau/`.

== Frequently Asked Questions ==

= What happens if I buy two different services? =

One distinct code is generated per service (and per quantity unit), each restricted to its own service. An Amelia coupon is consumed by a single booking, so you need one code per service to gift.

= Can an expired or already-used code be reused? =

No. Expiry and single-use are enforced natively by Amelia.

= Does uninstalling delete data? =

No. Only the plugin's own option is removed. Products, the vouchers page and generated coupons are kept.

== Changelog ==

= 1.0.0 =
* Initial release: Amelia service to WooCommerce product sync (checkbox list), automatic 100% Amelia coupon generation on paid orders, buyer email, code(s) added to the admin "New order" email, quantity locked to 1, vouchers page.
