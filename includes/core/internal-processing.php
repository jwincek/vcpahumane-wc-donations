<?php
/**
 * Internal processing helpers.
 *
 * Provides a safe mechanism for plugin code (importers, legacy sync, cron)
 * to execute abilities with 'internal' permission. Replaces the fragile
 * doing_action( 'woocommerce_order_status_completed' ) check in the
 * abilities provider.
 *
 * Usage:
 *   Helpers\set_internal_processing( true );
 *   try {
 *       $result = wp_get_ability( 'shelter-donations/create' )->execute( $input );
 *   } finally {
 *       Helpers\set_internal_processing( false );
 *   }
 *
 * @package Starter_Shelter
 * @subpackage Core
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Helpers;

/**
 * Enable or disable internal processing mode.
 *
 * When enabled, abilities with 'internal' permission will accept
 * execution outside of WooCommerce order hooks.
 *
 * Always wrap in try/finally to ensure the flag is reset even on errors.
 *
 * @since 2.0.0
 *
 * @param bool $state Whether to enable (true) or disable (false) internal processing.
 */
function set_internal_processing( bool $state = true ): void {
	$GLOBALS['_sd_internal_processing'] = $state;
}

/**
 * Check if internal processing mode is currently active.
 *
 * @since 2.0.0
 *
 * @return bool Whether internal processing is active.
 */
function is_internal_processing(): bool {
	return ! empty( $GLOBALS['_sd_internal_processing'] );
}
