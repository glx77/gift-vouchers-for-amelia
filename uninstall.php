<?php
/**
 * Uninstall cleanup.
 *
 * Only removes our own settings. Products, the "Bon cadeau" page and any
 * generated Amelia coupons are user data and are intentionally left intact.
 *
 * @package GiftVouchersForAmelia
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gvfa_settings' );
