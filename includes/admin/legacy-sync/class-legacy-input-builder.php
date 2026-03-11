<?php
/**
 * Legacy Input Builder - Builds ability input for orphaned WooCommerce products.
 *
 * Extracted from Legacy_Order_Sync methods:
 * - build_legacy_input() (lines 1525-1633)
 * - extract_tier_from_item() (lines 1859-1903)
 * - extract_memorial_type_from_item() (lines 1913-1945)
 * - extract_allocation_from_item() (lines 1955-1987)
 * - normalize_allocation_value() (lines 1997-2008)
 *
 * For current products, Product_Mapper::build_input() is used. This class
 * handles the legacy path: order items where the WooCommerce product has
 * been deleted or doesn't match any current product. It extracts data from
 * order meta, item meta, variation attributes, and item names.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

use Starter_Shelter\Helpers;

/**
 * Builds ability input from legacy order data.
 *
 * @since 2.0.0
 */
class Legacy_Input_Builder {

	/**
	 * Allocation value normalizations.
	 *
	 * @var array<string, string>
	 */
	private const ALLOCATION_MAP = [
		'general fund'         => 'general-fund',
		'spay / neuter clinic' => 'spay-neuter-clinic',
		'spay/neuter clinic'   => 'spay-neuter-clinic',
		'spay neuter clinic'   => 'spay-neuter-clinic',
	];

	/**
	 * Membership tier patterns to match in item names.
	 *
	 * @var array<string, string[]>
	 */
	private const TIER_NAME_PATTERNS = [
		'single'       => [ 'single membership', 'single - ' ],
		'family'       => [ 'family membership', 'family - ' ],
		'contributing' => [ 'contributing membership', 'contributing - ' ],
		'supporting'   => [ 'supporting membership', 'supporting - ' ],
		'donor'        => [ 'donor membership', 'donor - ' ],
		'sustaining'   => [ 'sustaining membership', 'sustaining - ' ],
		'patron'       => [ 'patron membership', 'patron - ' ],
		'benefactor'   => [ 'benefactor membership', 'benefactor - ' ],
	];

	/**
	 * Build ability input for a legacy order item.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order              $order     The order.
	 * @param \WC_Order_Item_Product $item      The order item.
	 * @param array                  $item_data Extracted item data from Item_Extractor.
	 * @param array                  $config    Product config from Item_Matcher.
	 * @return array Ability input array.
	 */
	public static function build( \WC_Order $order, \WC_Order_Item_Product $item, array $item_data, array $config ): array {
		$order_date  = $order->get_date_created();
		$date_string = $order_date ? $order_date->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

		$input = [
			'order_id'    => $order->get_id(),
			'amount'      => (float) $item->get_total(),
			'donor_email' => $order->get_billing_email(),
			'donor_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'date'        => $date_string,
		];

		$product_type = $config['product_type'] ?? '';

		// Type-specific field extraction.
		match ( $product_type ) {
			'membership' => self::extract_membership_fields( $input, $order, $item_data, $config ),
			'memorial'   => self::extract_memorial_fields( $input, $order, $item, $item_data ),
			'donation'   => self::extract_donation_fields( $input, $order, $item_data ),
			default      => null,
		};

		// Anonymous flag.
		$is_anonymous = $order->get_meta( '_sd_is_anonymous' );
		if ( $is_anonymous ) {
			$input['is_anonymous'] = (bool) $is_anonymous;
		}

		/**
		 * Filters the legacy input before ability execution.
		 *
		 * @since 1.0.0
		 *
		 * @param array                  $input     The ability input.
		 * @param \WC_Order              $order     The order.
		 * @param \WC_Order_Item_Product $item      The order item.
		 * @param array                  $item_data Extracted item data.
		 * @param array                  $config    Product config.
		 */
		return apply_filters( 'starter_shelter_legacy_sync_input', $input, $order, $item, $item_data, $config );
	}

	/**
	 * Extract membership-specific fields.
	 *
	 * @since 2.0.0
	 *
	 * @param array     $input     Ability input (modified by reference).
	 * @param \WC_Order $order     The order.
	 * @param array     $item_data Extracted item data.
	 * @param array     $config    Product config.
	 */
	private static function extract_membership_fields( array &$input, \WC_Order $order, array $item_data, array $config ): void {
		$tier = self::extract_tier( $item_data );
		if ( $tier ) {
			$input['tier'] = $tier;
		}

		$input['membership_type'] = $config['sync_mapping']['membership_type']
			?? ( str_contains( strtolower( $item_data['name'] ), 'business' ) ? 'business' : 'individual' );

		if ( 'business' === $input['membership_type'] ) {
			$input['business_name'] = $order->get_billing_company() ?: $input['donor_name'];
		}
	}

	/**
	 * Extract memorial-specific fields.
	 *
	 * @since 2.0.0
	 *
	 * @param array                  $input     Ability input (modified by reference).
	 * @param \WC_Order              $order     The order.
	 * @param \WC_Order_Item_Product $item      The order item.
	 * @param array                  $item_data Extracted item data.
	 */
	private static function extract_memorial_fields( array &$input, \WC_Order $order, \WC_Order_Item_Product $item, array $item_data ): void {
		$memorial_type = self::extract_memorial_type( $item_data );
		$input['memorial_type'] = $memorial_type ?: 'person';

		// Honoree name — cascading fallback.
		$honoree_name = $item->get_meta( 'In Memory Of', true )
			?: ( $item->get_meta( 'in-memory-of', true )
			?: ( $item->get_meta( 'Honoree Name', true )
			?: ( $item_data['meta']['In Memory Of'] ?? '' ) ) );

		if ( empty( $honoree_name ) ) {
			$honoree_name = $order->get_meta( '_sd_honoree_name' )
				?: __( 'In Loving Memory', 'starter-shelter' );
		}
		$input['honoree_name'] = $honoree_name;

		// Tribute message.
		$tribute = $item->get_meta( 'Tribute Message', true )
			?: ( $item->get_meta( 'tribute-message', true )
			?: ( $item_data['meta']['Tribute Message'] ?? '' ) );

		if ( empty( $tribute ) ) {
			$tribute = $order->get_meta( '_sd_tribute_message' );
		}

		// Fallback: legacy orders stored the tribute in the customer note field.
		if ( empty( $tribute ) ) {
			$customer_note = $order->get_customer_note();
			if ( ! empty( $customer_note ) ) {
				$tribute = $customer_note;
			}
		}

		if ( $tribute ) {
			$input['tribute_message'] = $tribute;
		}

		// Pet species for pet memorials.
		if ( 'pet' === $memorial_type ) {
			$pet_species = $item->get_meta( 'Pet Species', true )
				?: ( $item->get_meta( 'pet-species', true )
				?: ( $item_data['meta']['Pet Species'] ?? '' ) );

			if ( empty( $pet_species ) ) {
				$pet_species = $order->get_meta( '_sd_pet_species' ) ?: '';
			}
			$input['pet_species'] = $pet_species;
		}
	}

	/**
	 * Extract donation-specific fields.
	 *
	 * @since 2.0.0
	 *
	 * @param array     $input     Ability input (modified by reference).
	 * @param \WC_Order $order     The order.
	 * @param array     $item_data Extracted item data.
	 */
	private static function extract_donation_fields( array &$input, \WC_Order $order, array $item_data ): void {
		$allocation = self::extract_allocation( $item_data );
		$input['allocation'] = $allocation ?: 'general-fund';

		$dedication = $order->get_meta( '_sd_dedication' );
		if ( $dedication ) {
			$input['dedication'] = $dedication;
		}
	}

	// -------------------------------------------------------------------------
	// Field extraction helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract membership tier from item data.
	 *
	 * Checks sync mapping → attributes → meta → item name.
	 *
	 * @since 2.0.0
	 *
	 * @param array $item_data Extracted item data.
	 * @return string|null Tier slug or null.
	 */
	private static function extract_tier( array $item_data ): ?string {
		if ( ! empty( $item_data['sync_mapping']['tier'] ) ) {
			return $item_data['sync_mapping']['tier'];
		}

		// Normalized attributes.
		foreach ( [ 'membership_level', 'membershiplevel', 'tier' ] as $key ) {
			if ( ! empty( $item_data['attributes'][ $key ] ) ) {
				return Helpers\normalize_tier( $item_data['attributes'][ $key ] );
			}
		}

		// Raw meta.
		foreach ( [ 'pa_membership-level', 'membership-level', 'Membership Level' ] as $key ) {
			if ( ! empty( $item_data['meta'][ $key ] ) ) {
				return Helpers\normalize_tier( $item_data['meta'][ $key ] );
			}
		}

		// Item name.
		$name_lower = strtolower( $item_data['name'] ?? '' );
		foreach ( self::TIER_NAME_PATTERNS as $tier => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( str_contains( $name_lower, $pattern ) ) {
					return $tier;
				}
			}
		}

		return null;
	}

	/**
	 * Extract memorial type from item data.
	 *
	 * @since 2.0.0
	 *
	 * @param array $item_data Extracted item data.
	 * @return string|null Memorial type or null.
	 */
	private static function extract_memorial_type( array $item_data ): ?string {
		if ( ! empty( $item_data['sync_mapping']['memorial_type'] ) ) {
			return $item_data['sync_mapping']['memorial_type'];
		}

		foreach ( [ 'in_memoriam_type', 'inmemoriamtype', 'memorial_type' ] as $key ) {
			if ( ! empty( $item_data['attributes'][ $key ] ) ) {
				return strtolower( $item_data['attributes'][ $key ] );
			}
		}

		foreach ( [ 'pa_in-memoriam-type', 'in-memoriam-type', 'In Memoriam Type' ] as $key ) {
			if ( ! empty( $item_data['meta'][ $key ] ) ) {
				return strtolower( $item_data['meta'][ $key ] );
			}
		}

		$name_lower = strtolower( $item_data['name'] ?? '' );
		if ( str_contains( $name_lower, '- pet' ) || str_contains( $name_lower, 'pet memorial' ) ) {
			return 'pet';
		}
		if ( str_contains( $name_lower, '- person' ) || str_contains( $name_lower, 'person memorial' ) ) {
			return 'person';
		}

		return null;
	}

	/**
	 * Extract allocation from item data.
	 *
	 * @since 2.0.0
	 *
	 * @param array $item_data Extracted item data.
	 * @return string|null Allocation slug or null.
	 */
	private static function extract_allocation( array $item_data ): ?string {
		if ( ! empty( $item_data['sync_mapping']['allocation'] ) ) {
			return $item_data['sync_mapping']['allocation'];
		}

		foreach ( [ 'preferred_allocation', 'preferredallocation', 'allocation' ] as $key ) {
			if ( ! empty( $item_data['attributes'][ $key ] ) ) {
				return self::normalize_allocation( $item_data['attributes'][ $key ] );
			}
		}

		foreach ( [ 'pa_preferred-allocation', 'preferred-allocation', 'Preferred Allocation' ] as $key ) {
			if ( ! empty( $item_data['meta'][ $key ] ) ) {
				return self::normalize_allocation( $item_data['meta'][ $key ] );
			}
		}

		$name_lower = strtolower( $item_data['name'] ?? '' );
		if ( str_contains( $name_lower, 'spay' ) || str_contains( $name_lower, 'neuter' ) ) {
			return 'spay-neuter-clinic';
		}
		if ( str_contains( $name_lower, 'general' ) ) {
			return 'general-fund';
		}

		return null;
	}

	/**
	 * Normalize an allocation display value to its slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value Raw allocation value.
	 * @return string Normalized slug.
	 */
	private static function normalize_allocation( string $value ): string {
		$key = strtolower( trim( $value ) );
		return self::ALLOCATION_MAP[ $key ] ?? sanitize_title( $value );
	}
}
