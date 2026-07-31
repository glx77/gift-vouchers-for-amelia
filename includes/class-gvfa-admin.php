<?php
/**
 * Admin screen: settings (validity, booking URL) + the "sync services" list.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Admin {

	/** @var GVFA_Sync */
	private $sync;

	public function __construct( GVFA_Sync $sync ) {
		$this->sync = $sync;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_gvfa_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_gvfa_sync', array( $this, 'handle_sync' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Gift Vouchers', 'gift-vouchers-for-amelia' ),
			__( 'Gift Vouchers', 'gift-vouchers-for-amelia' ),
			'manage_woocommerce',
			'gvfa-vouchers',
			array( $this, 'render_page' )
		);
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'gift-vouchers-for-amelia' ) );
		}

		check_admin_referer( 'gvfa_save_settings' );

		$settings = array(
			'validity_months' => max( 1, absint( wp_unslash( $_POST['validity_months'] ?? 6 ) ) ),
			'booking_url'     => esc_url_raw( wp_unslash( $_POST['booking_url'] ?? '' ) ),
		);

		update_option( GVFA_Plugin::OPTION_KEY, $settings );

		$this->redirect_back( array( 'gvfa_notice' => 'saved' ) );
	}

	public function handle_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'gift-vouchers-for-amelia' ) );
		}

		check_admin_referer( 'gvfa_sync' );

		$checked = isset( $_POST['gvfa_services'] ) && is_array( $_POST['gvfa_services'] )
			? array_map( 'intval', wp_unslash( $_POST['gvfa_services'] ) )
			: array();

		$result = $this->sync->sync_selected( $checked );

		set_transient( 'gvfa_sync_result_' . get_current_user_id(), $result, 60 );

		$this->redirect_back( array( 'gvfa_notice' => 'synced' ) );
	}

	/**
	 * @param array $args Query args to add to the settings page URL.
	 */
	private function redirect_back( $args ) {
		$url = add_query_arg(
			array_merge( array( 'page' => 'gvfa-vouchers' ), $args ),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'gift-vouchers-for-amelia' ) );
		}

		$settings    = GVFA_Plugin::get_settings();
		$notice      = isset( $_GET['gvfa_notice'] ) ? sanitize_key( wp_unslash( $_GET['gvfa_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag.
		$page_id     = (int) get_option( GVFA_Plugin::PAGE_OPTION, 0 );
		$page_link   = $page_id ? get_permalink( $page_id ) : '';
		$amelia_ok   = GVFA_Plugin::amelia_active();
		$nb_products = $this->sync->count_voucher_products();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Gift Vouchers', 'gift-vouchers-for-amelia' ) . '</h1>';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'gift-vouchers-for-amelia' ) . '</p></div>';
		}

		if ( 'synced' === $notice ) {
			$result = get_transient( 'gvfa_sync_result_' . get_current_user_id() );
			delete_transient( 'gvfa_sync_result_' . get_current_user_id() );

			if ( is_array( $result ) ) {
				$msg = sprintf(
					/* translators: 1: created count, 2: updated count, 3: drafted count, 4: total services. */
					__( 'Sync complete: <strong>%1$d</strong> created, <strong>%2$d</strong> updated, <strong>%3$d</strong> set to draft (out of %4$d services).', 'gift-vouchers-for-amelia' ),
					(int) $result['created'],
					(int) $result['updated'],
					(int) ( $result['drafted'] ?? 0 ),
					(int) $result['total']
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';

				if ( ! empty( $result['errors'] ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( implode( ' ', $result['errors'] ) ) . '</p></div>';
				}
			}
		}

		if ( ! $amelia_ok ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The Amelia tables were not found. The plugin will not be able to generate coupons.', 'gift-vouchers-for-amelia' ) . '</p></div>';
		}

		// --- Settings form ---
		echo '<h2>' . esc_html__( 'Settings', 'gift-vouchers-for-amelia' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gvfa_save_settings' );
		echo '<input type="hidden" name="action" value="gvfa_save_settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="gvfa_validity">' . esc_html__( 'Validity duration (months)', 'gift-vouchers-for-amelia' ) . '</label></th>';
		echo '<td><input name="validity_months" id="gvfa_validity" type="number" min="1" step="1" value="' . esc_attr( (string) $settings['validity_months'] ) . '" class="small-text"> '
			. '<p class="description">' . esc_html__( 'How long a voucher stays valid after purchase (default: 6 months).', 'gift-vouchers-for-amelia' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="gvfa_booking">' . esc_html__( 'Booking page', 'gift-vouchers-for-amelia' ) . '</label></th>';
		echo '<td><input name="booking_url" id="gvfa_booking" type="url" value="' . esc_attr( $settings['booking_url'] ) . '" class="regular-text"> '
			. '<p class="description">' . esc_html__( 'Link included in the email so the customer can book and enter their code.', 'gift-vouchers-for-amelia' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'gift-vouchers-for-amelia' ) );
		echo '</form>';

		echo '<hr>';

		// --- Sync ---
		echo '<h2>' . esc_html__( 'Services to offer as gift vouchers', 'gift-vouchers-for-amelia' ) . '</h2>';
		echo '<p>' . wp_kses_post(
			__( 'Tick the services you want to sell as gift vouchers. A virtual WooCommerce product (service name + price) is created and published for each checked service, in the "Gift Vouchers" category. <strong>Unchecking</strong> an already-published service moves its product back to <strong>draft</strong> (removed from the shop but kept — re-checking republishes it).', 'gift-vouchers-for-amelia' )
		) . '</p>';
		echo '<p>' . esc_html__( 'Published gift voucher products:', 'gift-vouchers-for-amelia' ) . ' <strong>' . (int) $nb_products . '</strong></p>';

		$services = $this->sync->get_services();

		if ( ! $services ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No visible Amelia service found.', 'gift-vouchers-for-amelia' ) . '</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gvfa_sync' );
			echo '<input type="hidden" name="action" value="gvfa_sync">';

			echo '<table class="wp-list-table widefat fixed striped" style="max-width:760px;margin-top:10px;">';
			echo '<thead><tr>'
				. '<td class="check-column" style="width:2.2em;"><input type="checkbox" id="gvfa-check-all"></td>'
				. '<th>' . esc_html__( 'Service', 'gift-vouchers-for-amelia' ) . '</th>'
				. '<th style="width:120px;">' . esc_html__( 'Price', 'gift-vouchers-for-amelia' ) . '</th>'
				. '<th style="width:140px;">' . esc_html__( 'Current status', 'gift-vouchers-for-amelia' ) . '</th>'
				. '</tr></thead><tbody>';

			foreach ( $services as $s ) {
				switch ( $s->status ) {
					case 'publish':
						$state = '<span style="color:#008a20;">● ' . esc_html__( 'Published', 'gift-vouchers-for-amelia' ) . '</span>';
						break;
					case 'draft':
						$state = '<span style="color:#996800;">● ' . esc_html__( 'Draft', 'gift-vouchers-for-amelia' ) . '</span>';
						break;
					case '':
						$state = '<span style="color:#787c82;">— ' . esc_html__( 'Not created', 'gift-vouchers-for-amelia' ) . '</span>';
						break;
					default:
						$state = esc_html( $s->status );
				}

				printf(
					'<tr>'
					. '<th scope="row" class="check-column"><input type="checkbox" class="gvfa-service" name="gvfa_services[]" value="%1$d" %2$s></th>'
					. '<td><label>%3$s</label></td>'
					. '<td>%4$s</td>'
					. '<td>%5$s</td>'
					. '</tr>',
					(int) $s->id,
					checked( $s->is_synced, true, false ),
					esc_html( $s->name ),
					wp_kses_post( wc_price( (float) $s->price ) ),
					wp_kses_post( $state )
				);
			}

			echo '</tbody></table>';
			submit_button( __( 'Save selection', 'gift-vouchers-for-amelia' ) );
			echo '</form>';

			// Master checkbox toggle.
			echo '<script>(function(){var a=document.getElementById("gvfa-check-all");if(!a)return;'
				. 'a.addEventListener("change",function(){document.querySelectorAll(".gvfa-service").forEach(function(c){c.checked=a.checked;});});})();</script>';
		}

		if ( $page_link ) {
			echo '<hr><p>' . esc_html__( 'Public page:', 'gift-vouchers-for-amelia' ) . ' <a href="' . esc_url( $page_link ) . '" target="_blank">' . esc_html( $page_link ) . '</a></p>';
		}

		echo '</div>';
	}
}
