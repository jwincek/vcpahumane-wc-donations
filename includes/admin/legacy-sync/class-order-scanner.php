<?php
/**
 * Order Scanner - Readonly analysis of WooCommerce orders for sync eligibility.
 *
 * Extracted from Legacy_Order_Sync methods:
 * - scan_orders() (lines 371-477)
 * - analyze_order() (lines 488-559)
 * - get_sync_stats() (lines 180-222)
 * - count_synced_orders() (lines 231-258)
 * - count_processed_orders() (lines 267-273)
 * - get_all_syncable_order_ids() (lines 1253-1319)
 * - build_sync_preview() (lines 969-1027)
 * - describe_creation() (lines 1038-1074)
 *
 * No side effects — only reads orders and returns analysis data.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

use Starter_Shelter\Core\Config;
use Starter_Shelter\WooCommerce\Product_Mapper;
use Starter_Shelter\Admin\Shared\Donor_Lookup;

/**
 * Scans WooCommerce orders for legacy sync eligibility.
 *
 * @since 2.0.0
 */
class Order_Scanner {

	/**
	 * Meta key for tracking synced orders.
	 *
	 * @var string
	 */
	public const SYNCED_META_KEY = '_sd_legacy_synced';

	/**
	 * Get sync statistics.
	 *
	 * @since 2.0.0
	 *
	 * @return array{
	 *     total_orders: int,
	 *     synced_orders: int,
	 *     processed_orders: int,
	 *     unsynced_orders: int,
	 *     by_type: array,
	 *     last_scan: string
	 * }
	 */
	public static function get_stats(): array {
		global $wpdb;

		$total_orders = self::count_total_orders( $wpdb );
		$synced_orders = self::count_by_meta( $wpdb, self::SYNCED_META_KEY );
		$processed_orders = self::count_by_meta( $wpdb, '_sd_processed' );

		return [
			'total_orders'     => $total_orders,
			'synced_orders'    => $synced_orders,
			'processed_orders' => $processed_orders,
			'unsynced_orders'  => max( 0, $total_orders - $synced_orders - $processed_orders ),
			'by_type'          => [
				'donation'   => 0,
				'membership' => 0,
				'memorial'   => 0,
			],
			'last_scan' => get_option( 'sd_legacy_sync_last_scan', '' ),
		];
	}

	/**
	 * Scan orders for syncable items with pagination.
	 *
	 * @since 2.0.0
	 *
	 * @param array $filters  Filter parameters.
	 * @param int   $page     Page number.
	 * @param int   $per_page Items per page.
	 * @return array Scan results with orders, summary, and pagination.
	 */
	public static function scan( array $filters, int $page = 1, int $per_page = 50 ): array {
		$args = self::build_order_query( $filters, $per_page, ( $page - 1 ) * $per_page );
		$order_ids = wc_get_orders( $args );

		// Get total count.
		$total_args = self::build_order_query( $filters, -1, 0 );
		$total = count( wc_get_orders( $total_args ) );

		$orders  = [];
		$summary = [
			'total'     => 0,
			'synced'    => 0,
			'processed' => 0,
			'unsynced'  => 0,
			'by_type'   => [
				'donation'   => 0,
				'membership' => 0,
				'memorial'   => 0,
			],
		];

		foreach ( $order_ids as $order_id ) {
			$order_data = self::analyze_order( $order_id, $filters );

			if ( null === $order_data ) {
				continue;
			}

			if ( ! $filters['include_synced'] && 'synced' === $order_data['sync_status'] ) {
				continue;
			}

			if ( 'all' !== $filters['product_type'] ) {
				$has_type = false;
				foreach ( $order_data['items'] as $item ) {
					if ( $item['product_type'] === $filters['product_type'] ) {
						$has_type = true;
						break;
					}
				}
				if ( ! $has_type ) {
					continue;
				}
			}

			$orders[] = $order_data;

			// Update summary.
			$summary['total']++;
			$summary[ $order_data['sync_status'] ]++;

			foreach ( $order_data['items'] as $item ) {
				$type = $item['product_type'] ?? 'unknown';
				if ( isset( $summary['by_type'][ $type ] ) ) {
					$summary['by_type'][ $type ]++;
				}
			}
		}

		return [
			'orders'     => $orders,
			'summary'    => $summary,
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			],
		];
	}

	/**
	 * Get all syncable order IDs matching filters.
	 *
	 * Used by the "sync all" batch processor to get the full list of
	 * orders that need processing, then batch through them.
	 *
	 * @since 2.0.0
	 *
	 * @param array $filters      Filter parameters.
	 * @param bool  $force_resync Whether to include already synced orders.
	 * @return int[] Array of order IDs.
	 */
	public static function get_syncable_ids( array $filters, bool $force_resync = false ): array {
		$args = self::build_order_query( $filters, -1, 0 );

		// Exclude already synced unless forced.
		if ( ! $force_resync && empty( $filters['include_synced'] ) ) {
			$args['meta_query'] = [
				'relation' => 'AND',
				[
					'key'     => self::SYNCED_META_KEY,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_sd_processed',
					'compare' => 'NOT EXISTS',
				],
			];
		}

		$order_ids = wc_get_orders( $args );

		// Filter to only orders with shelter products.
		$syncable = [];
		foreach ( $order_ids as $order_id ) {
			$order_data = self::analyze_order( $order_id, $filters );
			if ( $order_data && ! empty( $order_data['items'] ) ) {
				if ( ! empty( $filters['product_type'] ) && 'all' !== $filters['product_type'] ) {
					$has_type = false;
					foreach ( $order_data['items'] as $item ) {
						if ( $item['product_type'] === $filters['product_type'] ) {
							$has_type = true;
							break;
						}
					}
					if ( ! $has_type ) {
						continue;
					}
				}
				$syncable[] = $order_id;
			}
		}

		return $syncable;
	}

	/**
	 * Analyze a single order for shelter products.
	 *
	 * Returns order metadata plus a list of shelter product line items,
	 * each with its matched product config and sync status.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $order_id The order ID.
	 * @param array $filters  Filter parameters (unused currently, reserved).
	 * @return array|null Order analysis data, or null if no shelter products.
	 */
	public static function analyze_order( int $order_id, array $filters = [] ): ?array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$items = [];
		$has_shelter_products = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product   = $item->get_product();
			$item_data = Item_Extractor::extract( $item, $product );

			if ( ! $item_data ) {
				continue;
			}

			$config = Item_Matcher::find_config( $item, $product, $item_data );

			if ( $config ) {
				$has_shelter_products = true;

				$items[] = [
					'item_id'      => $item_id,
					'product_id'   => $item_data['product_id'],
					'variation_id' => $item_data['variation_id'],
					'name'         => $item_data['name'],
					'sku'          => $item_data['sku'],
					'amount'       => (float) $item->get_total(),
					'product_type' => $config['product_type'] ?? 'unknown',
					'ability'      => $config['ability'] ?? '',
					'is_legacy'    => $config['legacy'] ?? true,
					'sku_prefix'   => $config['sku_prefix'] ?? '',
					'item_meta'    => $item_data['meta'],
				];
			}
		}

		if ( ! $has_shelter_products ) {
			return null;
		}

		$sync_status = 'unsynced';
		if ( $order->get_meta( self::SYNCED_META_KEY ) ) {
			$sync_status = 'synced';
		} elseif ( $order->get_meta( '_sd_processed' ) ) {
			$sync_status = 'processed';
		}

		return [
			'order_id'        => $order_id,
			'order_number'    => $order->get_order_number(),
			'date'            => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
			'date_formatted'  => $order->get_date_created() ? $order->get_date_created()->format( get_option( 'date_format' ) ) : '',
			'status'          => $order->get_status(),
			'total'           => (float) $order->get_total(),
			'total_formatted' => wp_strip_all_tags( wc_price( $order->get_total() ) ),
			'customer_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customer_email'  => $order->get_billing_email(),
			'items'           => $items,
			'sync_status'     => $sync_status,
			'synced_at'       => $order->get_meta( self::SYNCED_META_KEY ) ?: null,
			'edit_url'        => $order->get_edit_order_url(),
		];
	}

	/**
	 * Build a preview of what syncing an order would create.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order $order The order.
	 * @return array Preview data for the React UI.
	 */
	public static function build_preview( \WC_Order $order ): array {
		$preview = [
			'order_id' => $order->get_id(),
			'items'    => [],
			'donor'    => [
				'name'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email'    => $order->get_billing_email(),
				'existing' => null,
			],
		];

		// Check for existing donor via Donor_Lookup.
		$existing_id = Donor_Lookup::find_by_email( $order->get_billing_email() );
		if ( $existing_id ) {
			$preview['donor']['existing'] = [
				'id'   => $existing_id,
				'name' => get_the_title( $existing_id ),
			];
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product   = $item->get_product();
			$item_data = Item_Extractor::extract( $item, $product );

			if ( ! $item_data ) {
				continue;
			}

			$config = Item_Matcher::find_config( $item, $product, $item_data );

			if ( ! $config ) {
				continue;
			}

			$input = $product
				? Product_Mapper::build_input( $order, $item, $config )
				: Legacy_Input_Builder::build( $order, $item, $item_data, $config );

			$preview['items'][] = [
				'item_id'      => $item_id,
				'name'         => $item->get_name(),
				'product_type' => $config['product_type'] ?? 'unknown',
				'ability'      => $config['ability'] ?? '',
				'input'        => $input,
				'will_create'  => self::describe_creation( $config['product_type'] ?? '', $input ),
			];
		}

		return $preview;
	}

	/**
	 * Describe what a sync operation would create, in human-readable form.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type  Product type.
	 * @param array  $input Ability input.
	 * @return string Description string.
	 */
	public static function describe_creation( string $type, array $input ): string {
		return match ( $type ) {
			'donation' => sprintf(
				/* translators: 1: amount, 2: allocation */
				__( 'Donation of %1$s to %2$s', 'starter-shelter' ),
				wc_price( $input['amount'] ?? 0 ),
				ucwords( str_replace( '-', ' ', $input['allocation'] ?? 'General Fund' ) )
			),
			'membership' => sprintf(
				/* translators: 1: membership type, 2: tier */
				__( '%1$s Membership: %2$s', 'starter-shelter' ),
				( $input['membership_type'] ?? 'individual' ) === 'business'
					? __( 'Business', 'starter-shelter' )
					: __( 'Individual', 'starter-shelter' ),
				ucwords( str_replace( '-', ' ', $input['tier'] ?? 'unknown' ) )
			),
			'memorial' => sprintf(
				/* translators: 1: memorial type, 2: honoree name */
				__( '%1$s Memorial for %2$s', 'starter-shelter' ),
				ucfirst( $input['memorial_type'] ?? 'person' ),
				$input['honoree_name'] ?? __( 'Unknown', 'starter-shelter' )
			),
			default => __( 'Unknown record type', 'starter-shelter' ),
		};
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a wc_get_orders() query from filters.
	 *
	 * @since 2.0.0
	 *
	 * @param array $filters  Filter parameters.
	 * @param int   $limit    Results per page (-1 for all).
	 * @param int   $offset   Offset for pagination.
	 * @return array wc_get_orders args.
	 */
	private static function build_order_query( array $filters, int $limit, int $offset ): array {
		$args = [
			'type'    => 'shop_order',
			'status'  => [ 'wc-completed', 'wc-processing' ],
			'limit'   => $limit,
			'offset'  => $offset,
			'orderby' => 'date',
			'order'   => $limit === -1 ? 'ASC' : 'DESC',
			'return'  => 'ids',
		];

		if ( 'completed' === ( $filters['status'] ?? 'all' ) ) {
			$args['status'] = [ 'wc-completed' ];
		} elseif ( 'processing' === ( $filters['status'] ?? 'all' ) ) {
			$args['status'] = [ 'wc-processing' ];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$args['date_created'] = '>=' . strtotime( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$prefix = isset( $args['date_created'] ) ? $args['date_created'] . '...' : '';
			$args['date_created'] = $prefix . strtotime( $filters['date_to'] . ' 23:59:59' );
		}

		return $args;
	}

	/**
	 * Count total completed/processing orders, with HPOS support.
	 *
	 * @since 2.0.0
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 * @return int Total order count.
	 */
	private static function count_total_orders( \wpdb $wpdb ): int {
		// Check HPOS first.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
				return (int) $wpdb->get_var(
					"SELECT COUNT(DISTINCT id) FROM {$table}
					 WHERE type = 'shop_order'
					   AND status IN ('wc-completed', 'wc-processing')"
				);
			}
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			   AND post_status IN ('wc-completed', 'wc-processing')"
		);
	}

	/**
	 * Count orders with a specific meta key, with HPOS support.
	 *
	 * @since 2.0.0
	 *
	 * @param \wpdb  $wpdb     WordPress database object.
	 * @param string $meta_key The meta key to check.
	 * @return int Count of orders with this meta.
	 */
	private static function count_by_meta( \wpdb $wpdb, string $meta_key ): int {
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$meta_key
		) );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$meta_table = $wpdb->prefix . 'wc_orders_meta';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_table'" ) === $meta_table ) {
				$hpos_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT order_id) FROM {$meta_table} WHERE meta_key = %s",
					$meta_key
				) );
				$count = max( $count, $hpos_count );
			}
		}

		return $count;
	}
}
