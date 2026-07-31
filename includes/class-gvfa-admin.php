<?php
/**
 * Admin screen: settings (validity, booking URL) + the "sync services" button.
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
			'Bons cadeaux',
			'Bons cadeaux',
			'manage_woocommerce',
			'gvfa-vouchers',
			array( $this, 'render_page' )
		);
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Accès refusé.' );
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
			wp_die( 'Accès refusé.' );
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
	 * @param array $args
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
			wp_die( 'Accès refusé.' );
		}

		$settings    = GVFA_Plugin::get_settings();
		$notice      = isset( $_GET['gvfa_notice'] ) ? sanitize_key( wp_unslash( $_GET['gvfa_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag.
		$page_id     = (int) get_option( GVFA_Plugin::PAGE_OPTION, 0 );
		$page_link   = $page_id ? get_permalink( $page_id ) : '';
		$amelia_ok   = GVFA_Plugin::amelia_active();
		$nb_produits = $this->sync->count_voucher_products();

		echo '<div class="wrap">';
		echo '<h1>Bons cadeaux</h1>';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
		}

		if ( 'synced' === $notice ) {
			$result = get_transient( 'gvfa_sync_result_' . get_current_user_id() );
			delete_transient( 'gvfa_sync_result_' . get_current_user_id() );

			if ( is_array( $result ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>Synchronisation terminée : <strong>%d</strong> créé(s), <strong>%d</strong> mis à jour, <strong>%d</strong> passé(s) en brouillon (sur %d services).</p></div>',
					(int) $result['created'],
					(int) $result['updated'],
					(int) ( $result['drafted'] ?? 0 ),
					(int) $result['total']
				);

				if ( ! empty( $result['errors'] ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( implode( ' ', $result['errors'] ) ) . '</p></div>';
				}
			}
		}

		if ( ! $amelia_ok ) {
			echo '<div class="notice notice-error"><p>Les tables Amelia sont introuvables. Le plugin ne pourra pas générer de coupons.</p></div>';
		}

		// --- Settings form ---
		echo '<h2>Réglages</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gvfa_save_settings' );
		echo '<input type="hidden" name="action" value="gvfa_save_settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="gvfa_validity">Durée de validité (mois)</label></th>';
		echo '<td><input name="validity_months" id="gvfa_validity" type="number" min="1" step="1" value="' . esc_attr( (string) $settings['validity_months'] ) . '" class="small-text"> '
			. '<p class="description">Durée de validité d\'un bon après l\'achat (défaut : 6 mois).</p></td></tr>';

		echo '<tr><th scope="row"><label for="gvfa_booking">Page de réservation</label></th>';
		echo '<td><input name="booking_url" id="gvfa_booking" type="url" value="' . esc_attr( $settings['booking_url'] ) . '" class="regular-text"> '
			. '<p class="description">Lien inclus dans l\'email pour que le client réserve et saisisse son code.</p></td></tr>';

		echo '</tbody></table>';
		submit_button( 'Enregistrer les réglages' );
		echo '</form>';

		echo '<hr>';

		// --- Sync ---
		echo '<h2>Services à proposer en bon cadeau</h2>';
		echo '<p>Cochez les services que vous voulez vendre en bon cadeau. Un produit WooCommerce virtuel (nom + prix du service) est créé/publié pour chaque service coché, dans la catégorie « Bon cadeau ». '
			. '<strong>Décocher</strong> un service déjà publié repasse son produit en <strong>brouillon</strong> (retiré de la boutique, mais conservé — re-cocher le republie).</p>';
		echo '<p>Produits « bon cadeau » publiés : <strong>' . (int) $nb_produits . '</strong></p>';

		$services = $this->sync->get_services();

		if ( ! $services ) {
			echo '<div class="notice notice-warning inline"><p>Aucun service Amelia visible trouvé.</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gvfa_sync' );
			echo '<input type="hidden" name="action" value="gvfa_sync">';

			echo '<table class="wp-list-table widefat fixed striped" style="max-width:760px;margin-top:10px;">';
			echo '<thead><tr>'
				. '<td class="check-column" style="width:2.2em;"><input type="checkbox" id="gvfa-check-all"></td>'
				. '<th>Service</th>'
				. '<th style="width:100px;">Prix</th>'
				. '<th style="width:140px;">État actuel</th>'
				. '</tr></thead><tbody>';

			foreach ( $services as $s ) {
				switch ( $s->status ) {
					case 'publish':
						$state = '<span style="color:#008a20;">● Publié</span>';
						break;
					case 'draft':
						$state = '<span style="color:#996800;">● Brouillon</span>';
						break;
					case '':
						$state = '<span style="color:#787c82;">— Non créé</span>';
						break;
					default:
						$state = esc_html( $s->status );
				}

				printf(
					'<tr>'
					. '<th scope="row" class="check-column"><input type="checkbox" class="gvfa-service" name="gvfa_services[]" value="%1$d" %2$s></th>'
					. '<td><label>%3$s</label></td>'
					. '<td>%4$s&nbsp;€</td>'
					. '<td>%5$s</td>'
					. '</tr>',
					(int) $s->id,
					checked( $s->is_synced, true, false ),
					esc_html( $s->name ),
					esc_html( number_format( (float) $s->price, 2, ',', ' ' ) ),
					wp_kses_post( $state )
				);
			}

			echo '</tbody></table>';
			submit_button( 'Enregistrer la sélection' );
			echo '</form>';

			// Master checkbox toggle.
			echo '<script>(function(){var a=document.getElementById("gvfa-check-all");if(!a)return;'
				. 'a.addEventListener("change",function(){document.querySelectorAll(".gvfa-service").forEach(function(c){c.checked=a.checked;});});})();</script>';
		}

		if ( $page_link ) {
			echo '<hr><p>Page publique : <a href="' . esc_url( $page_link ) . '" target="_blank">' . esc_html( $page_link ) . '</a></p>';
		}

		echo '</div>';
	}
}
