<?php
/**
 * Generates Amelia coupons when a gift-voucher order is paid, and emails
 * the buyer with the code(s).
 *
 * A voucher = an Amelia coupon: 100% discount, restricted to one service,
 * single use (limit = 1), expiring after N months. Amelia natively enforces
 * expiry, single-use (it counts bookings referencing the coupon) and the
 * service restriction — so we only need to insert the rows correctly.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Vouchers {

	const ORDER_FLAG  = '_gvfa_vouchers_generated';
	const ORDER_CODES = '_gvfa_voucher_codes';

	/**
	 * Codes generated during this request, keyed by order ID. The admin
	 * "New order" email is composed from an order object loaded before we
	 * saved the codes, so its get_meta() would be stale — we read from here
	 * first and only fall back to persisted meta.
	 *
	 * @var array<int,array>
	 */
	private static $runtime_codes = array();

	public function register_hooks() {
		// Fire when the order is paid. Both statuses are covered; the flag
		// guard makes sure we never generate twice.
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_generate' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_generate' ), 10, 1 );

		// Show the generated codes on the order edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );

		// Force voucher products to 1 per order (removes the qty field in cart,
		// server-side). Applies to every voucher product regardless of its
		// stored setting, so existing products need no re-sync.
		add_filter( 'woocommerce_is_sold_individually', array( $this, 'force_sold_individually' ), 10, 2 );

		// Add the generated codes to the admin "New order" email.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'add_codes_to_admin_email' ), 20, 4 );
	}

	/**
	 * Append the voucher codes to the admin "New order" email body.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param WC_Email|null $email
	 */
	public function add_codes_to_admin_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( empty( $email ) || 'new_order' !== $email->id ) {
			return; // Only the admin new-order notification.
		}

		$oid   = $order->get_id();
		$codes = self::$runtime_codes[ $oid ] ?? $order->get_meta( self::ORDER_CODES );

		if ( ! is_array( $codes ) || ! $codes ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n\n" . esc_html__( 'Gift vouchers generated', 'gift-vouchers-for-amelia' ) . "\n";
			foreach ( $codes as $c ) {
				echo esc_html(
					sprintf(
						/* translators: 1: service name, 2: voucher code, 3: expiry date. */
						__( '- %1$s: %2$s (expires on %3$s)', 'gift-vouchers-for-amelia' ),
						$c['product_name'] ?? '',
						$c['code'] ?? '',
						$c['expires_display'] ?? ''
					)
				) . "\n";
			}
			return;
		}

		echo '<h2>' . esc_html__( 'Gift vouchers generated', 'gift-vouchers-for-amelia' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;margin-bottom:20px;">';
		echo '<tr><th style="text-align:left;border:1px solid #eee;">' . esc_html__( 'Service', 'gift-vouchers-for-amelia' ) . '</th>'
			. '<th style="text-align:left;border:1px solid #eee;">' . esc_html__( 'Code', 'gift-vouchers-for-amelia' ) . '</th>'
			. '<th style="text-align:left;border:1px solid #eee;">' . esc_html__( 'Expires on', 'gift-vouchers-for-amelia' ) . '</th></tr>';
		foreach ( $codes as $c ) {
			echo '<tr>'
				. '<td style="border:1px solid #eee;">' . esc_html( $c['product_name'] ?? '' ) . '</td>'
				. '<td style="border:1px solid #eee;font-family:monospace;"><strong>' . esc_html( $c['code'] ?? '' ) . '</strong></td>'
				. '<td style="border:1px solid #eee;">' . esc_html( $c['expires_display'] ?? '' ) . '</td>'
				. '</tr>';
		}
		echo '</table>';
	}

	/**
	 * @param bool       $sold_individually
	 * @param WC_Product|null $product
	 * @return bool
	 */
	public function force_sold_individually( $sold_individually, $product ) {
		if ( $product && $product->get_meta( GVFA_Sync::META_IS_VOUCHER ) === 'yes' ) {
			return true;
		}

		return $sold_individually;
	}

	/**
	 * @param int $order_id
	 */
	public function maybe_generate( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( self::ORDER_FLAG ) ) {
			return; // Already processed.
		}

		$months    = max( 1, (int) GVFA_Plugin::get_setting( 'validity_months' ) );
		$generated = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$service_id = (int) $product->get_meta( GVFA_Sync::META_SERVICE_ID );

			if ( ! $service_id ) {
				continue; // Not a voucher product.
			}

			$quantity = max( 1, (int) $item->get_quantity() );

			for ( $i = 0; $i < $quantity; $i++ ) {
				$coupon = $this->create_coupon( $service_id, $months );

				if ( $coupon ) {
					$coupon['product_name'] = $product->get_name();
					$generated[]            = $coupon;
				}
			}
		}

		if ( ! $generated ) {
			return; // Order had no voucher products.
		}

		// Persist for traceability.
		$order->update_meta_data( self::ORDER_FLAG, current_time( 'mysql' ) );
		$order->update_meta_data( self::ORDER_CODES, $generated );
		$order->save();

		self::$runtime_codes[ $order->get_id() ] = $generated;

		$lines = array_map(
			function ( $c ) {
				return sprintf(
					/* translators: 1: service name, 2: voucher code, 3: expiry date. */
					__( '%1$s → %2$s (expires on %3$s)', 'gift-vouchers-for-amelia' ),
					$c['product_name'],
					$c['code'],
					$c['expires_display']
				);
			},
			$generated
		);

		$order->add_order_note( __( 'Gift vouchers generated:', 'gift-vouchers-for-amelia' ) . "\n" . implode( "\n", $lines ) );

		$this->send_email( $order, $generated );
	}

	/**
	 * Insert a single-use, 100%, service-restricted Amelia coupon.
	 *
	 * @param int $service_id
	 * @param int $months
	 * @return array{code:string, expires:string, expires_display:string, coupon_id:int}|null
	 */
	private function create_coupon( $service_id, $months ) {
		global $wpdb;

		// Amelia compares coupon dates against UTC (getNowDateTimeInUtc), so we
		// must store them in UTC too. startDate is left NULL: a gift voucher is
		// valid from the moment of purchase, which also avoids any timezone edge
		// case on the "not started yet" filter.
		$exp = ( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) )->modify( "+{$months} months" );

		$code = $this->unique_code();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'amelia_coupons',
			array(
				'code'                  => $code,
				'discount'              => 100,
				'deduction'             => 0,
				'limit'                 => 1,
				'customerLimit'         => 0,
				'status'                => 'visible',
				'notificationInterval'  => 0,
				'notificationRecurring' => 0,
				'startDate'             => null,
				'expirationDate'        => $exp->format( 'Y-m-d H:i:s' ),
				'allServices'           => 0,
				'allEvents'             => 0,
				'allPackages'           => 0,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( ! $inserted ) {
			return null;
		}

		$coupon_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$linked = $wpdb->insert(
			$wpdb->prefix . 'amelia_coupons_to_services',
			array(
				'couponId'  => $coupon_id,
				'serviceId' => $service_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $linked ) {
			// Roll back the orphan coupon so it can never apply to everything.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
			$wpdb->delete( $wpdb->prefix . 'amelia_coupons', array( 'id' => $coupon_id ), array( '%d' ) );
			return null;
		}

		return array(
			'code'            => $code,
			'coupon_id'       => $coupon_id,
			'expires'         => $exp->format( 'Y-m-d H:i:s' ),
			// Amelia enforces expiry against the calendar date of expirationDate
			// (floored to 23:59:59), so display that exact date.
			'expires_display' => $exp->format( 'd/m/Y' ),
		);
	}

	/**
	 * @return string A code guaranteed unique in the coupons table.
	 */
	private function unique_code() {
		global $wpdb;

		$prefix = apply_filters( 'gvfa_code_prefix', 'GIFT-' );

		do {
			$code = $prefix . strtoupper( wp_generate_password( 4, false, false ) )
					. '-' . strtoupper( wp_generate_password( 4, false, false ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}amelia_coupons WHERE code = %s LIMIT 1",
					$code
				)
			);
		} while ( $exists );

		return $code;
	}

	/**
	 * @param WC_Order $order
	 * @param array    $vouchers
	 */
	private function send_email( $order, $vouchers ) {
		$to = $order->get_billing_email();

		if ( ! $to ) {
			return;
		}

		$booking_url = GVFA_Plugin::get_setting( 'booking_url' );
		$first_name  = $order->get_billing_first_name();

		$rows = '';
		foreach ( $vouchers as $v ) {
			$rows .= '<tr>'
				. '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $v['product_name'] ) . '</td>'
				. '<td style="padding:8px 12px;border:1px solid #eee;font-family:monospace;font-size:16px;"><strong>' . esc_html( $v['code'] ) . '</strong></td>'
				. '<td style="padding:8px 12px;border:1px solid #eee;">' . esc_html( $v['expires_display'] ) . '</td>'
				. '</tr>';
		}

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your gift voucher from %s', 'gift-vouchers-for-amelia' ), get_bloginfo( 'name' ) );

		$how_to = sprintf(
			/* translators: 1: opening link tag, 2: closing link tag. */
			__( '<strong>How to use it:</strong> go to %1$sour booking page%2$s, choose the matching service, then enter the code above at checkout. The discount covers 100%% of the service.', 'gift-vouchers-for-amelia' ),
			'<a href="' . esc_url( $booking_url ) . '">',
			'</a>'
		);

		$message = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#333;max-width:600px;">'
			/* translators: %s: customer first name. */
			. '<p>' . esc_html( sprintf( __( 'Hello %s,', 'gift-vouchers-for-amelia' ), $first_name ) ) . '</p>'
			. '<p>' . esc_html__( 'Thank you for your order! Here are your gift voucher(s):', 'gift-vouchers-for-amelia' ) . '</p>'
			. '<table style="border-collapse:collapse;margin:16px 0;">'
			. '<tr><th style="padding:8px 12px;border:1px solid #eee;text-align:left;">' . esc_html__( 'Service', 'gift-vouchers-for-amelia' ) . '</th>'
			. '<th style="padding:8px 12px;border:1px solid #eee;text-align:left;">' . esc_html__( 'Code', 'gift-vouchers-for-amelia' ) . '</th>'
			. '<th style="padding:8px 12px;border:1px solid #eee;text-align:left;">' . esc_html__( 'Valid until', 'gift-vouchers-for-amelia' ) . '</th></tr>'
			. $rows
			. '</table>'
			. '<p>' . wp_kses_post( $how_to ) . '</p>'
			. '<p>' . esc_html__( 'Each code can be used only once.', 'gift-vouchers-for-amelia' ) . '</p>'
			. '<p>' . esc_html__( 'See you soon,', 'gift-vouchers-for-amelia' ) . '<br>' . esc_html( get_bloginfo( 'name' ) ) . '</p>'
			. '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Transactional, one-off customer email; wp_mail is the correct primitive (not bulk mailing).
		wp_mail( $to, $subject, $message, $headers );
	}

	// -- Order admin display -------------------------------------------------

	public function add_order_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\Admin\Orders\PageController' )
			&& function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'gvfa_order_vouchers',
			__( 'Gift Vouchers', 'gift-vouchers-for-amelia' ),
			array( $this, 'render_order_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * @param mixed $post_or_order
	 */
	public function render_order_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );

		if ( ! $order ) {
			echo '<p>—</p>';
			return;
		}

		$codes = $order->get_meta( self::ORDER_CODES );

		if ( ! is_array( $codes ) || ! $codes ) {
			echo '<p>' . esc_html__( 'No voucher generated for this order.', 'gift-vouchers-for-amelia' ) . '</p>';
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $codes as $c ) {
			printf(
				'<li><strong>%1$s</strong><br><code>%2$s</code><br><small>%3$s</small></li>',
				esc_html( $c['product_name'] ?? '' ),
				esc_html( $c['code'] ?? '' ),
				esc_html(
					sprintf(
						/* translators: %s: expiry date. */
						__( 'Expires on %s', 'gift-vouchers-for-amelia' ),
						$c['expires_display'] ?? ''
					)
				)
			);
		}
		echo '</ul>';
	}
}
