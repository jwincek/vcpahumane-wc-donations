<?php
/**
 * Legacy Sync Admin Page + AJAX Handlers.
 *
 * Thin shell that replaces the old Legacy_Order_Sync monolith:
 * - Menu registration + asset enqueuing
 * - 7 AJAX handlers that delegate to Order_Scanner / Order_Processor
 *
 * All business logic lives in the extracted classes.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Admin\Legacy_Sync\{ Order_Scanner, Order_Processor };
use Starter_Shelter\Core\Config;

/**
 * Legacy order sync admin page.
 *
 * @since 2.0.0
 */
class Legacy_Sync_Page {

	/**
	 * Page slug.
	 */
	private const PAGE_SLUG = 'starter-shelter-legacy-sync';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'sd_legacy_sync';

	/**
	 * Batch size for sync-all processing.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Initialize the page and AJAX handlers.
	 *
	 * @since 2.0.0
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

		// AJAX handlers — same action names for JS compatibility.
		add_action( 'wp_ajax_sd_scan_legacy_orders', [ self::class, 'ajax_scan' ] );
		add_action( 'wp_ajax_sd_preview_legacy_order', [ self::class, 'ajax_preview' ] );
		add_action( 'wp_ajax_sd_sync_legacy_orders', [ self::class, 'ajax_sync' ] );
		add_action( 'wp_ajax_sd_sync_all_legacy_orders', [ self::class, 'ajax_sync_all' ] );
		add_action( 'wp_ajax_sd_get_sync_stats', [ self::class, 'ajax_get_stats' ] );
		add_action( 'wp_ajax_sd_reset_sync_status', [ self::class, 'ajax_reset' ] );
		add_action( 'wp_ajax_sd_debug_order', [ self::class, 'ajax_debug' ] );
	}

	/**
	 * Add submenu page.
	 *
	 * @since 2.0.0
	 */
	public static function add_menu_page(): void {
		add_submenu_page(
			Menu::MENU_SLUG,
			__( 'Legacy Order Sync', 'starter-shelter' ),
			__( 'Legacy Sync', 'starter-shelter' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		$expected = Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG;

		if ( strpos( $hook, self::PAGE_SLUG ) === false && $hook !== $expected ) {
			return;
		}

		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_style( 'wp-components' );

		wp_enqueue_script(
			'sd-legacy-sync',
			STARTER_SHELTER_URL . 'assets/js/admin-legacy-sync.js',
			[ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
			STARTER_SHELTER_VERSION,
			true
		);

		$products = Config::get_item( 'products', 'products', [] );
		$mappings = [];
		foreach ( $products as $sku => $config ) {
			$mappings[ $sku ] = [
				'label'    => $config['description'] ?? ucfirst( str_replace( '-', ' ', $sku ) ),
				'type'     => $config['product_type'] ?? 'unknown',
				'ability'  => $config['ability'] ?? '',
				'isLegacy' => $config['legacy'] ?? false,
			];
		}

		wp_localize_script( 'sd-legacy-sync', 'sdLegacySync', [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
			'batchSize'       => self::BATCH_SIZE,
			'stats'           => Order_Scanner::get_stats(),
			'productMappings' => $mappings,
			'strings'         => [
				'scanning'     => __( 'Scanning orders...', 'starter-shelter' ),
				'syncing'      => __( 'Syncing orders...', 'starter-shelter' ),
				'complete'     => __( 'Sync complete!', 'starter-shelter' ),
				'error'        => __( 'An error occurred.', 'starter-shelter' ),
				'confirmSync'  => __( 'Are you sure you want to sync these orders? This will create donation records.', 'starter-shelter' ),
				'confirmReset' => __( 'Are you sure you want to reset sync status? This will allow orders to be synced again.', 'starter-shelter' ),
			],
		] );

		wp_add_inline_style( 'wp-components', self::get_styles() );
	}

	/**
	 * Render page.
	 *
	 * @since 2.0.0
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'starter-shelter' ) );
		}
		?>
		<div class="wrap sd-legacy-sync">
			<h1><?php esc_html_e( 'Legacy Order Sync', 'starter-shelter' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Sync past WooCommerce orders to create donation, membership, and memorial records.', 'starter-shelter' ); ?>
			</p>
			<div id="sd-legacy-sync-app"></div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers — thin shells that delegate to extracted classes
	// -------------------------------------------------------------------------

	/**
	 * Scan for syncable orders.
	 */
	public static function ajax_scan(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$filters = self::extract_filters();
		$page     = absint( $_POST['page'] ?? 1 );
		$per_page = absint( $_POST['per_page'] ?? 50 );

		$result = Order_Scanner::scan( $filters, $page, $per_page );

		update_option( 'sd_legacy_sync_last_scan', current_time( 'mysql' ) );

		wp_send_json_success( $result );
	}

	/**
	 * Preview a single order.
	 */
	public static function ajax_preview(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'starter-shelter' ) );
		}

		wp_send_json_success( Order_Scanner::build_preview( $order ) );
	}

	/**
	 * Sync selected orders.
	 */
	public static function ajax_sync(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$order_ids    = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];
		$skip_errors  = ! empty( $_POST['skip_errors'] );
		$dry_run      = ! empty( $_POST['dry_run'] );
		$force_resync = ! empty( $_POST['force_resync'] );

		if ( empty( $order_ids ) ) {
			wp_send_json_error( __( 'No orders selected.', 'starter-shelter' ) );
		}

		$results = [
			'processed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
			'created'   => [ 'donations' => 0, 'memberships' => 0, 'memorials' => 0, 'donors' => 0 ],
			'details'   => [],
		];

		foreach ( $order_ids as $order_id ) {
			$result = Order_Processor::sync( $order_id, $dry_run, $force_resync );

			if ( is_wp_error( $result ) ) {
				$results['errors']++;
				$results['details'][] = [
					'order_id' => $order_id,
					'status'   => 'error',
					'message'  => $result->get_error_message(),
				];
				if ( ! $skip_errors ) {
					break;
				}
			} elseif ( $result['skipped'] ?? false ) {
				$results['skipped']++;
				$results['details'][] = [
					'order_id' => $order_id,
					'status'   => 'skipped',
					'message'  => $result['reason'] ?? '',
				];
			} else {
				$results['processed']++;
				foreach ( [ 'donations', 'memberships', 'memorials', 'donors' ] as $key ) {
					$results['created'][ $key ] += $result['created'][ $key ] ?? 0;
				}
				// Count per-item errors (e.g. ability validation failures).
				if ( ! empty( $result['errors'] ) ) {
					$results['errors'] += count( $result['errors'] );
				}
				$results['details'][] = [
					'order_id' => $order_id,
					'status'   => 'success',
					'created'  => $result['created'] ?? [],
					'errors'   => $result['errors'] ?? [],
				];
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Sync all matching orders in batches.
	 */
	public static function ajax_sync_all(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$filters      = self::extract_filters();
		$force_resync = ! empty( $_POST['force_resync'] );
		$batch_size   = absint( $_POST['batch_size'] ?? 50 );
		$offset       = absint( $_POST['offset'] ?? 0 );

		$order_ids = Order_Scanner::get_syncable_ids( $filters, $force_resync );
		$total     = count( $order_ids );

		if ( 0 === $total ) {
			wp_send_json_success( [
				'complete'  => true,
				'processed' => 0,
				'total'     => 0,
				'message'   => __( 'No orders to sync.', 'starter-shelter' ),
			] );
		}

		$batch = array_slice( $order_ids, $offset, $batch_size );

		if ( empty( $batch ) ) {
			wp_send_json_success( [
				'complete'  => true,
				'processed' => $offset,
				'total'     => $total,
				'message'   => __( 'Sync complete.', 'starter-shelter' ),
			] );
		}

		$results = [
			'processed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
			'created'   => [ 'donations' => 0, 'memberships' => 0, 'memorials' => 0, 'donors' => 0, 'updated' => 0 ],
		];

		foreach ( $batch as $order_id ) {
			$result = Order_Processor::sync( $order_id, false, $force_resync );

			if ( is_wp_error( $result ) ) {
				$results['errors']++;
			} elseif ( $result['skipped'] ?? false ) {
				$results['skipped']++;
			} else {
				$results['processed']++;
				foreach ( [ 'donations', 'memberships', 'memorials', 'donors', 'updated' ] as $key ) {
					$results['created'][ $key ] += $result['created'][ $key ] ?? 0;
				}
				// Count per-item errors (e.g. ability validation failures).
				if ( ! empty( $result['errors'] ) ) {
					$results['errors'] += count( $result['errors'] );
				}
			}
		}

		$new_offset = $offset + count( $batch );

		wp_send_json_success( [
			'complete'  => $new_offset >= $total,
			'processed' => $results['processed'],
			'skipped'   => $results['skipped'],
			'errors'    => $results['errors'],
			'created'   => $results['created'],
			'batch_size' => count( $batch ),
			'offset'    => $new_offset,
			'total'     => $total,
			'progress'  => round( ( $new_offset / $total ) * 100 ),
		] );
	}

	/**
	 * Get sync stats.
	 */
	public static function ajax_get_stats(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}
		wp_send_json_success( Order_Scanner::get_stats() );
	}

	/**
	 * Reset sync status.
	 */
	public static function ajax_reset(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];
		$reset_all = ! empty( $_POST['reset_all'] );

		if ( $reset_all ) {
			global $wpdb;

			$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => Order_Scanner::SYNCED_META_KEY ] );
			$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_sd_legacy_sync_results' ] );

			// HPOS support.
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$meta_table = $wpdb->prefix . 'wc_orders_meta';
				if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_table'" ) === $meta_table ) {
					$wpdb->delete( $meta_table, [ 'meta_key' => Order_Scanner::SYNCED_META_KEY ] );
					$wpdb->delete( $meta_table, [ 'meta_key' => '_sd_legacy_sync_results' ] );
				}
			}

			wp_send_json_success( [ 'message' => __( 'All sync status has been reset.', 'starter-shelter' ) ] );
		} elseif ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->delete_meta_data( Order_Scanner::SYNCED_META_KEY );
					$order->delete_meta_data( '_sd_legacy_sync_results' );
					$order->save();
				}
			}
			wp_send_json_success( [
				'message' => sprintf(
					__( 'Sync status reset for %d order(s).', 'starter-shelter' ),
					count( $order_ids )
				),
			] );
		} else {
			wp_send_json_error( __( 'No orders specified.', 'starter-shelter' ) );
		}
	}

	/**
	 * Debug a single order.
	 */
	public static function ajax_debug(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'starter-shelter' ) );
		}

		$debug = [
			'order_id'     => $order_id,
			'order_number' => $order->get_order_number(),
			'status'       => $order->get_status(),
			'items'        => [],
		];

		foreach ( $order->get_items() as $item_id => $item ) {
			$item_debug = [ 'item_id' => $item_id, 'item_class' => get_class( $item ) ];

			if ( ! $item instanceof \WC_Order_Item_Product ) {
				$item_debug['error'] = 'Not a WC_Order_Item_Product';
				$debug['items'][] = $item_debug;
				continue;
			}

			$product   = $item->get_product();
			$item_data = Legacy_Sync\Item_Extractor::extract( $item, $product );

			$item_debug['name']           = $item->get_name();
			$item_debug['product_exists'] = (bool) $product;
			$item_debug['extracted_data'] = $item_data;

			// Try each matching strategy.
			$final = Legacy_Sync\Item_Matcher::find_config( $item, $product, $item_data ?? [] );
			$item_debug['final_config'] = $final ? [
				'ability'    => $final['ability'] ?? '',
				'type'       => $final['product_type'] ?? '',
				'sku_prefix' => $final['sku_prefix'] ?? '',
				'matched_by' => $final['matched_by'] ?? 'unknown',
			] : null;

			$debug['items'][] = $item_debug;
		}

		wp_send_json_success( $debug );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract filter parameters from POST data.
	 *
	 * @since 2.0.0
	 *
	 * @return array Sanitized filters.
	 */
	private static function extract_filters(): array {
		return [
			'status'         => sanitize_text_field( $_POST['status'] ?? 'all' ),
			'date_from'      => sanitize_text_field( $_POST['date_from'] ?? '' ),
			'date_to'        => sanitize_text_field( $_POST['date_to'] ?? '' ),
			'product_type'   => sanitize_text_field( $_POST['product_type'] ?? 'all' ),
			'include_synced' => ! empty( $_POST['include_synced'] ),
		];
	}

	/**
	 * Minimal inline styles.
	 *
	 * @since 2.0.0
	 *
	 * @return string CSS.
	 */
	private static function get_styles(): string {
		return '
			.sd-legacy-sync .components-card { margin-bottom: 20px; }
			.sd-legacy-sync .sd-stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 16px; margin-bottom: 24px;
			}
			.sd-legacy-sync .sd-stat-card {
				background: #fff; border: 1px solid #e0e0e0;
				border-radius: 4px; padding: 16px; text-align: center;
			}
			.sd-legacy-sync .sd-stat-number { font-size: 24px; font-weight: 600; }
			.sd-legacy-sync .sd-stat-label { font-size: 12px; color: #757575; margin-top: 4px; }
		';
	}
}
