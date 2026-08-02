<?php
/**
 * Main plugin orchestrator: settings helpers, dependency checks, activation.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Plugin {

	const OPTION_KEY    = 'gvfa_settings';
	const CATEGORY_SLUG = 'gift-vouchers';
	const PAGE_OPTION   = 'gvfa_page_id';

	/** @var GVFA_Plugin|null */
	private static $instance = null;

	/** @var GVFA_Sync */
	public $sync;

	/** @var GVFA_Vouchers */
	public $vouchers;

	/** @var GVFA_Admin */
	public $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// WooCommerce is mandatory. If missing, show a notice and stop.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		$this->sync     = new GVFA_Sync();
		$this->vouchers = new GVFA_Vouchers();
		$this->admin    = new GVFA_Admin( $this->sync );

		$this->vouchers->register_hooks();
		$this->admin->register_hooks();
	}

	/**
	 * Load the plugin translations.
	 */
	public static function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Kept so the bundled translations load on WP < 6.7, where just-in-time loading does not read the plugin's own /languages folder.
		load_plugin_textdomain(
			'gift-vouchers-for-amelia',
			false,
			dirname( plugin_basename( GVFA_FILE ) ) . '/languages'
		);
	}

	/**
	 * Default settings merged with the stored ones.
	 *
	 * @return array{validity_months:int, booking_url:string, voucher_attach:int, voucher_message:string, voucher_contact:string}
	 */
	public static function get_settings() {
		$defaults = array(
			'validity_months' => 6,
			'booking_url'     => home_url( '/reservation/' ),
			'voucher_attach'  => 1,
			'voucher_message' => "Une expérience dédiée à la confiance en soi et à la mise en valeur grâce à un accompagnement personnalisé.\n\nValable {months} mois à compter de la date d'achat.\n\nRéservation en ligne en renseignant le code cadeau lors de la réservation.\n\nBon cadeau non remboursable et non échangeable.",
			'voucher_contact' => "Contact : 07 75 22 49 63\nGlowwithlilou.com",
		);

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, $stored );
	}

	/**
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_setting( $key ) {
		$settings = self::get_settings();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Amelia must be installed for the whole thing to make sense.
	 *
	 * @return bool
	 */
	public static function amelia_active() {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_services';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create the gift-vouchers product category and the listing page.
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::load_textdomain();

		// 1. Product category.
		if ( ! term_exists( self::CATEGORY_SLUG, 'product_cat' ) ) {
			wp_insert_term(
				__( 'Gift Vouchers', 'gift-vouchers-for-amelia' ),
				'product_cat',
				array( 'slug' => self::CATEGORY_SLUG )
			);
		}

		// 2. Listing page (only once).
		$existing_page_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( ! $existing_page_id || false === get_post_status( $existing_page_id ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Gift Vouchers', 'gift-vouchers-for-amelia' ),
					'post_name'    => self::CATEGORY_SLUG,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '[products category="' . self::CATEGORY_SLUG . '" limit="-1" columns="3" orderby="title" order="ASC"]',
				)
			);

			if ( $page_id ) {
				update_option( self::PAGE_OPTION, $page_id );
			}
		}
	}

	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>'
			. wp_kses_post( __( '<strong>Gift Vouchers for Amelia</strong> requires WooCommerce to be active.', 'gift-vouchers-for-amelia' ) )
			. '</p></div>';
	}
}
