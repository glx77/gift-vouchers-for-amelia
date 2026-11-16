<?php
/**
 * Duplicates visible Amelia services and upcoming events into WooCommerce
 * products.
 *
 * The link between a product and its Amelia entity is stored in the
 * `_amelia_service_id` / `_amelia_event_id` product meta — this is what lets
 * us generate the right coupon when the product is bought.
 *
 * Events: a recurring event is stored by Amelia as one row per occurrence,
 * all pointing to the first one through `parentId`. A voucher product maps
 * to a whole series, identified by its root ID (COALESCE(parentId, id)).
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GVFA_Sync {

	const META_SERVICE_ID = '_amelia_service_id';
	const META_EVENT_ID   = '_amelia_event_id';
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$services = $wpdb->get_results(
			"SELECT id, name, price FROM {$wpdb->prefix}amelia_services WHERE status = 'visible' ORDER BY name ASC"
		);

		if ( ! $services ) {
			return array();
		}

		$out = array();

		foreach ( $services as $service ) {
			$product_id = $this->find_product( self::META_SERVICE_ID, (int) $service->id );
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
	 * List every bookable upcoming Amelia event series with its sync status.
	 *
	 * An event is listed when at least one approved, publicly shown
	 * occurrence of its series has not ended yet.
	 *
	 * @return array<int,object{id:int,name:string,price:string,next_date:string,product_id:int,status:string,is_synced:bool}>
	 */
	public function get_events() {
		$out = array();

		foreach ( $this->fetch_events() as $event ) {
			$product_id = $this->find_product( self::META_EVENT_ID, (int) $event->id );
			$status     = $product_id ? get_post_status( $product_id ) : '';

			$out[] = (object) array(
				'id'         => (int) $event->id,
				'name'       => $event->name,
				'price'      => $event->price,
				'next_date'  => $event->next_date,
				'product_id' => (int) $product_id,
				'status'     => $status,
				'is_synced'  => ( 'publish' === $status ),
			);
		}

		return $out;
	}

	/**
	 * Upcoming event series (root ID, name, one-place price, next start).
	 *
	 * With custom pricing (ticket types) the event's own price is not used by
	 * Amelia, so the cheapest enabled ticket of the series root is taken.
	 *
	 * @return object[]
	 */
	private function fetch_events() {
		global $wpdb;

		if ( ! GVFA_Plugin::amelia_active() ) {
			return array();
		}

		// Amelia stores event periods in UTC.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$events = $wpdb->get_results(
			"SELECT r.id, r.name,
			        CASE WHEN r.customPricing = 1
			             THEN COALESCE( ( SELECT MIN( t.price ) FROM {$wpdb->prefix}amelia_events_to_tickets t WHERE t.eventId = r.id AND t.enabled = 1 ), r.price )
			             ELSE r.price END AS price,
			        s.next_date
			 FROM (
			     SELECT COALESCE( e.parentId, e.id ) AS series_id, MIN( p.periodStart ) AS next_date
			     FROM {$wpdb->prefix}amelia_events e
			     INNER JOIN {$wpdb->prefix}amelia_events_periods p ON p.eventId = e.id
			     WHERE e.status = 'approved' AND e.`show` = 1 AND p.periodEnd >= UTC_TIMESTAMP()
			     GROUP BY series_id
			 ) s
			 INNER JOIN {$wpdb->prefix}amelia_events r ON r.id = s.series_id
			 ORDER BY s.next_date ASC, r.name ASC"
		);

		return $events ? $events : array();
	}

	/**
	 * Sync the checked services/events and unpublish (set to draft) the
	 * unchecked ones whose product is currently published.
	 *
	 * @param int[] $checked_ids       Amelia service IDs that must be published.
	 * @param int[] $checked_event_ids Amelia event series (root) IDs that must be published.
	 * @return array{created:int, updated:int, drafted:int, total:int, errors:string[]}
	 */
	public function sync_selected( $checked_ids, $checked_event_ids = array() ) {
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

		$checked_ids       = array_map( 'intval', (array) $checked_ids );
		$checked_event_ids = array_map( 'intval', (array) $checked_event_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$services = $wpdb->get_results(
			"SELECT id, name, price FROM {$wpdb->prefix}amelia_services WHERE status = 'visible' ORDER BY name ASC"
		);
		$events   = $this->fetch_events();

		if ( ! $services && ! $events ) {
			$result['errors'][] = __( 'No visible Amelia service or upcoming event found.', 'gift-vouchers-for-amelia' );
			return $result;
		}

		foreach ( (array) $services as $service ) {
			$this->sync_one( self::META_SERVICE_ID, $service, in_array( (int) $service->id, $checked_ids, true ), $category_id, $result );
		}

		$listed_event_ids = array();

		foreach ( $events as $event ) {
			$listed_event_ids[] = (int) $event->id;
			$this->sync_one( self::META_EVENT_ID, $event, in_array( (int) $event->id, $checked_event_ids, true ), $category_id, $result );
		}

		// Events that are over (or cancelled/hidden) no longer appear in the
		// list: unpublish their products so they cannot be bought any more.
		foreach ( $this->find_published_products( self::META_EVENT_ID ) as $product_id => $event_id ) {
			if ( ! in_array( $event_id, $listed_event_ids, true ) ) {
				$this->unpublish( $product_id, $result );
			}
		}

		return $result;
	}

	/**
	 * Create/update/unpublish the voucher product of one Amelia entity.
	 *
	 * @param string $meta_key    META_SERVICE_ID or META_EVENT_ID.
	 * @param object $entity      Row with id, name, price.
	 * @param bool   $checked     Whether the product must be published.
	 * @param int    $category_id Gift vouchers product category.
	 * @param array  $result      Sync counters, updated in place.
	 */
	private function sync_one( $meta_key, $entity, $checked, $category_id, &$result ) {
		++$result['total'];

		$id          = (int) $entity->id;
		$existing_id = $this->find_product( $meta_key, $id );

		if ( ! $checked ) {
			if ( $existing_id ) {
				$this->unpublish( $existing_id, $result );
			}
			return;
		}

		$is_new  = ! $existing_id;
		$product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();

		if ( ! $product ) {
			$product = new WC_Product_Simple();
			$is_new  = true;
		}

		$product->set_name( $entity->name );
		$product->set_regular_price( (string) $entity->price );
		$product->set_price( (string) $entity->price );
		$product->set_virtual( true );
		$product->set_sold_individually( true ); // one voucher per line
		$product->set_catalog_visibility( 'visible' );
		$product->set_status( 'publish' ); // (re)publish when checked

		if ( $is_new ) {
			$product->set_category_ids( array( $category_id ) );
		}

		$product->update_meta_data( $meta_key, (string) $id );
		$product->update_meta_data( self::META_IS_VOUCHER, 'yes' );

		$product_id = $product->save();

		if ( ! $product_id ) {
			$result['errors'][] = sprintf(
				/* translators: 1: Amelia service or event ID, 2: its name. */
				__( 'Failed to save the product for #%1$d (%2$s).', 'gift-vouchers-for-amelia' ),
				$id,
				$entity->name
			);
			return;
		}

		if ( $is_new ) {
			++$result['created'];
		} else {
			++$result['updated'];
		}
	}

	/**
	 * Set a product back to draft if it is currently live.
	 *
	 * @param int   $product_id
	 * @param array $result Sync counters, updated in place.
	 */
	private function unpublish( $product_id, &$result ) {
		$product = wc_get_product( $product_id );

		if ( $product && $product->get_status() === 'publish' ) {
			$product->set_status( 'draft' );
			$product->save();
			++$result['drafted'];
		}
	}

	/**
	 * @param string $meta_key   META_SERVICE_ID or META_EVENT_ID.
	 * @param int    $entity_id  Amelia service ID or event series ID.
	 * @return int Product ID or 0.
	 */
	public function find_product( $meta_key, $entity_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value = %d
                   AND p.post_type = 'product' AND p.post_status != 'trash'
                 LIMIT 1",
				$meta_key,
				$entity_id
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * @param string $meta_key META_SERVICE_ID or META_EVENT_ID.
	 * @return array<int,int> Published product ID => linked Amelia ID.
	 */
	private function find_published_products( $meta_key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by meta key on published products only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status = 'publish'",
				$meta_key
			)
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->post_id ] = (int) $row->meta_value;
		}

		return $out;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia exposes no public API; a direct query on its custom tables is required here.
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
