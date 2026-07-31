<?php
/**
 * Duplicates visible Amelia services into WooCommerce products.
 *
 * The link between a product and its Amelia service is stored in the
 * `_amelia_service_id` product meta — this is what lets us generate the
 * right coupon when the product is bought.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Sync {

	const META_SERVICE_ID = '_amelia_service_id';
	const META_IS_VOUCHER = '_gvfa_voucher';

	/**
	 * List every visible Amelia service with its current sync status.
	 *
	 * @return array<int,object{id:int,name:string,price:string,product_id:int,status:string,is_synced:bool}>
	 */
	public function get_services() {
		global $wpdb;

		if ( ! GVFA_Plugin::amelia_active() ) {
			return array();
		}

		$services = $wpdb->get_results(
			"SELECT id, name, price FROM {$wpdb->prefix}amelia_services WHERE status = 'visible' ORDER BY name ASC"
		);

		if ( ! $services ) {
			return array();
		}

		$out = array();

		foreach ( $services as $service ) {
			$product_id = $this->find_product_by_service( (int) $service->id );
			$status     = $product_id ? get_post_status( $product_id ) : '';

			$out[] = (object) array(
				'id'         => (int) $service->id,
				'name'       => $service->name,
				'price'      => $service->price,
				'product_id' => (int) $product_id,
				'status'     => $status,
				// Pre-checked in the admin when the product exists and is live.
				'is_synced'  => ( 'publish' === $status ),
			);
		}

		return $out;
	}

	/**
	 * Sync the checked services and unpublish (set to draft) the unchecked ones
	 * whose product is currently published.
	 *
	 * @param int[] $checked_ids Amelia service IDs that must be published.
	 * @return array{created:int, updated:int, drafted:int, total:int, errors:string[]}
	 */
	public function sync_selected( $checked_ids ) {
		global $wpdb;

		$result = array(
			'created' => 0,
			'updated' => 0,
			'drafted' => 0,
			'total'   => 0,
			'errors'  => array(),
		);

		if ( ! GVFA_Plugin::amelia_active() ) {
			$result['errors'][] = __( 'The Amelia tables were not found.', 'gift-vouchers-for-amelia' );
			return $result;
		}

		$category_id = $this->ensure_category();

		if ( ! $category_id ) {
			$result['errors'][] = __( 'Could not create the gift vouchers category.', 'gift-vouchers-for-amelia' );
			return $result;
		}

		$checked_ids = array_map( 'intval', (array) $checked_ids );

		$services = $wpdb->get_results(
			"SELECT id, name, price FROM {$wpdb->prefix}amelia_services WHERE status = 'visible' ORDER BY name ASC"
		);

		if ( ! $services ) {
			$result['errors'][] = __( 'No visible Amelia service found.', 'gift-vouchers-for-amelia' );
			return $result;
		}

		foreach ( $services as $service ) {
			++$result['total'];

			$sid         = (int) $service->id;
			$existing_id = $this->find_product_by_service( $sid );
			$checked     = in_array( $sid, $checked_ids, true );

			if ( $checked ) {
				$is_new  = ! $existing_id;
				$product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();

				if ( ! $product ) {
					$product = new WC_Product_Simple();
					$is_new  = true;
				}

				$product->set_name( $service->name );
				$product->set_regular_price( (string) $service->price );
				$product->set_price( (string) $service->price );
				$product->set_virtual( true );
				$product->set_sold_individually( true ); // one voucher per line
				$product->set_catalog_visibility( 'visible' );
				$product->set_status( 'publish' ); // (re)publish when checked

				if ( $is_new ) {
					$product->set_category_ids( array( $category_id ) );
				}

				$product->update_meta_data( self::META_SERVICE_ID, (string) $sid );
				$product->update_meta_data( self::META_IS_VOUCHER, 'yes' );

				$product_id = $product->save();

				if ( ! $product_id ) {
					$result['errors'][] = sprintf(
						/* translators: 1: service ID, 2: service name. */
						__( 'Failed to save the product for service #%1$d (%2$s).', 'gift-vouchers-for-amelia' ),
						$sid,
						$service->name
					);
					continue;
				}

				if ( $is_new ) {
					++$result['created'];
				} else {
					++$result['updated'];
				}
			} elseif ( $existing_id ) {
				// Unchecked: unpublish the product if it is currently live.
				$product = wc_get_product( $existing_id );

				if ( $product && $product->get_status() === 'publish' ) {
					$product->set_status( 'draft' );
					$product->save();
					++$result['drafted'];
				}
			}
		}

		return $result;
	}

	/**
	 * @param int $service_id
	 * @return int Product ID or 0.
	 */
	public function find_product_by_service( $service_id ) {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value = %d
                   AND p.post_type = 'product' AND p.post_status != 'trash'
                 LIMIT 1",
				self::META_SERVICE_ID,
				$service_id
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * @return int Category term ID or 0.
	 */
	private function ensure_category() {
		$term = get_term_by( 'slug', GVFA_Plugin::CATEGORY_SLUG, 'product_cat' );

		if ( $term ) {
			return (int) $term->term_id;
		}

		$created = wp_insert_term( __( 'Gift Vouchers', 'gift-vouchers-for-amelia' ), 'product_cat', array( 'slug' => GVFA_Plugin::CATEGORY_SLUG ) );

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Count products currently flagged as vouchers.
	 *
	 * @return int
	 */
	public function count_voucher_products() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status = 'publish'",
				self::META_IS_VOUCHER
			)
		);
	}
}
