<?php
/**
 * Item Matcher - Matches WooCommerce order items to product config.
 *
 * Extracted from Legacy_Order_Sync methods:
 * - find_config_for_item() (lines 705-749) — the 5-strategy orchestrator
 * - find_config_by_product_id() (lines 760-794) — sync config lookup
 * - find_config_by_item_meta() (lines 805-873) — attribute-based inference
 * - find_config_by_name() (lines 883-930) — name pattern matching
 *
 * Uses a cascade of strategies to identify which shelter product type
 * an order item represents, even when the product has been deleted,
 * renamed, or its SKU changed. Strategies from most to least reliable:
 *
 * 1. SKU match via Product_Mapper
 * 2. Product ID match via sync config
 * 3. Name pattern match
 * 4. Parent product fallback (for variations)
 * 5. Item meta/attribute inference
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

use Starter_Shelter\Core\Config;
use Starter_Shelter\WooCommerce\Product_Mapper;

/**
 * Matches WooCommerce order items to shelter product configurations.
 *
 * @since 2.0.0
 */
class Item_Matcher {

	/**
	 * Name patterns for product identification.
	 *
	 * Order matters — more specific patterns must come first.
	 * The key is the substring to match in the lowercased item name;
	 * the value is the SKU prefix from products.json config.
	 *
	 * @var array<string, string>
	 */
	private const NAME_PATTERNS = [
		// Business memberships (must precede general memberships).
		'shelter business memberships' => 'shelter-memberships-business',
		'business memberships'         => 'shelter-memberships-business',
		'business membership'          => 'shelter-memberships-business',

		// Individual memberships.
		'shelter memberships'          => 'shelter-memberships',
		'shelter membership'           => 'shelter-memberships',

		// Legacy memberships (no "shelter" prefix).
		'memberships -'                => 'memberships',
		'membership -'                 => 'memberships',

		// Donations.
		'shelter donations'            => 'shelter-donations',
		'shelter donation'             => 'shelter-donations',

		// In Memoriam.
		'in memoriam donations'        => 'shelter-donations-in-memoriam',
		'in memoriam donation'         => 'shelter-donations-in-memoriam',
		'in memoriam'                  => 'shelter-donations-in-memoriam',
		'memorial donation'            => 'shelter-donations-in-memoriam',
		'memorial'                     => 'shelter-donations-in-memoriam',
	];

	/**
	 * Find the product configuration for an order item.
	 *
	 * Tries five strategies in order from most to least reliable.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order_Item_Product $item      The order item.
	 * @param \WC_Product|false      $product   The product (may be false).
	 * @param array                  $item_data Extracted item data from Item_Extractor.
	 * @return array|null Product config array or null if no match.
	 */
	public static function find_config( $item, $product, array $item_data ): ?array {
		// Strategy 1: SKU match via Product_Mapper.
		if ( $product && ! empty( $item_data['sku'] ) ) {
			$config = Product_Mapper::find_by_sku( $item_data['sku'] );
			if ( $config ) {
				return $config;
			}
		}

		// Strategy 2: Product ID match via sync config.
		$config = self::find_by_product_id( $item_data['product_id'], $item_data['variation_id'] );
		if ( $config ) {
			return $config;
		}

		// Strategy 3: Name pattern match.
		$config = self::find_by_name( $item_data['name'] );
		if ( $config ) {
			return $config;
		}

		// Strategy 4: Parent product fallback for variations.
		if ( $item_data['variation_id'] && $item_data['product_id'] ) {
			$config = self::find_by_product_id( $item_data['product_id'], 0 );
			if ( $config ) {
				return $config;
			}

			$parent = wc_get_product( $item_data['product_id'] );
			if ( $parent ) {
				$parent_sku = $parent->get_sku();
				if ( $parent_sku ) {
					$config = Product_Mapper::find_by_sku( $parent_sku );
					if ( $config ) {
						return $config;
					}
				}
				$config = self::find_by_name( $parent->get_name() );
				if ( $config ) {
					return $config;
				}
			}
		}

		// Strategy 5: Infer from item meta (variation attributes).
		if ( ! empty( $item_data['meta'] ) ) {
			return self::find_by_item_meta( $item_data['meta'], $item_data['name'] );
		}

		return null;
	}

	/**
	 * Match by product/variation ID via the sync config.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id   The product ID.
	 * @param int $variation_id The variation ID (0 for parent).
	 * @return array|null Product config or null.
	 */
	private static function find_by_product_id( int $product_id, int $variation_id ): ?array {
		$sync_config = Config::get_path( 'sync', 'product_mappings.by_product_id', [] );
		$products    = Config::get_item( 'products', 'products', [] );

		$ids_to_check = array_filter( [ $variation_id, $product_id ] );

		foreach ( $ids_to_check as $id ) {
			$id_string = (string) $id;
			if ( ! isset( $sync_config[ $id_string ] ) ) {
				continue;
			}

			$mapping = $sync_config[ $id_string ];

			// Follow parent reference if present.
			$sku_prefix = isset( $mapping['parent'] )
				? ( $sync_config[ $mapping['parent'] ]['maps_to'] ?? '' )
				: ( $mapping['maps_to'] ?? '' );

			if ( $sku_prefix && isset( $products[ $sku_prefix ] ) ) {
				return array_merge(
					$products[ $sku_prefix ],
					[
						'sku_prefix'   => $sku_prefix,
						'legacy'       => true,
						'sync_mapping' => $mapping,
					]
				);
			}
		}

		return null;
	}

	/**
	 * Match by product name using pattern table.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Product or item name.
	 * @return array|null Product config or null.
	 */
	private static function find_by_name( string $name ): ?array {
		$name_lower = strtolower( $name );
		$products   = Config::get_item( 'products', 'products', [] );

		foreach ( self::NAME_PATTERNS as $pattern => $sku_prefix ) {
			if ( str_contains( $name_lower, $pattern ) && isset( $products[ $sku_prefix ] ) ) {
				return array_merge(
					$products[ $sku_prefix ],
					[
						'sku_prefix'      => $sku_prefix,
						'legacy'          => true,
						'matched_by'      => 'name_pattern',
						'matched_pattern' => $pattern,
					]
				);
			}
		}

		return null;
	}

	/**
	 * Infer product type from item meta (variation attributes).
	 *
	 * This is the least reliable strategy — it looks at WooCommerce
	 * attribute values to guess which product type the item belongs to.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $meta Item meta data.
	 * @param string $name Item name (for disambiguation).
	 * @return array|null Product config or null.
	 */
	private static function find_by_item_meta( array $meta, string $name ): ?array {
		$products   = Config::get_item( 'products', 'products', [] );
		$name_lower = strtolower( $name );

		// Membership level attribute → membership.
		$membership_level = $meta['pa_membership-level']
			?? $meta['membership-level']
			?? $meta['Membership Level']
			?? null;

		if ( $membership_level ) {
			$sku_prefix = match ( true ) {
				str_contains( $name_lower, 'business' )
					=> 'shelter-memberships-business',
				str_contains( $name_lower, 'shelter' )
					=> 'shelter-memberships',
				default => 'memberships',
			};

			if ( isset( $products[ $sku_prefix ] ) ) {
				return array_merge(
					$products[ $sku_prefix ],
					[ 'sku_prefix' => $sku_prefix, 'legacy' => true ]
				);
			}
		}

		// Memorial type attribute → memorial.
		$memorial_type = $meta['pa_in-memoriam-type']
			?? $meta['in-memoriam-type']
			?? $meta['In Memoriam Type']
			?? null;

		if ( $memorial_type || str_contains( $name_lower, 'memoriam' ) || str_contains( $name_lower, 'memorial' ) ) {
			if ( isset( $products['shelter-donations-in-memoriam'] ) ) {
				return array_merge(
					$products['shelter-donations-in-memoriam'],
					[ 'sku_prefix' => 'shelter-donations-in-memoriam', 'legacy' => true ]
				);
			}
		}

		// Allocation attribute → donation.
		$allocation = $meta['pa_preferred-allocation']
			?? $meta['preferred-allocation']
			?? $meta['Preferred Allocation']
			?? null;

		if ( $allocation || str_contains( $name_lower, 'shelter donations' ) ) {
			if ( isset( $products['shelter-donations'] ) ) {
				return array_merge(
					$products['shelter-donations'],
					[ 'sku_prefix' => 'shelter-donations', 'legacy' => true ]
				);
			}
		}

		return null;
	}
}
