<?php
/**
 * Item Extractor - Extracts structured data from WooCommerce order items.
 *
 * Extracted from Legacy_Order_Sync::extract_item_data() (lines 574-693).
 *
 * Handles the complex reality of WooCommerce item data:
 * - Product exists: use product object for SKU
 * - Product deleted: fall back to stored item meta, parent product
 * - Variation exists but parent doesn't: check _sku meta
 * - Neither exists: use whatever WooCommerce stored on the order item
 *
 * Pure data extraction — returns a structured array. No config matching.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

/**
 * Extracts structured data from WooCommerce order items.
 *
 * @since 2.0.0
 */
class Item_Extractor {

	/**
	 * Attribute keys to check for legacy WooCommerce variation data.
	 *
	 * These cover multiple naming conventions across WooCommerce versions
	 * and the shelter's product configuration history.
	 *
	 * @var string[]
	 */
	private const LEGACY_ATTRIBUTE_KEYS = [
		'pa_membership-level',
		'membership-level',
		'Membership Level',
		'pa_preferred-allocation',
		'preferred-allocation',
		'Preferred Allocation',
		'pa_in-memoriam-type',
		'in-memoriam-type',
		'In Memoriam Type',
	];

	/**
	 * Extract structured data from an order item.
	 *
	 * Returns name, IDs, SKU, meta, and normalized attributes. Works even
	 * when the product has been deleted from WooCommerce, because order
	 * items store their own copy of the data.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order_Item_Product $item    The order item.
	 * @param \WC_Product|false      $product The product (may be false if deleted).
	 * @return array|null Structured item data, or null if unusable.
	 */
	public static function extract( $item, $product ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}

		$data = [
			'name'         => $item->get_name(),
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'quantity'     => $item->get_quantity(),
			'sku'          => '',
			'meta'         => [],
			'attributes'   => [],
		];

		// Resolve SKU — multiple strategies depending on product state.
		$data['sku'] = self::resolve_sku( $item, $product, $data );

		// Extract meta and attributes.
		self::extract_meta( $item, $data );
		self::extract_legacy_attributes( $item, $data );

		if ( empty( $data['name'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Resolve the SKU using multiple fallback strategies.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order_Item_Product $item    The order item.
	 * @param \WC_Product|false      $product The product.
	 * @param array                  $data    Current item data (for IDs).
	 * @return string The resolved SKU (may be empty).
	 */
	private static function resolve_sku( $item, $product, array $data ): string {
		// Strategy 1: Live product object.
		if ( $product && $product instanceof \WC_Product ) {
			$sku = $product->get_sku();

			// For variations without SKU, try parent.
			if ( empty( $sku ) && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$data['parent_sku']  = $parent->get_sku();
					$data['parent_name'] = $parent->get_name();
					return $parent->get_sku();
				}
			}

			return $sku;
		}

		// Strategy 2: WooCommerce stored SKU as item meta.
		$stored_sku = $item->get_meta( '_sku', true );
		if ( $stored_sku ) {
			return $stored_sku;
		}

		// Strategy 3: Load parent product for orphaned variations.
		if ( $data['variation_id'] && $data['product_id'] ) {
			$parent = wc_get_product( $data['product_id'] );
			if ( $parent && $parent instanceof \WC_Product ) {
				$data['parent_sku']  = $parent->get_sku();
				$data['parent_name'] = $parent->get_name();
				return $parent->get_sku();
			}
		}

		// Strategy 4: Try product_id directly (might be parent).
		if ( $data['product_id'] ) {
			$maybe_parent = wc_get_product( $data['product_id'] );
			if ( $maybe_parent && $maybe_parent instanceof \WC_Product ) {
				$data['parent_sku']  = $maybe_parent->get_sku();
				$data['parent_name'] = $maybe_parent->get_name();
				return $maybe_parent->get_sku();
			}
		}

		return '';
	}

	/**
	 * Extract visible meta and formatted attributes from the item.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order_Item_Product $item The order item.
	 * @param array                  $data Item data (modified by reference).
	 */
	private static function extract_meta( $item, array &$data ): void {
		// Raw meta — skip internal WooCommerce keys except _sd_ prefix.
		foreach ( $item->get_meta_data() as $meta ) {
			$key = $meta->key;
			if ( str_starts_with( $key, '_' ) && ! str_starts_with( $key, '_sd_' ) ) {
				continue;
			}
			$data['meta'][ $key ] = $meta->value;
		}

		// Formatted meta (variation attributes as displayed by WooCommerce).
		foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
			$data['meta'][ $meta->key ] = $meta->value;

			// Normalize for matching: "Membership Level" → "membership_level".
			$normalized = strtolower( str_replace( [ ' ', '-' ], '_', $meta->key ) );
			$data['attributes'][ $normalized ] = $meta->value;
		}
	}

	/**
	 * Extract legacy WooCommerce attribute values.
	 *
	 * Older WooCommerce versions stored variation attributes with
	 * different key formats. This checks all known patterns.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order_Item_Product $item The order item.
	 * @param array                  $data Item data (modified by reference).
	 */
	private static function extract_legacy_attributes( $item, array &$data ): void {
		foreach ( self::LEGACY_ATTRIBUTE_KEYS as $key ) {
			$value = $item->get_meta( $key, true );
			if ( $value ) {
				$data['meta'][ $key ] = $value;
				$normalized = strtolower( str_replace( [ ' ', '-', 'pa_' ], [ '_', '_', '' ], $key ) );
				$data['attributes'][ $normalized ] = $value;
			}
		}
	}
}
