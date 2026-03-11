<?php
/**
 * Donor Lookup - Shared find-or-create logic for all import paths.
 *
 * Consolidates donor matching/creation that was previously duplicated in:
 * - Import_Export::process_donor_import()
 * - Import_Export::process_donation_import()
 * - Import_Export::process_memorial_import()
 * - Import_Export::process_membership_import()
 * - Import_Export::find_or_create_legacy_donor()
 * - Legacy_Order_Sync::find_donor_by_email()
 * - Helpers\get_or_create_donor()
 *
 * @package Starter_Shelter
 * @subpackage Admin\Shared
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Shared;

/**
 * Centralized donor lookup and creation.
 *
 * Handles two distinct lookup strategies:
 * 1. By email (standard imports, order sync) — most reliable.
 * 2. By display name (legacy memorial CSV) — name-only data with no email.
 *
 * @since 2.0.0
 */
class Donor_Lookup {

	/**
	 * Find a donor by email address.
	 *
	 * @since 2.0.0
	 *
	 * @param string $email The donor email.
	 * @return int|null Donor post ID or null if not found.
	 */
	public static function find_by_email( string $email ): ?int {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return null;
		}

		$posts = get_posts( [
			'post_type'      => 'sd_donor',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_sd_email',
					'value' => sanitize_email( $email ),
				],
			],
			'fields' => 'ids',
		] );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Find a donor by display name.
	 *
	 * Used for legacy data imports where email is not available.
	 *
	 * WordPress can encode special characters (e.g. & → &amp;) in post_title
	 * via sanitize_post(). We use a direct DB query to match the raw stored
	 * title reliably, avoiding WP_Query's title parameter which can mismatch
	 * when names contain &, quotes, or other HTML-significant characters.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name The donor display name to search for.
	 * @return int|null Donor post ID or null if not found.
	 */
	public static function find_by_name( string $name ): ?int {
		if ( empty( $name ) ) {
			return null;
		}

		global $wpdb;

		// Strategy 1: Match the stored title exactly as provided.
		$donor_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'sd_donor'
			   AND post_status IN ( 'publish', 'draft', 'private' )
			   AND post_title = %s
			 LIMIT 1",
			$name
		) );

		if ( $donor_id ) {
			return (int) $donor_id;
		}

		// Strategy 2: Try HTML-encoded version for names with special chars.
		// WordPress may store "John & Mary" as "John &amp; Mary" in post_title.
		$encoded_name = wp_specialchars_decode( $name );
		if ( $encoded_name !== $name ) {
			$donor_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donor'
				   AND post_status IN ( 'publish', 'draft', 'private' )
				   AND post_title = %s
				 LIMIT 1",
				$encoded_name
			) );

			if ( $donor_id ) {
				return (int) $donor_id;
			}
		}

		// Strategy 3: Try the WP-encoded form (the input might be raw while DB is encoded).
		$wp_encoded = esc_html( $name );
		if ( $wp_encoded !== $name ) {
			$donor_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donor'
				   AND post_status IN ( 'publish', 'draft', 'private' )
				   AND post_title = %s
				 LIMIT 1",
				$wp_encoded
			) );

			if ( $donor_id ) {
				return (int) $donor_id;
			}
		}

		// Strategy 4: Fall back to display_name meta for donors that were
		// previously created with a display_name but whose post_title was
		// mangled by encoding.
		$donor_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'sd_donor'
			   AND p.post_status IN ( 'publish', 'draft', 'private' )
			   AND pm.meta_key = '_sd_display_name'
			   AND pm.meta_value = %s
			 LIMIT 1",
			$name
		) );

		return $donor_id ? (int) $donor_id : null;
	}

	/**
	 * Find or create a donor record.
	 *
	 * For email-based lookups (standard imports): matches by email, creates
	 * with full name parsing if not found.
	 *
	 * For name-based lookups (legacy memorial CSV): matches by display name,
	 * creates without email if not found.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data {
	 *     Donor data for lookup/creation.
	 *
	 *     @type string $email         Email for primary lookup. Empty for legacy name-only data.
	 *     @type string $first_name    First name (parsed from display_name if not provided).
	 *     @type string $last_name     Last name (parsed from display_name if not provided).
	 *     @type string $display_name  Full display name. Required for name-based lookups.
	 *     @type bool   $is_anonymous  Whether this is an anonymous donor.
	 *     @type string $import_source Source identifier for auditing (e.g. 'legacy_memorial_csv').
	 *     @type array  $extra_meta    Additional meta to set on creation.
	 * }
	 * @param bool $create_if_missing Whether to create a new donor if not found.
	 * @return array{ id: int, created: bool }|\WP_Error
	 */
	public static function find_or_create( array $data, bool $create_if_missing = true ): array|\WP_Error {
		$email        = $data['email'] ?? '';
		$display_name = $data['display_name'] ?? '';
		$is_anonymous = $data['is_anonymous'] ?? false;

		// Handle anonymous donors: use a single shared record.
		if ( $is_anonymous ) {
			return self::find_or_create_anonymous( $data['import_source'] ?? '' );
		}

		// Strategy 1: Lookup by email (preferred).
		if ( ! empty( $email ) && is_email( $email ) ) {
			$donor_id = self::find_by_email( $email );

			if ( $donor_id ) {
				self::maybe_backfill_display_name( $donor_id, $data );
				return [ 'id' => $donor_id, 'created' => false ];
			}

			if ( ! $create_if_missing ) {
				return new \WP_Error(
					'donor_not_found',
					sprintf(
						/* translators: %s: email address */
						__( 'Donor not found: %s', 'starter-shelter' ),
						$email
					)
				);
			}

			return self::create_from_email( $data );
		}

		// Strategy 2: Lookup by display name (legacy data without email).
		if ( ! empty( $display_name ) ) {
			$donor_id = self::find_by_name( $display_name );

			if ( $donor_id ) {
				self::maybe_backfill_display_name( $donor_id, $data );
				return [ 'id' => $donor_id, 'created' => false ];
			}

			if ( ! $create_if_missing ) {
				return new \WP_Error(
					'donor_not_found',
					sprintf(
						/* translators: %s: donor name */
						__( 'Donor not found: %s', 'starter-shelter' ),
						$display_name
					)
				);
			}

			return self::create_from_name( $data );
		}

		return new \WP_Error(
			'insufficient_data',
			__( 'Either email or display_name is required to find or create a donor.', 'starter-shelter' )
		);
	}

	/**
	 * Find or create the shared anonymous donor record.
	 *
	 * @since 2.0.0
	 *
	 * @param string $import_source Source identifier for auditing.
	 * @return array{ id: int, created: bool }|\WP_Error
	 */
	private static function find_or_create_anonymous( string $import_source = '' ): array|\WP_Error {
		// Check for existing anonymous donor via meta flag first (most reliable).
		$existing = get_posts( [
			'post_type'      => 'sd_donor',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_sd_is_anonymous',
					'value' => '1',
				],
				[
					'key'   => '_sd_email',
					'value' => 'anonymous@example.com',
				],
			],
			'fields' => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return [ 'id' => $existing[0], 'created' => false ];
		}

		// Fallback: check by title.
		$existing = self::find_by_name( 'Anonymous' );
		if ( $existing ) {
			return [ 'id' => $existing, 'created' => false ];
		}

		$donor_id = wp_insert_post( [
			'post_type'   => 'sd_donor',
			'post_status' => 'publish',
			'post_title'  => 'Anonymous',
			'meta_input'  => [
				'_sd_email'        => 'anonymous@example.com',
				'_sd_display_name' => 'Anonymous',
				'_sd_is_anonymous' => 1,
				'_sd_import_source' => $import_source ?: 'system',
			],
		], true );

		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		return [ 'id' => $donor_id, 'created' => true ];
	}

	/**
	 * Create a new donor from email-based import data.
	 *
	 * Parses first/last name from provided fields or from display_name.
	 *
	 * @since 2.0.0
	 *
	 * @param array $data Donor data.
	 * @return array{ id: int, created: bool }|\WP_Error
	 */
	private static function create_from_email( array $data ): array|\WP_Error {
		$email      = sanitize_email( $data['email'] );
		$first_name = sanitize_text_field( $data['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $data['last_name'] ?? '' );

		// If first/last not provided, try parsing from display_name.
		if ( empty( $first_name ) && ! empty( $data['display_name'] ) ) {
			$parsed     = self::parse_name( $data['display_name'] );
			$first_name = $parsed['first'];
			$last_name  = $parsed['last'];
		}

		$display_name = self::build_display_name( $first_name, $last_name, $email );

		$meta = array_merge( [
			'_sd_email'           => $email,
			'_sd_display_name'    => $display_name,
			'_sd_first_name'      => $first_name,
			'_sd_last_name'       => $last_name,
			'_sd_lifetime_giving' => 0,
			'_sd_created_date'    => wp_date( 'Y-m-d H:i:s' ),
		], $data['extra_meta'] ?? [] );

		// Phone.
		if ( ! empty( $data['phone'] ) ) {
			$meta['_sd_phone'] = sanitize_text_field( $data['phone'] );
		}

		// Address — store as both serialized object and individual keys for
		// compatibility with the abilities pipeline and admin meta boxes.
		if ( ! empty( $data['address'] ) && is_array( $data['address'] ) ) {
			$address = array_filter( array_map( 'sanitize_text_field', $data['address'] ) );
			if ( ! empty( $address ) ) {
				$meta['_sd_address'] = $address;

				// Also store as flat keys for meta box compatibility.
				$flat_map = [
					'address_1' => '_sd_address_line_1',
					'address_2' => '_sd_address_line_2',
					'city'      => '_sd_city',
					'state'     => '_sd_state',
					'postcode'  => '_sd_postal_code',
					'country'   => '_sd_country',
				];
				foreach ( $flat_map as $key => $meta_key ) {
					if ( ! empty( $address[ $key ] ) ) {
						$meta[ $meta_key ] = $address[ $key ];
					}
				}
			}
		}

		if ( ! empty( $data['import_source'] ) ) {
			$meta['_sd_import_source'] = sanitize_key( $data['import_source'] );
		}

		$donor_id = wp_insert_post( [
			'post_type'   => 'sd_donor',
			'post_status' => 'publish',
			'post_title'  => $display_name,
			'meta_input'  => $meta,
		], true );

		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		return [ 'id' => $donor_id, 'created' => true ];
	}

	/**
	 * Create a new donor from name-only legacy data (no email).
	 *
	 * Uses wp_kses() for sanitization instead of sanitize_text_field() to
	 * preserve special characters like & and quotes that appear in donor names
	 * such as "John & Mary Smith" or "O'Brien Family".
	 *
	 * @since 2.0.0
	 *
	 * @param array $data Donor data with at minimum 'display_name'.
	 * @return array{ id: int, created: bool }|\WP_Error
	 */
	private static function create_from_name( array $data ): array|\WP_Error {
		$raw_name = $data['display_name'] ?? '';

		// Sanitize: strip HTML tags but preserve &, quotes, and special chars.
		// sanitize_text_field() aggressively encodes & and strips some chars,
		// which breaks names like "John & Mary Smith". wp_kses with no allowed
		// tags strips HTML while preserving special characters in text content.
		$display_name = self::sanitize_display_name( $raw_name );

		if ( empty( $display_name ) ) {
			return new \WP_Error(
				'empty_name',
				__( 'Donor display name is empty after sanitization.', 'starter-shelter' )
			);
		}

		$parsed = self::parse_name( $display_name );

		$meta = [
			'_sd_email'        => '', // No email in legacy data.
			'_sd_display_name' => $display_name,
			'_sd_first_name'   => $parsed['first'],
			'_sd_last_name'    => $parsed['last'],
		];

		if ( ! empty( $data['import_source'] ) ) {
			$meta['_sd_import_source'] = sanitize_key( $data['import_source'] );
		}

		if ( ! empty( $data['extra_meta'] ) ) {
			$meta = array_merge( $meta, $data['extra_meta'] );
		}

		$donor_id = wp_insert_post( [
			'post_type'   => 'sd_donor',
			'post_status' => 'publish',
			'post_title'  => $display_name,
			'meta_input'  => $meta,
		], true );

		if ( is_wp_error( $donor_id ) ) {
			return $donor_id;
		}

		return [ 'id' => $donor_id, 'created' => true ];
	}

	/**
	 * Backfill display_name meta on an existing donor if missing.
	 *
	 * Legacy donors or donors created by older code may not have the
	 * _sd_display_name meta set, which the front-end memorial wall needs.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $donor_id The donor post ID.
	 * @param array $data     The incoming data with potential name info.
	 */
	private static function maybe_backfill_display_name( int $donor_id, array $data ): void {
		$current = get_post_meta( $donor_id, '_sd_display_name', true );

		if ( ! empty( $current ) ) {
			return;
		}

		// Determine the best display name from available data.
		$display_name = '';

		if ( ! empty( $data['display_name'] ) ) {
			$display_name = self::sanitize_display_name( $data['display_name'] );
		} elseif ( ! empty( $data['first_name'] ) ) {
			$display_name = self::build_display_name(
				sanitize_text_field( $data['first_name'] ),
				sanitize_text_field( $data['last_name'] ?? '' )
			);
		}

		if ( empty( $display_name ) ) {
			// Try the post title as last resort.
			$post = get_post( $donor_id );
			if ( $post ) {
				$display_name = $post->post_title;
			}
		}

		if ( ! empty( $display_name ) ) {
			update_post_meta( $donor_id, '_sd_display_name', $display_name );
		}
	}

	/**
	 * Sanitize a display name preserving special characters.
	 *
	 * Unlike sanitize_text_field() which encodes & and strips octets,
	 * this preserves characters that commonly appear in real names:
	 * - Ampersands: "John & Mary Smith"
	 * - Apostrophes: "O'Brien", "The Smith's"
	 * - Quotes in pet names: '"Hector" Mewes'
	 * - Accented characters: "José García"
	 * - Hyphens: "Anne-Marie"
	 *
	 * Still strips HTML tags, extra whitespace, and control characters.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Raw display name.
	 * @return string Sanitized display name.
	 */
	public static function sanitize_display_name( string $name ): string {
		// Strip HTML tags but keep text content (preserves &, quotes, etc.).
		$name = wp_strip_all_tags( $name );

		// Remove control characters (null bytes, etc.) but keep printable chars.
		$name = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $name );

		// Collapse multiple spaces into one.
		$name = preg_replace( '/\s+/', ' ', $name );

		// Trim leading/trailing whitespace.
		$name = trim( $name );

		// Limit length to prevent abuse (255 chars = post_title column limit).
		if ( mb_strlen( $name ) > 255 ) {
			$name = mb_substr( $name, 0, 255 );
		}

		return $name;
	}

	/**
	 * Parse a full name string into first and last name.
	 *
	 * Handles common patterns:
	 * - "John Smith" → first: John, last: Smith
	 * - "John" → first: John, last: (empty)
	 * - "John Michael Smith" → first: John, last: Michael Smith
	 * - "The Smith Family" → first: The Smith, last: Family
	 *   (imperfect but acceptable for legacy data)
	 *
	 * @since 2.0.0
	 *
	 * @param string $full_name The full name string.
	 * @return array{ first: string, last: string }
	 */
	public static function parse_name( string $full_name ): array {
		$full_name = trim( $full_name );

		if ( empty( $full_name ) ) {
			return [ 'first' => '', 'last' => '' ];
		}

		$parts = explode( ' ', $full_name, 2 );

		return [
			'first' => $parts[0] ?? '',
			'last'  => $parts[1] ?? '',
		];
	}

	/**
	 * Build a display name from components.
	 *
	 * @since 2.0.0
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @param string $fallback   Fallback if both names are empty (e.g. email).
	 * @return string The display name.
	 */
	public static function build_display_name( string $first_name, string $last_name, string $fallback = '' ): string {
		$name = trim( "$first_name $last_name" );

		if ( ! empty( $name ) ) {
			return $name;
		}

		// If the fallback looks like an email address, don't use it as a
		// display name — it would surface as PII on the public memorial
		// wall, in exports, admin lists, and search indexes. Instead,
		// derive a name-like string from the local part (before the @).
		if ( ! empty( $fallback ) && str_contains( $fallback, '@' ) ) {
			$local = substr( $fallback, 0, (int) strpos( $fallback, '@' ) );
			// Turn "john.doe" or "john_doe" into "John Doe".
			$local = str_replace( [ '.', '_', '-' ], ' ', $local );
			$humanized = mb_convert_case( trim( $local ), MB_CASE_TITLE, 'UTF-8' );
			return $humanized ?: __( 'Anonymous Donor', 'starter-shelter' );
		}

		return $fallback ?: __( 'Anonymous Donor', 'starter-shelter' );
	}
}
