<?php
/**
 * CSV Exporter - Config-driven export for all entity types.
 *
 * Replaces the four separate handle_export_donations(), handle_export_memberships(),
 * handle_export_donors(), and handle_export_memorials() methods (~270 lines) with
 * a single export() method that reads column definitions from import-export.json.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Generic config-driven CSV exporter.
 *
 * @since 2.0.0
 */
class CSV_Exporter {

	/**
	 * Export an entity type to CSV and stream it to the browser.
	 *
	 * @since 2.0.0
	 *
	 * @param string $entity_type Key from import-export.json entity_types (e.g. 'donations').
	 * @param array  $options {
	 *     Export options.
	 *
	 *     @type string $date_range  Date range filter: all, this_year, last_year, this_month, custom.
	 *     @type string $date_from   Custom date range start (Y-m-d).
	 *     @type string $date_to     Custom date range end (Y-m-d).
	 *     @type string $status      Status filter for memberships: all, active, expired.
	 *     @type bool   $include_stats Include optional column groups for donors.
	 * }
	 */
	public static function export( string $entity_type, array $options = [] ): void {
		$type_config = Config::get_path( 'import-export', "entity_types.{$entity_type}" );

		if ( ! $type_config ) {
			wp_die(
				esc_html__( 'Invalid export type.', 'starter-shelter' ),
				esc_html__( 'Export Error', 'starter-shelter' ),
				[ 'response' => 400 ]
			);
		}

		$export_config = $type_config['export'] ?? [];
		$post_type     = $type_config['post_type'] ?? '';

		// Build query.
		$args = self::build_query_args( $post_type, $export_config, $options );
		$query = new \WP_Query( $args );

		// Collect columns (base + optional groups).
		$columns = $export_config['columns'] ?? [];
		$columns = self::maybe_add_optional_columns( $columns, $export_config, $options );

		// Stream CSV.
		$filename = ( $export_config['filename_prefix'] ?? $entity_type ) . '-' . wp_date( 'Y-m-d' ) . '.csv';
		self::send_headers( $filename );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv( $output, array_column( $columns, 'header' ) );

		// Pre-cache post meta for all results to avoid N+1 queries.
		if ( ! empty( $query->posts ) ) {
			update_postmeta_cache( wp_list_pluck( $query->posts, 'ID' ) );
		}

		// Data rows.
		foreach ( $query->posts as $post ) {
			$entity = Entity_Hydrator::get( $post_type, $post->ID );

			if ( ! $entity ) {
				continue;
			}

			$row = self::build_row( $post, $entity, $columns, $post_type );
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Build a single CSV row from column definitions and entity data.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Post $post      The post object.
	 * @param array    $entity    Hydrated entity data.
	 * @param array    $columns   Column definitions from config.
	 * @param string   $post_type The post type.
	 * @return array Row values.
	 */
	private static function build_row( \WP_Post $post, array $entity, array $columns, string $post_type ): array {
		$row = [];

		foreach ( $columns as $col ) {
			$row[] = self::resolve_column_value( $post, $entity, $col, $post_type );
		}

		return $row;
	}

	/**
	 * Resolve a single column value from its config definition.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Post $post      The post object.
	 * @param array    $entity    Hydrated entity data.
	 * @param array    $col       Column definition.
	 * @param string   $post_type The post type.
	 * @return string The resolved value.
	 */
	private static function resolve_column_value( \WP_Post $post, array $entity, array $col, string $post_type ): string {
		// Direct post field.
		if ( isset( $col['source'] ) ) {
			return match ( $col['source'] ) {
				'post_id' => (string) $post->ID,
				'post_date' => $post->post_date,
				'taxonomy' => self::get_taxonomy_names( $post->ID, $col['taxonomy'] ?? '' ),
				default => '',
			};
		}

		// Related entity field (e.g. donor name/email).
		if ( isset( $col['relation'] ) ) {
			return self::resolve_relation( $entity, $col, $post_type );
		}

		// Computed field (from Entity_Hydrator).
		if ( isset( $col['computed'] ) ) {
			$computed_key = $col['computed'];

			// Special: active_status for memberships.
			if ( 'active_status' === $computed_key ) {
				$end_date = $entity['end_date'] ?? '';
				return ( ! empty( $end_date ) && strtotime( $end_date ) >= time() )
					? 'Active'
					: 'Expired';
			}

			return (string) ( $entity[ $computed_key ] ?? '' );
		}

		// Direct meta lookup (for fields not in entities.json hydration).
		if ( isset( $col['meta'] ) ) {
			$value = get_post_meta( $post->ID, $col['meta'], true );
			return self::format_value( $value, $col['format'] ?? null );
		}

		// Entity field (from hydrated data).
		if ( isset( $col['field'] ) ) {
			$value = $entity[ $col['field'] ] ?? '';

			// Subfield extraction for object fields (e.g. address.address_1).
			if ( isset( $col['subfield'] ) ) {
				$value = self::extract_subfield( $post->ID, $value, $col );
				return self::format_value( $value, $col['format'] ?? null );
			}

			// Field with fallback.
			if ( empty( $value ) && isset( $col['fallback'] ) ) {
				if ( 'post_date' === $col['fallback'] ) {
					$value = $post->post_date;
				} else {
					$value = $entity[ $col['fallback'] ] ?? '';
				}
			}

			return self::format_value( $value, $col['format'] ?? null );
		}

		return '';
	}

	/**
	 * Resolve a value from a related entity (e.g. donor).
	 *
	 * @since 2.0.0
	 *
	 * @param array  $entity    The primary entity data.
	 * @param array  $col       Column definition.
	 * @param string $post_type The primary post type.
	 * @return string The related value.
	 */
	private static function resolve_relation( array $entity, array $col, string $post_type ): string {
		$relation = $col['relation'];

		// Currently only 'donor' relations are used.
		if ( 'donor' !== $relation ) {
			return '';
		}

		$donor_id = (int) ( $entity['donor_id'] ?? 0 );
		if ( ! $donor_id ) {
			return '';
		}

		// For donor exports, the entity IS the donor.
		if ( 'sd_donor' === $post_type ) {
			if ( isset( $col['computed'] ) ) {
				return (string) ( $entity[ $col['computed'] ] ?? '' );
			}
			if ( isset( $col['subfield'] ) ) {
				$value = $entity[ $col['field'] ?? '' ] ?? '';
				return self::extract_subfield( 0, $value, $col );
			}
			return (string) ( $entity[ $col['field'] ?? '' ] ?? '' );
		}

		// Hydrate the related donor.
		$donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
		if ( ! $donor ) {
			return '';
		}

		if ( isset( $col['computed'] ) ) {
			$computed = $col['computed'];
			if ( 'full_name' === $computed ) {
				return trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) );
			}
			return (string) ( $donor[ $computed ] ?? '' );
		}

		// Subfield extraction on related entity (e.g. donor address).
		if ( isset( $col['subfield'] ) ) {
			$value = $donor[ $col['field'] ?? '' ] ?? '';
			// For related entities, use donor_id for meta fallback lookups.
			return self::extract_subfield( $donor_id, $value, $col );
		}

		return (string) ( $donor[ $col['field'] ?? '' ] ?? '' );
	}

	/**
	 * Get taxonomy term names for a post, comma-separated.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return string Comma-separated term names.
	 */
	private static function get_taxonomy_names( int $post_id, string $taxonomy ): string {
		if ( empty( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Format a value for CSV output.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed       $value  The raw value.
	 * @param string|null $format The format type from config.
	 * @return string Formatted string value.
	 */
	private static function format_value( $value, ?string $format ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( null === $format ) {
			return is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
		}

		return match ( $format ) {
			'yes_no' => $value ? 'Yes' : 'No',
			'yes_no_truthy' => ! empty( $value ) ? 'Yes' : 'No',
			'currency' => Helpers\format_currency( (float) $value ),
			'date' => Helpers\format_date( (string) $value ),
			default => (string) $value,
		};
	}

	/**
	 * Extract a subfield from an object value with flat meta fallback.
	 *
	 * Address data exists in two formats depending on how it was stored:
	 * - Abilities pipeline: serialized object in _sd_address
	 * - Meta box saves: individual keys (_sd_address_line_1, _sd_city, etc.)
	 *
	 * This method tries the hydrated object first, then falls back to
	 * individual meta keys if the object subfield is empty.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $post_id The post ID for meta fallback lookups (0 to skip).
	 * @param mixed  $value   The hydrated field value (may be array/object).
	 * @param array  $col     Column definition with 'subfield' and optional 'meta_fallback'.
	 * @return string The extracted value.
	 */
	private static function extract_subfield( int $post_id, $value, array $col ): string {
		$subfield = $col['subfield'] ?? '';
		$result   = '';

		// Try extracting from the hydrated object.
		if ( is_array( $value ) && ! empty( $value[ $subfield ] ) ) {
			$result = (string) $value[ $subfield ];
		}

		// Fallback: try individual meta key.
		if ( empty( $result ) && $post_id > 0 ) {
			// Explicit meta key fallback.
			if ( ! empty( $col['meta_fallback'] ) ) {
				$result = (string) get_post_meta( $post_id, $col['meta_fallback'], true );
			}

			// Prefix-based fallback (for related entities).
			if ( empty( $result ) && ! empty( $col['meta_fallback_prefix'] ) ) {
				$meta_key = $col['meta_fallback_prefix'] . $subfield;
				$result   = (string) get_post_meta( $post_id, $meta_key, true );
			}
		}

		return $result;
	}

	/**
	 * Build WP_Query args from config and filter options.
	 *
	 * @since 2.0.0
	 *
	 * @param string $post_type     The post type.
	 * @param array  $export_config Export config section.
	 * @param array  $options       User-selected filter options.
	 * @return array WP_Query args.
	 */
	private static function build_query_args( string $post_type, array $export_config, array $options ): array {
		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => $export_config['orderby'] ?? 'date',
			'order'          => $export_config['order'] ?? 'DESC',
		];

		// Apply date range filter.
		$date_range = $options['date_range'] ?? 'all';
		if ( 'all' !== $date_range ) {
			$date_query = self::build_date_query( $date_range, $options );
			if ( $date_query ) {
				$args['date_query'] = $date_query;
			}
		}

		// Apply status meta filter (e.g. active/expired memberships).
		$status = $options['status'] ?? 'all';
		if ( 'all' !== $status ) {
			$filters = $export_config['filters'] ?? [];
			if ( isset( $filters['status']['meta_filter'][ $status ] ) ) {
				$meta_filter = $filters['status']['meta_filter'][ $status ];
				$meta_value  = $meta_filter['value'] ?? '';

				// Resolve dynamic values.
				if ( 'today' === $meta_value ) {
					$meta_value = wp_date( 'Y-m-d' );
				}

				$args['meta_query'] = [
					[
						'key'     => $meta_filter['key'],
						'value'   => $meta_value,
						'compare' => $meta_filter['compare'] ?? '=',
					],
				];
			}
		}

		return $args;
	}

	/**
	 * Build a date_query array for WP_Query.
	 *
	 * @since 2.0.0
	 *
	 * @param string $range   Date range key.
	 * @param array  $options Options with potential custom dates.
	 * @return array|null WP_Query date_query or null.
	 */
	private static function build_date_query( string $range, array $options ): ?array {
		return match ( $range ) {
			'this_year'  => [ [ 'year' => (int) wp_date( 'Y' ) ] ],
			'last_year'  => [ [ 'year' => (int) wp_date( 'Y' ) - 1 ] ],
			'this_month' => [ [ 'year' => (int) wp_date( 'Y' ), 'month' => (int) wp_date( 'n' ) ] ],
			'custom'     => self::build_custom_date_query( $options ),
			default      => null,
		};
	}

	/**
	 * Build date query for custom date range.
	 *
	 * @since 2.0.0
	 *
	 * @param array $options Options with date_from and date_to.
	 * @return array|null Date query or null if dates are incomplete.
	 */
	private static function build_custom_date_query( array $options ): ?array {
		$from = $options['date_from'] ?? '';
		$to   = $options['date_to'] ?? '';

		if ( $from && $to ) {
			return [ [ 'after' => $from, 'before' => $to, 'inclusive' => true ] ];
		}

		return null;
	}

	/**
	 * Append optional column groups if their option is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @param array $columns       Base columns.
	 * @param array $export_config Export config.
	 * @param array $options       User options.
	 * @return array Columns with optional groups appended.
	 */
	private static function maybe_add_optional_columns( array $columns, array $export_config, array $options ): array {
		$groups = $export_config['optional_column_groups'] ?? [];

		foreach ( $groups as $group_key => $group ) {
			// Check if this group should be included.
			$include = $options[ $group_key ] ?? ( $group['default'] ?? false );

			if ( $include ) {
				$columns = array_merge( $columns, $group['columns'] ?? [] );
			}
		}

		return $columns;
	}

	/**
	 * Send HTTP headers for CSV download.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filename The download filename.
	 */
	private static function send_headers( string $filename ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}
}
