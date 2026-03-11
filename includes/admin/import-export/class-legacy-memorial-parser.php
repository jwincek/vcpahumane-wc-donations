<?php
/**
 * Legacy Memorial Parser - Handles the shelter's custom CSV format.
 *
 * The shelter maintains memorial records in a specific CSV format:
 * - Column A: "In Memory Of" (honoree name)
 * - Column B: "By" (donor display name)
 * - Column C: Optional "pet" indicator
 * - Month names as section header rows
 * - No email addresses or donation amounts
 *
 * This format is used regularly by the shelter and cannot be normalized
 * to the standard import format. The parser handles:
 * - Month-header detection for date assignment
 * - Pet name quote preservation (e.g. "Hector" Mewes)
 * - Donor names with special characters (&, quotes, accented chars)
 * - Anonymous donor detection and shared record reuse
 * - Duplicate detection for re-imports
 *
 * Extracted from class-import-export.php (lines 2027-2497).
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Admin\Shared\Donor_Lookup;
use Starter_Shelter\Helpers;

/**
 * Parses and imports the shelter's legacy memorial CSV format.
 *
 * @since 2.0.0
 */
class Legacy_Memorial_Parser {

	/**
	 * Month name to number mapping.
	 *
	 * @var array<string, int>
	 */
	private const MONTH_MAP = [
		'january'   => 1,
		'february'  => 2,
		'march'     => 3,
		'april'     => 4,
		'may'       => 5,
		'june'      => 6,
		'july'      => 7,
		'august'    => 8,
		'september' => 9,
		'october'   => 10,
		'november'  => 11,
		'december'  => 12,
	];

	/**
	 * Import source identifier for auditing.
	 */
	private const IMPORT_SOURCE = 'legacy_memorial_csv';

	/**
	 * Parse the legacy memorial CSV file into structured row data.
	 *
	 * Does NOT create any records — only reads and validates the CSV structure.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filepath Path to the CSV file.
	 * @param int    $year     The year for dating the memorials.
	 * @return array{
	 *     rows: array,
	 *     months_found: string[],
	 *     pet_count: int,
	 *     person_count: int
	 * }|\WP_Error Parsed data or error.
	 */
	public static function parse( string $filepath, int $year ): array|\WP_Error {
		$handle = fopen( $filepath, 'r' );

		if ( ! $handle ) {
			return new \WP_Error(
				'file_error',
				__( 'Could not open file.', 'starter-shelter' )
			);
		}

		$rows          = [];
		$months_found  = [];
		$current_month = null;
		$pet_count     = 0;
		$person_count  = 0;
		$line_number   = 0;

		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			$line_number++;

			// Skip completely empty rows.
			$non_empty = array_filter( $data, fn( $cell ) => '' !== trim( (string) $cell ) );
			if ( empty( $non_empty ) ) {
				continue;
			}

			$col_a = isset( $data[0] ) ? trim( (string) $data[0] ) : '';
			$col_b = isset( $data[1] ) ? trim( (string) $data[1] ) : '';
			$col_c = isset( $data[2] ) ? strtolower( trim( (string) $data[2] ) ) : '';

			// Skip header row (e.g. "In Memory Of", "By").
			if ( 1 === $line_number && stripos( $col_a, 'memory' ) !== false ) {
				continue;
			}

			// Check if this is a month header row.
			$col_a_lower = strtolower( $col_a );
			if ( isset( self::MONTH_MAP[ $col_a_lower ] ) && empty( $col_b ) ) {
				$current_month = self::MONTH_MAP[ $col_a_lower ];
				$months_found[] = ucfirst( $col_a_lower );
				continue;
			}

			// Skip rows without both honoree and donor.
			if ( empty( $col_a ) || empty( $col_b ) ) {
				continue;
			}

			// Clean up honoree name (preserves quotes in pet names).
			$honoree_name = self::clean_honoree_name( $col_a );

			// Clean up donor name (preserves &, quotes, special chars).
			$donor_name = self::clean_donor_name( $col_b );

			// Determine memorial type from column C.
			$is_pet = ( 'pet' === $col_c );
			if ( $is_pet ) {
				$pet_count++;
			} else {
				$person_count++;
			}

			// Build date from month and year.
			$month = $current_month ?? 1;
			$date  = sprintf( '%04d-%02d-01', $year, $month );

			// Check if donor is anonymous.
			$is_anonymous = self::is_anonymous_name( $donor_name );

			$rows[] = [
				'line_number'   => $line_number,
				'honoree_name'  => $honoree_name,
				'donor_name'    => $donor_name,
				'memorial_type' => $is_pet ? 'pet' : 'person',
				'month'         => $current_month
					? date( 'F', mktime( 0, 0, 0, $current_month, 1 ) )
					: 'Unknown',
				'date'          => $date,
				'is_anonymous'  => $is_anonymous,
			];
		}

		fclose( $handle );

		return [
			'rows'         => $rows,
			'months_found' => $months_found,
			'pet_count'    => $pet_count,
			'person_count' => $person_count,
		];
	}

	/**
	 * Generate a preview of parsed data.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filepath Path to the CSV file.
	 * @param int    $year     The year for dating.
	 * @param int    $limit    Maximum preview rows.
	 * @return array|\WP_Error Preview data or error.
	 */
	public static function preview( string $filepath, int $year, int $limit = 20 ): array|\WP_Error {
		$parsed = self::parse( $filepath, $year );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return [
			'total_rows'   => count( $parsed['rows'] ),
			'preview_rows' => array_slice( $parsed['rows'], 0, $limit ),
			'months_found' => $parsed['months_found'],
			'pet_count'    => $parsed['pet_count'],
			'person_count' => $parsed['person_count'],
			'year'         => $year,
		];
	}

	/**
	 * Import parsed memorial rows, creating donors and memorial records.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filepath        Path to CSV file.
	 * @param int    $year            Year for dating.
	 * @param bool   $skip_duplicates Skip duplicate honoree/donor combinations.
	 * @param float  $default_amount  Default donation amount (0 if not specified).
	 * @return array{
	 *     created: int,
	 *     skipped: int,
	 *     errors: int,
	 *     donors_created: int,
	 *     details: array
	 * }|\WP_Error Import results or error.
	 */
	public static function import(
		string $filepath,
		int $year,
		bool $skip_duplicates = true,
		float $default_amount = 0
	): array|\WP_Error {
		$parsed = self::parse( $filepath, $year );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$results = [
			'created'        => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'donors_created' => 0,
			'details'        => [],
		];

		foreach ( $parsed['rows'] as $row ) {
			$result = self::import_single_row( $row, $default_amount, $skip_duplicates );

			if ( is_wp_error( $result ) ) {
				$results['errors']++;
				$results['details'][] = [
					'line'    => $row['line_number'],
					'status'  => 'error',
					'message' => $result->get_error_message(),
				];
				continue;
			}

			if ( $result['skipped'] ?? false ) {
				$results['skipped']++;
				$results['details'][] = [
					'line'    => $row['line_number'],
					'status'  => 'skipped',
					'message' => $result['message'] ?? '',
				];
				continue;
			}

			$results['created']++;
			if ( $result['donor_created'] ?? false ) {
				$results['donors_created']++;
			}
			$results['details'][] = [
				'line'        => $row['line_number'],
				'status'      => 'created',
				'memorial_id' => $result['memorial_id'],
				'honoree'     => $row['honoree_name'],
				'donor'       => $row['donor_name'],
			];
		}

		return $results;
	}

	/**
	 * Import a single parsed memorial row.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row             Parsed row data.
	 * @param float $default_amount  Default donation amount.
	 * @param bool  $skip_duplicates Whether to skip duplicates.
	 * @return array|\WP_Error Import result or error.
	 */
	private static function import_single_row(
		array $row,
		float $default_amount,
		bool $skip_duplicates
	): array|\WP_Error {
		// Find or create donor.
		$donor = Donor_Lookup::find_or_create( [
			'display_name' => $row['donor_name'],
			'is_anonymous' => $row['is_anonymous'],
			'import_source' => self::IMPORT_SOURCE,
		] );

		if ( is_wp_error( $donor ) ) {
			return $donor;
		}

		$donor_id      = $donor['id'];
		$donor_created = $donor['created'];

		// Check for duplicate memorial.
		if ( $skip_duplicates ) {
			$existing = self::find_existing_memorial( $row['honoree_name'], $donor_id );
			if ( $existing ) {
				return [
					'skipped' => true,
					'message' => sprintf(
						/* translators: %s: honoree name */
						__( 'Memorial for "%s" by this donor already exists.', 'starter-shelter' ),
						$row['honoree_name']
					),
				];
			}
		}

		// Create memorial.
		$memorial_id = self::create_memorial( $row, $donor_id, $default_amount );

		if ( is_wp_error( $memorial_id ) ) {
			return $memorial_id;
		}

		return [
			'memorial_id'   => $memorial_id,
			'donor_created' => $donor_created,
		];
	}

	/**
	 * Create a memorial record from parsed row data.
	 *
	 * Always sets _sd_donor_display_name on the memorial so the front-end
	 * (memorial wall) can display the donor name without hydrating the
	 * full donor entity.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row            Parsed row data.
	 * @param int   $donor_id       The donor post ID.
	 * @param float $default_amount Default donation amount.
	 * @return int|\WP_Error Memorial post ID or error.
	 */
	private static function create_memorial( array $row, int $donor_id, float $default_amount ): int|\WP_Error {
		$memorial_date = $row['date'] . ' 12:00:00';

		// Sanitize honoree name preserving quotes and special chars.
		$honoree_name = Donor_Lookup::sanitize_display_name( $row['honoree_name'] );

		// Get donor display name for denormalized storage on the memorial.
		$donor_display_name = self::get_donor_display_for_memorial( $donor_id, $row );

		$memorial_id = wp_insert_post( [
			'post_type'   => 'sd_memorial',
			'post_status' => 'publish',
			'post_title'  => $honoree_name,
			'post_date'   => $memorial_date,
			'meta_input'  => [
				'_sd_donor_id'           => $donor_id,
				'_sd_donor_display_name' => $donor_display_name,
				'_sd_honoree_name'       => $honoree_name,
				'_sd_memorial_type'      => $row['memorial_type'],
				'_sd_amount'             => $default_amount,
				'_sd_donation_date'      => $memorial_date,
				'_sd_date'               => $memorial_date,
				'_sd_is_anonymous'       => $row['is_anonymous'] ? 1 : 0,
				'_sd_import_source'      => self::IMPORT_SOURCE,
				'_sd_import_line'        => $row['line_number'],
			],
		], true );

		if ( is_wp_error( $memorial_id ) ) {
			return $memorial_id;
		}

		// Assign to memorial year taxonomy.
		$year = date( 'Y', strtotime( $memorial_date ) );
		wp_set_object_terms( $memorial_id, [ $year ], 'sd_memorial_year' );

		// Update donor stats if amount > 0.
		if ( $default_amount > 0 ) {
			Helpers\update_donor_lifetime_giving( $donor_id, $default_amount );
		}

		return $memorial_id;
	}

	/**
	 * Determine the donor display name to store on a memorial.
	 *
	 * For anonymous donors: "Anonymous Donor"
	 * For named donors: the display name (from meta or row data)
	 *
	 * This denormalized value lets the front-end display the donor name
	 * without loading the full donor entity on every memorial card.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $donor_id The donor post ID.
	 * @param array $row      The parsed row data.
	 * @return string The display name for the memorial.
	 */
	private static function get_donor_display_for_memorial( int $donor_id, array $row ): string {
		if ( $row['is_anonymous'] ) {
			return 'Anonymous Donor';
		}

		// Prefer the stored display_name meta (set by Donor_Lookup).
		$display_name = get_post_meta( $donor_id, '_sd_display_name', true );
		if ( ! empty( $display_name ) ) {
			return $display_name;
		}

		// Fall back to the raw name from the CSV row.
		return Donor_Lookup::sanitize_display_name( $row['donor_name'] );
	}

	/**
	 * Find an existing memorial by honoree name and donor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $honoree_name The honoree name.
	 * @param int    $donor_id     The donor post ID.
	 * @return int|null Memorial post ID or null.
	 */
	private static function find_existing_memorial( string $honoree_name, int $donor_id ): ?int {
		$existing = get_posts( [
			'post_type'      => 'sd_memorial',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => '_sd_honoree_name',
					'value' => $honoree_name,
				],
				[
					'key'   => '_sd_donor_id',
					'value' => $donor_id,
					'type'  => 'NUMERIC',
				],
			],
			'fields' => 'ids',
		] );

		return ! empty( $existing ) ? $existing[0] : null;
	}

	/**
	 * Clean up honoree name from CSV, preserving quoted pet names.
	 *
	 * CSV escapes quotes by doubling them, so a field like:
	 *   """Hector"" Mewes"
	 * is read by fgetcsv() as:
	 *   ""Hector" Mewes"
	 * but we want to display:
	 *   "Hector" Mewes
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Raw honoree name from CSV.
	 * @return string Cleaned honoree name.
	 */
	private static function clean_honoree_name( string $name ): string {
		// Handle CSV's triple-quote escaping patterns.
		if ( preg_match( '/^""(.+?)""(.*)$/', $name, $matches ) ) {
			$name = '"' . $matches[1] . '"' . $matches[2];
		} elseif ( str_starts_with( $name, '""' ) ) {
			$name = preg_replace( '/^""/', '"', $name );
			$name = preg_replace( '/""$/', '"', $name );
		}

		// Clean remaining double-double quotes.
		$name = str_replace( '""', '"', $name );

		return trim( $name );
	}

	/**
	 * Clean up donor name from CSV, preserving special characters.
	 *
	 * Unlike sanitize_text_field() which encodes & and strips chars,
	 * this preserves characters that commonly appear in donor names:
	 * - "John & Mary Smith"
	 * - "O'Brien Family"
	 * - "José García"
	 * - "The Thompson's"
	 *
	 * Delegates to Donor_Lookup::sanitize_display_name() for consistent
	 * handling across all import paths.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Raw donor name from CSV.
	 * @return string Cleaned donor name.
	 */
	private static function clean_donor_name( string $name ): string {
		return Donor_Lookup::sanitize_display_name( $name );
	}

	/**
	 * Check if a donor name indicates an anonymous donation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name The donor name.
	 * @return bool Whether the donor is anonymous.
	 */
	private static function is_anonymous_name( string $name ): bool {
		return in_array(
			strtolower( trim( $name ) ),
			[ 'anonymous', 'anon', 'anonymous donor' ],
			true
		);
	}
}
