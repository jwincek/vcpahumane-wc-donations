<?php
/**
 * Import AJAX Handler - Thin layer for all import/export AJAX endpoints.
 *
 * Consolidates the following AJAX handlers from the old monolith:
 * - ajax_preview_import()
 * - ajax_process_import_donors()
 * - ajax_process_import_donations()
 * - ajax_process_import_memorials()
 * - ajax_process_import_memberships()
 * - ajax_preview_import_memorials_legacy()
 * - ajax_process_import_memorials_legacy()
 * - ajax_download_template()
 * - ajax_get_export_counts()
 *
 * The four separate process handlers (donors, donations, memorials, memberships)
 * collapse into a single generic import() method that extracts the entity type
 * from the AJAX action name.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Core\Config;

/**
 * Registers and handles all import/export AJAX endpoints.
 *
 * @since 2.0.0
 */
class Import_Ajax_Handler {

	/**
	 * Register all AJAX actions.
	 *
	 * @since 2.0.0
	 */
	public static function init(): void {
		// Generic preview (for standard CSV imports).
		add_action( 'wp_ajax_sd_preview_import', [ self::class, 'preview' ] );

		// Generic import handlers (entity type extracted from action name).
		add_action( 'wp_ajax_sd_process_import_donors', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_donations', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_memorials', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_memberships', [ self::class, 'import' ] );

		// Legacy memorial format (separate handlers due to different parameters).
		add_action( 'wp_ajax_sd_preview_import_memorials_legacy', [ self::class, 'preview_legacy_memorials' ] );
		add_action( 'wp_ajax_sd_process_import_memorials_legacy', [ self::class, 'import_legacy_memorials' ] );

		// Templates and counts.
		add_action( 'wp_ajax_sd_download_template', [ self::class, 'download_template' ] );
		add_action( 'wp_ajax_sd_get_export_counts', [ self::class, 'get_export_counts' ] );
	}

	/**
	 * Preview a standard CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function preview(): void {
		check_ajax_referer( 'sd_preview_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$import_type = sanitize_key( $_POST['import_type'] ?? '' );
		$file        = $_FILES['file'] ?? null;

		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		// Validate the entity type exists in config.
		$config = Config::get_path( 'import-export', "entity_types.{$import_type}" );
		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		$preview = CSV_Importer::preview( $import_type, $file['tmp_name'] );

		if ( isset( $preview['error'] ) ) {
			wp_send_json_error( $preview['error'] );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * Process a standard CSV import.
	 *
	 * Generic handler for all four entity types. The entity type is
	 * extracted from the AJAX action name:
	 *   sd_process_import_donors      → donors
	 *   sd_process_import_donations   → donations
	 *   sd_process_import_memorials   → memorials
	 *   sd_process_import_memberships → memberships
	 *
	 * @since 2.0.0
	 */
	public static function import(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		// Extract entity type from the AJAX action.
		$action      = sanitize_key( $_POST['action'] ?? '' );
		$entity_type = str_replace( 'sd_process_import_', '', $action );

		// Validate entity type exists.
		$config = Config::get_path( 'import-export', "entity_types.{$entity_type}" );
		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		// Collect options from POST data.
		$options = [
			'create_donors'   => ! empty( $_POST['create_donors'] ),
			'update_existing' => ! empty( $_POST['update_existing'] ),
			'skip_errors'     => ! empty( $_POST['skip_errors'] ),
			'skip_duplicates' => ! empty( $_POST['skip_duplicates'] )
		];

		$results = CSV_Importer::process( $entity_type, $file['tmp_name'], $options );

		wp_send_json_success( $results );
	}

	/**
	 * Preview a legacy memorial CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function preview_legacy_memorials(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		$year = absint( $_POST['year'] ?? date( 'Y' ) );

		$preview = Legacy_Memorial_Parser::preview( $file['tmp_name'], $year );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( $preview->get_error_message() );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * Process a legacy memorial CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function import_legacy_memorials(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		$year            = absint( $_POST['year'] ?? date( 'Y' ) );
		$skip_duplicates = ! empty( $_POST['skip_duplicates'] );
		$default_amount  = (float) ( $_POST['default_amount'] ?? 0 );

		$results = Legacy_Memorial_Parser::import(
			$file['tmp_name'],
			$year,
			$skip_duplicates,
			$default_amount
		);

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( $results->get_error_message() );
		}

		wp_send_json_success( $results );
	}

	/**
	 * Download a CSV import template.
	 *
	 * Reads template definitions from import-export.json config instead
	 * of hardcoding them. The old code had the template block duplicated
	 * (lines 1595-1629 repeated) — that bug is fixed here.
	 *
	 * @since 2.0.0
	 */
	public static function download_template(): void {
		check_ajax_referer( 'sd_download_template', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'starter-shelter' ) );
		}

		$type = sanitize_key( $_GET['type'] ?? '' );

		// Read from config.
		$import_config = Config::get_path( 'import-export', "entity_types.{$type}.import" );
		if ( ! $import_config ) {
			wp_die( esc_html__( 'Invalid template type.', 'starter-shelter' ) );
		}

		$template = $import_config['template'] ?? null;
		if ( ! $template ) {
			wp_die( esc_html__( 'No template defined for this type.', 'starter-shelter' ) );
		}

		// Build header row from required + optional fields.
		$headers = array_merge(
			$import_config['required'] ?? [],
			$import_config['optional'] ?? []
		);

		$filename = $template['filename'] ?? "{$type}-import-template.csv";
		$examples = $template['examples'] ?? [];

		// Stream CSV.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Headers.
		fputcsv( $output, $headers );

		// Example rows.
		foreach ( $examples as $example ) {
			fputcsv( $output, $example );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Get export record counts for the dashboard cards.
	 *
	 * @since 2.0.0
	 */
	public static function get_export_counts(): void {
		check_ajax_referer( 'sd_export_counts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( self::get_record_counts() );
	}

	/**
	 * Get counts of all entity types for the export dashboard.
	 *
	 * @since 2.0.0
	 *
	 * @return array Entity counts.
	 */
	public static function get_record_counts(): array {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );

		return [
			'donations' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donation' AND post_status = 'publish'"
			),
			'memberships' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_membership' AND post_status = 'publish'"
			),
			'memberships_active' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
				 WHERE p.post_type = 'sd_membership'
				   AND p.post_status = 'publish'
				   AND pm.meta_value >= %s",
				$today
			) ),
			'memberships_expired' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
				 WHERE p.post_type = 'sd_membership'
				   AND p.post_status = 'publish'
				   AND pm.meta_value < %s",
				$today
			) ),
			'donors' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donor' AND post_status = 'publish'"
			),
			'memorials' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_memorial' AND post_status = 'publish'"
			),
		];
	}
}
