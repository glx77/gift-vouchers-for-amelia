=== Gift Vouchers for Amelia ===
Contributors: glx77
Tags: gift voucher, gift card, amelia, woocommerce, coupon
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell gift vouchers for your Amelia services and events via WooCommerce; each paid order auto-creates a single-use Amelia coupon.

== Description ==

Gift Vouchers for Amelia bridges WooCommerce and the Amelia booking plugin so you can sell gift vouchers for your services and events:

* Mirror any visible Amelia service or upcoming event into a virtual WooCommerce product (name + price) from a checkbox list under **WooCommerce → Gift Vouchers**. Unchecking an item sets its product back to draft (reversible); products of events that are over are set to draft automatically on the next save.
* Products live in a "Gift Vouchers" product category and are listed on an auto-created page.
* Each voucher product is limited to one per order (quantity locked server-side).
* When an order is paid, the plugin generates — for each purchased voucher — an **Amelia coupon**, single-use, with an expiry date (6 months by default, configurable):
  * service: 100% discount, restricted to that service;
  * event: a fixed amount worth one place, restricted to that event (every date of a recurring event).
* The code(s) are emailed to the buyer, added to the admin "New order" email, and stored on the order.

The recipient books the service or event in Amelia and enters the code: it covers the full price of the service, or one place of the event (extra places are paid). Amelia natively enforces expiry, single-use and the service/event restriction.

== Requirements ==

* WooCommerce (active)
* Amelia booking plugin (active)
* A configured WooCommerce payment gateway
* PHP 7.4+

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **WooCommerce → Gift Vouchers**: set the validity duration and the booking page URL, then tick the services and events to offer and save.
4. The public vouchers page is available at `/gift-vouchers/`.

== Frequently Asked Questions ==

= What happens if I buy two different services? =

One distinct code is generated per service (and per quantity unit), each restricted to its own service. An Amelia coupon is consumed by a single booking, so you need one code per service to gift.

= Does it work with Amelia events? =

Yes. Upcoming events are listed below the services. An event voucher is worth one place: the event price, or the cheapest ticket when the event uses custom pricing. If the recipient books several places, only one is covered. For a recurring event, the code is valid on any of its dates.

= Can an expired or already-used code be reused? =

No. Expiry and single-use are enforced natively by Amelia.

= Does uninstalling delete data? =

No. Only the plugin's own option is removed. Products, the vouchers page and generated coupons are kept.

== Screenshots ==

1. Admin: pick the Amelia services to sell as gift vouchers.
2. The email a buyer receives, containing the gift code.

== Changelog ==

= 1.1.0 =
* New: sell gift vouchers for Amelia events (one place per voucher, valid on every date of a recurring event). Products of past events are unpublished automatically.

= 1.0.0 =
* Initial release: Amelia service to WooCommerce product sync (checkbox list), automatic 100% Amelia coupon generation on paid orders, buyer email, code(s) added to the admin "New order" email, quantity locked to 1, vouchers page, optional printable gift-voucher image (configurable message) attached to the buyer email. Fully translatable (English default, French translation bundled).
