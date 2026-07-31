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
	const CATEGORY_SLUG = 'bon-cadeau';
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
	 * Default settings merged with the stored ones.
	 *
	 * @return array{validity_months:int, booking_url:string}
	 */
	public static function get_settings() {
		$defaults = array(
			'validity_months' => 6,
			'booking_url'     => home_url( '/reservation/' ),
		);

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, $stored );
	}

	/**
	 * @param string $key
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

		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table;
	}

	/**
	 * Create the "Bon cadeau" product category and the listing page.
	 */
	public static function activate() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// 1. Product category.
		if ( ! term_exists( self::CATEGORY_SLUG, 'product_cat' ) ) {
			wp_insert_term( 'Bon cadeau', 'product_cat', array( 'slug' => self::CATEGORY_SLUG ) );
		}

		// 2. Listing page (only once).
		$existing_page_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( ! $existing_page_id || get_post_status( $existing_page_id ) === false ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => 'Bon cadeau',
					'post_name'    => 'bon-cadeau',
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
		echo '<div class="notice notice-error"><p><strong>Gift Vouchers for Amelia</strong> nécessite WooCommerce actif pour fonctionner.</p></div>';
	}
}
