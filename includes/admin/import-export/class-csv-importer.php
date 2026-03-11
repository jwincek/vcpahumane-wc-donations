<?php
/**
 * CSV Importer - Abilities-first import for all entity types.
 *
 * Replaces the four separate import processors and their create helpers.
 *
 * Calls abilities directly (not through AJAX). The Abilities API provides:
 * - Input validation via JSON Schema (single source of truth)
 * - Permission checks (via set_internal_processing() flag)
 * - All side effects: donor stats, email notifications, hooks
 * - Output validation
 *
 * This means the importer does NOT duplicate any record-creation logic.
 * It is purely: parse CSV → resolve donor → build input → call ability.
 *
 * For donor imports specifically, there is no "create donor" ability
 * (donors are a side effect of other operations), so upsert_donor()
 * handles direct creation/update via Donor_Lookup.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Admin\Shared\Donor_Lookup;
use Starter_Shelter\Helpers;

/**
 * Config-driven CSV importer.
 *
 * @since 2.0.0
 */
class CSV_Importer {

	/**
	 * Meta key for the import deduplication hash.
	 *
	 * Stored as post meta on every imported record. When re-importing,
	 * rows whose hash matches an existing record are skipped.
	 *
	 * The hash is: md5( entity_type + sorted key=value pairs from hash_columns ).
	 * This means two rows are "the same" only if every hash column matches exactly.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	public const HASH_META_KEY = '_sd_import_hash';

	/**
	 * Process a CSV import for any entity type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $entity_type Key from import-export.json entity_types.
	 * @param string $filepath    Path to the uploaded CSV file.
	 * @param array  $options {
	 *     Import options.
	 *
	 *     @type bool $create_donors   Create donors if not found (default true).
	 *     @type bool $update_existing Update existing records (donors only, default true).
	 *     @type bool $skip_errors     Skip rows with validation errors (default true).
	 * }
	 * @return array{
	 *     created: int,
	 *     updated: int,
	 *     skipped: int,
	 *     errors: int,
	 *     error_details: array
	 * }
	 */
	public static function process( string $entity_type, string $filepath, array $options = [] ): array {
		$config = Config::get_path( 'import-export', "entity_types.{$entity_type}.import" );

		if ( ! $config ) {
			return self::error_result( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		// Verify the ability exists (except for donor imports which don't use one).
		$ability_name = $config['ability'] ?? null;
		$is_donor     = ( 'upsert_donor' === ( $config['handler'] ?? null ) );

		if ( ! $is_donor && ! empty( $ability_name ) ) {
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
				return self::error_result(
					sprintf(
						/* translators: %s: ability name */
						__( 'Required ability "%s" is not registered. Ensure WordPress 6.9+ and abilities are loaded.', 'starter-shelter' ),
						$ability_name
					)
				);
			}
		}

		$validator        = new CSV_Validator( $entity_type );
		$skip_errors      = $options['skip_errors'] ?? true;
		$create_donors    = $options['create_donors'] ?? true;
		$update_existing  = $options['update_existing'] ?? true;
		$skip_duplicates  = $options['skip_duplicates'] ?? true;

		// Resolve hash columns from config (used for dedup).
		$hash_columns = $config['hash_columns'] ?? [];

		// Pre-load existing hashes for this entity type if dedup is active.
		$existing_hashes = [];
		if ( $skip_duplicates && ! empty( $hash_columns ) && ! $is_donor ) {
			$post_type       = Config::get_path( 'import-export', "entity_types.{$entity_type}.post_type" ) ?? '';
			$existing_hashes = self::load_existing_hashes( $post_type );
		}

		// Open and validate CSV structure.
		$csv = self::open_csv( $filepath, $validator, $entity_type );
		if ( is_wp_error( $csv ) ) {
			return self::error_result( $csv->get_error_message() );
		}

		$handle  = $csv['handle'];
		$headers = $csv['headers'];

		$results = [
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'errors'        => 0,
			'duplicates'    => 0,
			'error_details' => [],
		];

		// Enable internal processing so abilities accept non-WooCommerce callers.
		Helpers\set_internal_processing( true );

		try {
			$row_number = 1; // Row 1 was headers.

			while ( ( $data = fgetcsv( $handle ) ) !== false ) {
				$row_number++;

				$row = @array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
				if ( false === $row ) {
					$results['skipped']++;
					continue;
				}

				// Validate row structure (CSV-level checks: types, required fields, enums).
				$errors = $validator->validate_row( $row );
				if ( ! empty( $errors ) ) {
					self::record_error( $results, $row_number, implode( '; ', $errors ), $skip_errors );
					continue;
				}

				// Check import hash for duplicate detection (non-donor entities only).
				if ( $skip_duplicates && ! $is_donor && ! empty( $hash_columns ) ) {
					$hash = self::compute_row_hash( $entity_type, $row, $hash_columns );
					if ( isset( $existing_hashes[ $hash ] ) ) {
						$results['duplicates']++;
						$results['skipped']++;
						continue;
					}
				}

				// Process the row.
				$result = $is_donor
					? self::upsert_donor( $row, $update_existing )
					: self::import_entity_row( $row, $config, $create_donors, $entity_type, $hash_columns );

				self::tally_result( $results, $result, $row_number, $skip_errors );

				// Track the hash of successfully created records so later rows
				// in the same CSV don't duplicate each other.
				if ( ! is_wp_error( $result ) && ( $result['created'] ?? false ) && ! empty( $hash_columns ) ) {
					$hash = $hash ?? self::compute_row_hash( $entity_type, $row, $hash_columns );
					$existing_hashes[ $hash ] = true;
				}
			}
		} finally {
			Helpers\set_internal_processing( false );
		}

		fclose( $handle );
		return $results;
	}

	/**
	 * Generate a preview of the CSV file without creating records.
	 *
	 * @since 2.0.0
	 *
	 * @param string $entity_type Key from import-export.json.
	 * @param string $filepath    Path to the CSV file.
	 * @param int    $limit       Maximum preview rows (default 10).
	 * @return array Preview data for the React UI.
	 */
	public static function preview( string $entity_type, string $filepath, int $limit = 10 ): array {
		$validator = new CSV_Validator( $entity_type );

		$csv = self::open_csv( $filepath, $validator, $entity_type );
		if ( is_wp_error( $csv ) ) {
			return [ 'error' => $csv->get_error_message() ];
		}

		$handle  = $csv['handle'];
		$headers = $csv['headers'];

		$rows        = [];
		$valid_count = 0;
		$row_num     = 0;

		while ( ( $data = fgetcsv( $handle ) ) !== false && $row_num < 100 ) {
			$row_num++;
			$row = @array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
			if ( false === $row ) {
				continue;
			}

			$errors   = $validator->validate_row( $row );
			$is_valid = empty( $errors );

			if ( $is_valid ) {
				$valid_count++;
			}

			if ( count( $rows ) < $limit ) {
				$rows[] = [
					'data'  => $row,
					'valid' => $is_valid,
					'error' => $is_valid ? null : ( $errors[0] ?? null ),
				];
			}
		}

		// Count remaining rows (still validate, just don't collect preview data).
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			$row_num++;
			$row = @array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
			if ( false === $row ) {
				continue;
			}
			if ( empty( $validator->validate_row( $row ) ) ) {
				$valid_count++;
			}
		}

		fclose( $handle );

		return [
			'headers'   => $headers,
			'rows'      => $rows,
			'totalRows' => $row_num,
			'validRows' => $valid_count,
		];
	}

	/**
	 * Import a single entity row by calling its registered ability.
	 *
	 * The flow is:
	 * 1. Resolve donor via Donor_Lookup (once).
	 * 2. Build ability input from CSV field map.
	 * 3. Pass donor_id directly — the ability skips its own donor resolution.
	 * 4. Call wp_get_ability()->execute() — which validates input, checks permissions,
	 *    executes, validates output, and fires hooks.
	 *
	 * Because the ability handles all side effects (donor stats, display name
	 * denormalization, email notifications, taxonomy assignment), the importer
	 * does NOT duplicate any of that logic.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row           CSV row as associative array.
	 * @param array $config        Import config from import-export.json.
	 * @param bool  $create_donors Whether to create donors if not found.
	 * @return array|\WP_Error Import result.
	 */
	private static function import_entity_row(
		array $row,
		array $config,
		bool $create_donors,
		string $entity_type = '',
		array $hash_columns = []
	): array|\WP_Error {
		// 1. Resolve donor once here — pass donor_id to ability.
		$donor = Donor_Lookup::find_or_create(
			[
				'email'         => $row['email'] ?? '',
				'first_name'    => $row['first_name'] ?? '',
				'last_name'     => $row['last_name'] ?? '',
				'display_name'  => trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ),
				'import_source' => 'csv_import',
			],
			$create_donors
		);

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		// 2. Build ability input.
		$field_map = $config['field_map'] ?? [];
		$input     = self::build_ability_input( $row, $field_map );

		// 3. Pass donor_id directly so the ability skips its own donor lookup.
		$input['donor_id'] = $donor['id'];

		// 4. Execute the ability.
		$ability_name = $config['ability'];
		$ability = wp_get_ability( $ability_name );
		$result  = $ability ? $ability->execute( $input ) : new \WP_Error(
			'ability_not_found',
			sprintf( __( 'Ability "%s" could not be loaded.', 'starter-shelter' ), $ability_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 5. Store the import hash on the created post for future dedup.
		$created_id = is_array( $result )
			? ( $result['memorial_id'] ?? $result['donation_id'] ?? $result['membership_id'] ?? 0 )
			: (int) $result;

		if ( $created_id && ! empty( $hash_columns ) ) {
			$hash = self::compute_row_hash( $entity_type, $row, $hash_columns );
			update_post_meta( $created_id, self::HASH_META_KEY, $hash );
		}

		return [
			'created'       => true,
			'id'            => $created_id,
			'donor_created' => $donor['created'],
		];
	}

	/**
	 * Build ability input from CSV row using the config field map.
	 *
	 * Maps CSV column names to ability input keys, casting values to
	 * appropriate types. Skips donor-identity fields (email, first_name,
	 * last_name) since the importer resolves donors separately and
	 * passes donor_id directly.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row       The CSV row data.
	 * @param array $field_map The field_map from import config.
	 * @return array The ability input.
	 */
	private static function build_ability_input( array $row, array $field_map ): array {
		$input = [];

		// Fields the importer handles — don't pass to ability.
		$skip_targets = [ 'donor_email', 'first_name', 'last_name' ];

		foreach ( $field_map as $csv_col => $mapping ) {
			$value  = $row[ $csv_col ] ?? '';
			$target = $mapping['target'] ?? $csv_col;

			if ( in_array( $target, $skip_targets, true ) ) {
				continue;
			}

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$input[ $target ] = self::cast_value( $value, $mapping['validate'] ?? 'text' );
		}

		return $input;
	}

	/**
	 * Upsert a donor record (no ability — donors are infrastructure).
	 *
	 * @since 2.0.0
	 *
	 * @param array $row             The CSV row data.
	 * @param bool  $update_existing Whether to update existing donors.
	 * @return array|\WP_Error Import result.
	 */
	private static function upsert_donor( array $row, bool $update_existing ): array|\WP_Error {
		$email       = sanitize_email( $row['email'] ?? '' );
		$existing_id = Donor_Lookup::find_by_email( $email );

		if ( $existing_id ) {
			if ( $update_existing ) {
				self::update_donor_meta( $existing_id, $row );
				return [ 'updated' => true, 'id' => $existing_id ];
			}
			return [ 'skipped' => true ];
		}

		$result = Donor_Lookup::find_or_create( [
			'email'         => $email,
			'first_name'    => $row['first_name'] ?? '',
			'last_name'     => $row['last_name'] ?? '',
			'import_source' => 'csv_import',
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::update_donor_meta( $result['id'], $row );

		return [ 'created' => true, 'id' => $result['id'] ];
	}

	/**
	 * Update donor meta fields from a CSV row.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $donor_id The donor post ID.
	 * @param array $row      The CSV row data.
	 */
	private static function update_donor_meta( int $donor_id, array $row ): void {
		$meta_fields = [
			'first_name'     => '_sd_first_name',
			'last_name'      => '_sd_last_name',
			'email'          => '_sd_email',
			'phone'          => '_sd_phone',
			'address_line_1' => '_sd_address_line_1',
			'address_line_2' => '_sd_address_line_2',
			'city'           => '_sd_city',
			'state'          => '_sd_state',
			'postal_code'    => '_sd_postal_code',
			'country'        => '_sd_country',
		];

		foreach ( $meta_fields as $csv_col => $meta_key ) {
			$value = $row[ $csv_col ] ?? '';
			if ( '' !== trim( $value ) ) {
				update_post_meta( $donor_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		$display_name = Donor_Lookup::build_display_name(
			sanitize_text_field( $row['first_name'] ?? '' ),
			sanitize_text_field( $row['last_name'] ?? '' ),
			sanitize_email( $row['email'] ?? '' )
		);

		if ( ! empty( $display_name ) ) {
			update_post_meta( $donor_id, '_sd_display_name', $display_name );
			wp_update_post( [ 'ID' => $donor_id, 'post_title' => $display_name ] );
		}
	}

	/**
	 * Cast a CSV string value to the appropriate PHP type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value    The string value.
	 * @param string $validate The validation type from config.
	 * @return mixed The cast value.
	 */
	private static function cast_value( string $value, string $validate ): mixed {
		return match ( $validate ) {
			'positive_number' => (float) $value,
			'boolean_string'  => in_array( strtolower( trim( $value ) ), [ 'yes', '1', 'true' ], true ),
			'email'           => sanitize_email( $value ),
			'email_optional'  => ! empty( $value ) ? sanitize_email( $value ) : '',
			'url'             => esc_url_raw( $value ),
			'date'            => wp_date( 'Y-m-d H:i:s', strtotime( $value ) ) ?: $value,
			'text'            => sanitize_text_field( $value ),
			'enum'            => sanitize_key( $value ),
			default           => sanitize_text_field( $value ),
		};
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve export-to-import column name aliases.
	 *
	 * The export CSV uses human-readable headers ("Donor Email", "Honoree Name")
	 * while the import config uses machine keys ("email", "honoree_name").
	 * After the space → underscore normalization, some headers still don't
	 * match because they use different words entirely (e.g. "donor_email" vs
	 * "email", "type" vs "memorial_type").
	 *
	 * This method applies an alias map so that re-importing an export
	 * works without manual column renaming. Aliases are only applied when the
	 * header does NOT already match a known import column — this prevents
	 * clobbering intentional column names from hand-crafted CSVs.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $headers     Normalized header names (lowercase, underscored).
	 * @param string $entity_type The entity type being imported.
	 * @return array Headers with aliases resolved.
	 */
	private static function resolve_header_aliases( array $headers, string $entity_type = '' ): array {
		// Shared aliases (export name → import column key).
		static $shared = [
			'donor_email'     => 'email',
			'donor_name'      => '_donor_name',   // Display-only, not imported.
			'member_name'     => '_member_name',
			'order_id'        => '_order_id',
			'family_notified' => '_family_notified',
			'status'          => '_status',
			'display_name'    => '_display_name',
		];

		// Entity-specific aliases for the ambiguous "type" column.
		static $entity_aliases = [
			'memorials'   => [ 'type' => 'memorial_type' ],
			'memberships' => [ 'type' => 'membership_type' ],
			'donations'   => [ 'type' => '_type' ],  // Not imported.
		];

		$type_aliases = $entity_aliases[ $entity_type ] ?? [];
		$aliases      = array_merge( $shared, $type_aliases );

		return array_map( function ( string $header ) use ( $aliases ): string {
			return $aliases[ $header ] ?? $header;
		}, $headers );
	}

	/**
	 * Open a CSV file and validate its headers.
	 *
	 * @since 2.0.0
	 *
	 * @param string        $filepath  Path to CSV file.
	 * @param CSV_Validator $validator Validator instance.
	 * @return array{ handle: resource, headers: string[] }|\WP_Error
	 */
	private static function open_csv( string $filepath, CSV_Validator $validator, string $entity_type = '' ): array|\WP_Error {
		$handle = fopen( $filepath, 'r' );
		if ( ! $handle ) {
			return new \WP_Error( 'file_error', __( 'Could not open file.', 'starter-shelter' ) );
		}

		$raw_headers = fgetcsv( $handle );
		if ( ! $raw_headers ) {
			fclose( $handle );
			return new \WP_Error( 'empty_file', __( 'Empty file or invalid CSV.', 'starter-shelter' ) );
		}

		// Normalize: lowercase, trim, then spaces → underscores.
		// This bridges the gap between human-readable export headers
		// ("Honoree Name") and machine import column keys ("honoree_name").
		$headers = array_map( function ( string $h ): string {
			return str_replace( ' ', '_', strtolower( trim( $h ) ) );
		}, $raw_headers );

		// Resolve column aliases so re-importing an export "just works".
		// Export headers use human-friendly names that don't always match
		// the import field_map keys. This maps them without changing config.
		$headers = self::resolve_header_aliases( $headers, $entity_type );

		// Strip BOM from the first header if present.
		if ( ! empty( $headers[0] ) ) {
			$headers[0] = preg_replace( '/^\x{FEFF}/u', '', $headers[0] );
		}

		$missing = $validator->validate_headers( $headers );
		if ( ! empty( $missing ) ) {
			fclose( $handle );
			return new \WP_Error(
				'missing_columns',
				sprintf(
					__( 'Missing required columns: %s', 'starter-shelter' ),
					implode( ', ', $missing )
				)
			);
		}

		return [ 'handle' => $handle, 'headers' => $headers ];
	}

	/**
	 * Build an error-only result array.
	 *
	 * @since 2.0.0
	 *
	 * @param string $message Error message.
	 * @return array Result array.
	 */
	private static function error_result( string $message ): array {
		return [
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'errors'        => 1,
			'error_details' => [ [ 'row' => 0, 'message' => $message ] ],
		];
	}

	/**
	 * Record an error or skip in the results array.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $results     Results array (modified by reference).
	 * @param int    $row_number  The CSV row number.
	 * @param string $message     Error message.
	 * @param bool   $skip_errors Whether to count as skipped vs error.
	 */
	private static function record_error( array &$results, int $row_number, string $message, bool $skip_errors ): void {
		if ( $skip_errors ) {
			$results['skipped']++;
		} else {
			$results['errors']++;
			$results['error_details'][] = [
				'row'     => $row_number,
				'message' => $message,
			];
		}
	}

	// -------------------------------------------------------------------------
	// Import Hash (deduplication)
	// -------------------------------------------------------------------------

	/**
	 * Compute a deterministic hash from a CSV row.
	 *
	 * The hash is: md5( entity_type + sorted "col=normalized_value" pairs ).
	 * Only columns listed in hash_columns are included.
	 *
	 * Normalization ensures minor formatting differences don't create
	 * false non-matches:
	 * - Emails are lowercased and trimmed.
	 * - Amounts are cast to float and formatted to 2 decimal places.
	 * - Dates are normalized to Y-m-d.
	 * - Everything else is lowercased and trimmed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $entity_type  The entity type key (e.g. 'donations').
	 * @param array  $row          The CSV row as associative array.
	 * @param array  $hash_columns Column names to include in the hash.
	 * @return string The md5 hash.
	 */
	public static function compute_row_hash( string $entity_type, array $row, array $hash_columns ): string {
		$parts = [ $entity_type ];

		// Sort columns so order doesn't matter.
		$sorted_cols = $hash_columns;
		sort( $sorted_cols );

		foreach ( $sorted_cols as $col ) {
			$value = trim( (string) ( $row[ $col ] ?? '' ) );
			$value = self::normalize_hash_value( $col, $value );
			$parts[] = $col . '=' . $value;
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Compute a hash from stored post meta (for retroactive backfill).
	 *
	 * Used by Data_Integrity to generate hashes for records that were
	 * created before the hash feature was added. Reads the same fields
	 * from post meta instead of CSV columns.
	 *
	 * @since 2.1.0
	 *
	 * @param string $entity_type  The entity type key (e.g. 'donations').
	 * @param int    $post_id      The post ID.
	 * @param array  $hash_columns Column names (matching CSV column names).
	 * @return string The md5 hash.
	 */
	public static function compute_post_hash( string $entity_type, int $post_id, array $hash_columns ): string {
		// Map CSV column names → meta keys / post fields.
		// The hash columns use CSV column names, so we need to resolve
		// what meta key or relation each one maps to.
		$config = Config::get_path( 'import-export', "entity_types.{$entity_type}.import" );
		$field_map = $config['field_map'] ?? [];

		$row = [];
		foreach ( $hash_columns as $col ) {
			if ( 'email' === $col ) {
				// Email comes from the related donor, not from the post itself.
				$donor_id = (int) get_post_meta( $post_id, '_sd_donor_id', true );
				$row[ $col ] = $donor_id ? (string) get_post_meta( $donor_id, '_sd_email', true ) : '';
			} else {
				// Resolve the meta key from the field map.
				$mapping  = $field_map[ $col ] ?? [];
				$target   = $mapping['target'] ?? $col;
				$meta_key = '_sd_' . $target;
				$row[ $col ] = (string) get_post_meta( $post_id, $meta_key, true );
			}
		}

		return self::compute_row_hash( $entity_type, $row, $hash_columns );
	}

	/**
	 * Load all existing import hashes for a post type into a lookup set.
	 *
	 * One query, returns hash → true map for O(1) lookups.
	 *
	 * @since 2.1.0
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, true> Hash lookup map.
	 */
	private static function load_existing_hashes( string $post_type ): array {
		global $wpdb;

		if ( ! $post_type ) {
			return [];
		}

		// Single query: join posts → postmeta to get all hashes.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s
			 AND pm.meta_key = %s
			 AND pm.meta_value != ''",
			$post_type,
			self::HASH_META_KEY
		) );

		$map = [];
		foreach ( $rows as $hash ) {
			$map[ $hash ] = true;
		}

		return $map;
	}

	/**
	 * Normalize a value for consistent hashing.
	 *
	 * @since 2.1.0
	 *
	 * @param string $column The column name (for type inference).
	 * @param string $value  The raw value.
	 * @return string Normalized value.
	 */
	private static function normalize_hash_value( string $column, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Email columns: lowercase, trim.
		if ( 'email' === $column || str_contains( $column, 'email' ) ) {
			return strtolower( trim( $value ) );
		}

		// Amount columns: normalize to 2 decimal places.
		if ( 'amount' === $column ) {
			return number_format( (float) $value, 2, '.', '' );
		}

		// Date columns: normalize to Y-m-d.
		if ( in_array( $column, [ 'date', 'start_date', 'end_date' ], true ) || str_contains( $column, 'date' ) ) {
			$timestamp = strtotime( $value );
			return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : strtolower( trim( $value ) );
		}

		// Everything else: lowercase, trim.
		return strtolower( trim( $value ) );
	}

	/**
	 * Tally an import row result into the running totals.
	 *
	 * @since 2.0.0
	 *
	 * @param array              $results     Results array (modified by reference).
	 * @param array|\WP_Error    $result      The single-row result.
	 * @param int                $row_number  The CSV row number.
	 * @param bool               $skip_errors Whether to count errors as skips.
	 */
	private static function tally_result( array &$results, array|\WP_Error $result, int $row_number, bool $skip_errors ): void {
		if ( is_wp_error( $result ) ) {
			self::record_error( $results, $row_number, $result->get_error_message(), $skip_errors );
		} elseif ( $result['skipped'] ?? false ) {
			$results['skipped']++;
		} elseif ( $result['updated'] ?? false ) {
			$results['updated']++;
		} else {
			$results['created']++;
		}
	}
}
