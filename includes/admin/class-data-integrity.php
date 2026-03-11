<?php
/**
 * Data Integrity — Admin tool for backfilling missing meta and purging records.
 *
 * Adds a "Data Tools" submenu under Shelter Donations with two tabs:
 *
 * 1. BACKFILL — Scans existing records against entities.json, reports
 *    missing/empty meta fields, and fills them in batches. Also handles:
 *    - Memorial donor_display_name denormalization
 *    - Memorial year taxonomy terms
 *    - Donation allocation defaults
 *    - Donor lifetime_giving recalculation
 *
 * 2. PURGE — Bulk-delete all records of a chosen CPT. Double confirmation
 *    (type the post type slug to confirm). Useful for re-syncing from
 *    WooCommerce orders or re-importing from CSV.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

class Data_Integrity {

	/**
	 * Page slug.
	 */
	private const SLUG = 'starter-shelter-data-tools';

	/**
	 * Nonce action.
	 */
	private const NONCE = 'sd_data_integrity';

	/**
	 * Batch size for AJAX operations.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Entity types we manage.
	 */
	private const ENTITY_TYPES = [
		'sd_donation'   => 'Donations',
		'sd_membership' => 'Memberships',
		'sd_memorial'   => 'Memorials',
		'sd_donor'      => 'Donors',
	];

	/**
	 * Initialize.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ], 30 );
		add_action( 'wp_ajax_sd_integrity_scan', [ self::class, 'ajax_scan' ] );
		add_action( 'wp_ajax_sd_integrity_backfill', [ self::class, 'ajax_backfill' ] );
		add_action( 'wp_ajax_sd_integrity_purge', [ self::class, 'ajax_purge' ] );
		add_action( 'wp_ajax_sd_integrity_recalc_donors', [ self::class, 'ajax_recalc_donors' ] );
	}

	/**
	 * Register the admin submenu page.
	 */
	public static function add_menu_page(): void {
		$hook = add_submenu_page(
			Menu::MENU_SLUG,
			__( 'Data Tools', 'starter-shelter' ),
			__( 'Data Tools', 'starter-shelter' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'render_page' ]
		);

		add_action( "admin_print_scripts-{$hook}", [ self::class, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue JS and localize data.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_script(
			'sd-data-integrity',
			plugins_url( 'assets/js/admin-data-integrity.js', STARTER_SHELTER_FILE ),
			[ 'jquery' ],
			STARTER_SHELTER_VERSION,
			true
		);

		wp_localize_script( 'sd-data-integrity', 'sdDataIntegrity', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'batchSize'  => self::BATCH_SIZE,
			'entityTypes' => self::ENTITY_TYPES,
			'i18n'       => [
				'scanning'        => __( 'Scanning…', 'starter-shelter' ),
				'backfilling'     => __( 'Backfilling…', 'starter-shelter' ),
				'purging'         => __( 'Deleting…', 'starter-shelter' ),
				'recalculating'   => __( 'Recalculating…', 'starter-shelter' ),
				'complete'        => __( 'Complete!', 'starter-shelter' ),
				'confirmPurge'    => __( 'Type the post type slug to confirm:', 'starter-shelter' ),
				'purgeWarning'    => __( 'This will permanently delete ALL %s records. This cannot be undone.', 'starter-shelter' ),
				'noIssues'        => __( 'All records look good — no missing fields found.', 'starter-shelter' ),
			],
		] );
	}

	// ─────────────────────────────────────────────────────────────
	// Page Render
	// ─────────────────────────────────────────────────────────────

	/**
	 * Render the admin page.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Data Tools', 'starter-shelter' ); ?></h1>

			<nav class="nav-tab-wrapper" id="sd-data-tools-tabs">
				<a href="#backfill" class="nav-tab nav-tab-active"><?php esc_html_e( 'Backfill &amp; Repair', 'starter-shelter' ); ?></a>
				<a href="#purge" class="nav-tab"><?php esc_html_e( 'Purge Records', 'starter-shelter' ); ?></a>
				<a href="#recalc" class="nav-tab"><?php esc_html_e( 'Recalculate', 'starter-shelter' ); ?></a>
			</nav>

			<?php self::render_backfill_tab(); ?>
			<?php self::render_purge_tab(); ?>
			<?php self::render_recalc_tab(); ?>

			<div id="sd-progress-area" style="display:none; margin-top: 20px;">
				<div class="sd-progress-bar-wrap" style="background:#ddd; border-radius:4px; height:24px; max-width:600px;">
					<div id="sd-progress-bar" style="background:#2271b1; height:100%; border-radius:4px; width:0%; transition:width 0.3s;"></div>
				</div>
				<p id="sd-progress-text" style="margin-top:8px;"></p>
			</div>

			<div id="sd-results-area" style="margin-top: 20px;"></div>
		</div>
		<style>
			.sd-tool-section { display: none; margin-top: 20px; }
			.sd-tool-section.active { display: block; }
			.sd-entity-card { background: #fff; border: 1px solid #c3c4c7; padding: 16px 20px; margin-bottom: 12px; }
			.sd-entity-card h3 { margin: 0 0 8px; }
			.sd-entity-card .sd-field-list { margin: 8px 0; color: #646970; }
			.sd-purge-danger { border-left: 4px solid #d63638; }
			.sd-scan-ok { border-left: 4px solid #00a32a; }
			.sd-scan-issues { border-left: 4px solid #dba617; }
			.sd-count-badge { display: inline-block; background: #dba617; color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
			.sd-count-badge.ok { background: #00a32a; }
		</style>
		<?php
	}

	/**
	 * Render the Backfill tab.
	 */
	private static function render_backfill_tab(): void {
		?>
		<div id="tab-backfill" class="sd-tool-section active">
			<h2><?php esc_html_e( 'Backfill Missing Fields', 'starter-shelter' ); ?></h2>
			<p class="description">
				<?php esc_html_e(
					'Scans all records and reports missing or empty meta fields based on the entity schema. '
					. 'You can then backfill defaults, denormalized fields, and taxonomy terms in batches.',
					'starter-shelter'
				); ?>
			</p>

			<p>
				<button type="button" id="sd-scan-btn" class="button button-primary">
					<?php esc_html_e( 'Scan All Records', 'starter-shelter' ); ?>
				</button>
			</p>

			<div id="sd-scan-results"></div>

			<p id="sd-backfill-actions" style="display:none;">
				<button type="button" id="sd-backfill-btn" class="button button-primary">
					<?php esc_html_e( 'Backfill All Issues', 'starter-shelter' ); ?>
				</button>
				<span class="description" style="margin-left: 10px;">
					<?php esc_html_e( 'Fills in missing defaults, display names, and taxonomy terms. Does not overwrite existing values.', 'starter-shelter' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Purge tab.
	 */
	private static function render_purge_tab(): void {
		?>
		<div id="tab-purge" class="sd-tool-section">
			<h2><?php esc_html_e( 'Purge Records', 'starter-shelter' ); ?></h2>

			<div class="notice notice-warning inline" style="margin: 10px 0 20px;">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'starter-shelter' ); ?></strong>
					<?php esc_html_e(
						'This permanently deletes posts and their meta. '
						. 'Use this to start fresh before re-running Legacy Order Sync or CSV imports. '
						. 'WooCommerce orders and donor records are not affected unless you explicitly choose them.',
						'starter-shelter'
					); ?>
				</p>
			</div>

			<?php foreach ( self::ENTITY_TYPES as $post_type => $label ) : ?>
				<?php
				$count = wp_count_posts( $post_type );
				$total = ( $count->publish ?? 0 ) + ( $count->draft ?? 0 ) + ( $count->private ?? 0 ) + ( $count->trash ?? 0 );
				?>
				<div class="sd-entity-card sd-purge-danger">
					<h3>
						<?php echo esc_html( $label ); ?>
						<span class="sd-count-badge"><?php echo esc_html( number_format( $total ) ); ?></span>
					</h3>
					<p class="description">
						<?php printf(
							/* translators: %s: post type slug */
							esc_html__( 'Post type: %s', 'starter-shelter' ),
							'<code>' . esc_html( $post_type ) . '</code>'
						); ?>
					</p>
					<p style="margin-top: 10px;">
						<button type="button"
							class="button sd-purge-btn"
							data-post-type="<?php echo esc_attr( $post_type ); ?>"
							data-label="<?php echo esc_attr( $label ); ?>"
							data-count="<?php echo esc_attr( (string) $total ); ?>"
							<?php echo $total === 0 ? 'disabled' : ''; ?>
						>
							<?php printf(
								/* translators: %s: record type label */
								esc_html__( 'Delete All %s', 'starter-shelter' ),
								esc_html( $label )
							); ?>
						</button>
					</p>
				</div>
			<?php endforeach; ?>

			<?php if ( function_exists( 'wc_get_orders' ) ) : ?>
			<div class="sd-entity-card" style="border-left: 4px solid #2271b1; margin-top: 20px;">
				<h3><?php esc_html_e( 'Reset Legacy Sync Flags', 'starter-shelter' ); ?></h3>
				<p class="description">
					<?php esc_html_e(
						'Clears the "_sd_legacy_synced" flag on all WooCommerce orders so they can be re-processed by the Legacy Order Sync tool. Does NOT delete any records.',
						'starter-shelter'
					); ?>
				</p>
				<p style="margin-top: 10px;">
					<button type="button" id="sd-reset-sync-flags" class="button">
						<?php esc_html_e( 'Reset Sync Flags', 'starter-shelter' ); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Recalculate tab.
	 */
	private static function render_recalc_tab(): void {
		?>
		<div id="tab-recalc" class="sd-tool-section">
			<h2><?php esc_html_e( 'Recalculate Donor Stats', 'starter-shelter' ); ?></h2>
			<p class="description">
				<?php esc_html_e(
					'Recalculates lifetime_giving for every donor by summing their donations, memorials, and memberships. '
					. 'Use this after bulk imports or if stats look incorrect.',
					'starter-shelter'
				); ?>
			</p>
			<p>
				<button type="button" id="sd-recalc-btn" class="button button-primary">
					<?php esc_html_e( 'Recalculate All Donors', 'starter-shelter' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Scan
	// ─────────────────────────────────────────────────────────────

	/**
	 * Scan all entity types and report missing fields.
	 *
	 * Returns a summary per entity type:
	 *   { sd_donation: { total: 500, issues: { allocation: 12, donation_date: 0 } } }
	 */
	public static function ajax_scan(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$entities = Config::get_item( 'entities', 'entities', [] );
		$report   = [];

		foreach ( self::ENTITY_TYPES as $post_type => $label ) {
			$config = $entities[ $post_type ] ?? null;
			if ( ! $config ) {
				continue;
			}

			$prefix = $config['meta_prefix'] ?? '_sd_';
			$fields = $config['fields'] ?? [];

			// Get all post IDs for this type.
			$post_ids = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );

			$total      = count( $post_ids );
			$issues     = [];
			$issue_ids  = [];

			// Check each field.
			foreach ( $fields as $field_name => $field_config ) {
				$meta_key = $prefix . $field_name;
				$missing  = self::count_missing_meta( $post_ids, $meta_key, $field_config );

				$issues[ $field_name ] = $missing;
				if ( $missing > 0 ) {
					$issue_ids[ $field_name ] = self::get_ids_missing_meta( $post_ids, $meta_key, $field_config, 5 );
				}
			}

			// Special checks per entity type.
			$specials = self::scan_special_fields( $post_type, $post_ids );

			$report[ $post_type ] = [
				'label'       => $label,
				'total'       => $total,
				'issues'      => $issues,
				'specials'    => $specials,
				'sample_ids'  => $issue_ids,
				'has_issues'  => array_sum( $issues ) > 0 || ! empty( $specials ),
			];
		}

		wp_send_json_success( $report );
	}

	/**
	 * Count posts missing a meta field or having it empty.
	 */
	private static function count_missing_meta( array $post_ids, string $meta_key, array $field_config ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Posts where meta key doesn't exist or value is empty string.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.ID IN ($id_placeholders)
			 AND (pm.meta_value IS NULL OR pm.meta_value = '')",
			array_merge( [ $meta_key ], $post_ids )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get sample IDs of posts missing a meta field.
	 */
	private static function get_ids_missing_meta( array $post_ids, string $meta_key, array $field_config, int $limit = 5 ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.ID IN ($id_placeholders)
			 AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 LIMIT %d",
			array_merge( [ $meta_key ], $post_ids, [ $limit ] )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Scan entity-specific fields that need special handling.
	 */
	private static function scan_special_fields( string $post_type, array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		$specials = [];

		if ( 'sd_memorial' === $post_type ) {
			// Check for missing sd_memorial_year taxonomy terms.
			$missing_year = 0;
			foreach ( $post_ids as $id ) {
				$terms = get_the_terms( $id, 'sd_memorial_year' );
				if ( ! $terms || is_wp_error( $terms ) ) {
					$missing_year++;
				}
			}
			if ( $missing_year > 0 ) {
				$specials['memorial_year_taxonomy'] = $missing_year;
			}

			// Check for email addresses stored as donor display names.
			// This happens when the legacy sync or import creates a memorial
			// and the donor's first/last name fields are empty, causing
			// build_display_name() to fall back to the email address.
			$email_display = self::count_email_display_names( $post_ids );
			if ( $email_display > 0 ) {
				$specials['donor_display_name_email'] = $email_display;
			}

			// Check for order-linked memorials whose date doesn't match the order.
			// This happens when Product_Mapper builds the input (product still exists)
			// but doesn't pass a 'date' field, so the ability defaults to wp_date()
			// (the sync timestamp) instead of the original order date.
			$date_mismatches = self::count_memorial_date_mismatches( $post_ids );
			if ( $date_mismatches > 0 ) {
				$specials['order_date_mismatch'] = $date_mismatches;
			}
		}

		if ( 'sd_donor' === $post_type ) {
			// Check for donors whose _sd_display_name is an email address.
			// These need rebuilding from first_name + last_name.
			$donor_email_display = self::count_email_display_names_on_donors( $post_ids );
			if ( $donor_email_display > 0 ) {
				$specials['display_name_is_email'] = $donor_email_display;
			}
		}

		// Check for missing import hashes on non-donor entity types.
		if ( 'sd_donor' !== $post_type ) {
			$missing_hash = self::count_missing_meta(
				$post_ids,
				\Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY,
				[ 'type' => 'string' ]
			);
			if ( $missing_hash > 0 ) {
				$specials['import_hash'] = $missing_hash;
			}
		}

		return $specials;
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Backfill
	// ─────────────────────────────────────────────────────────────

	/**
	 * Backfill missing meta for a batch of posts.
	 *
	 * Expects POST: post_type, offset
	 * Returns: { processed, fixed, done, offset }
	 */
	public static function ajax_backfill(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_type = sanitize_key( $_POST['post_type'] ?? '' );
		$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		if ( ! isset( self::ENTITY_TYPES[ $post_type ] ) ) {
			wp_send_json_error( 'Invalid post type.' );
		}

		$entities = Config::get_item( 'entities', 'entities', [] );
		$config   = $entities[ $post_type ] ?? null;

		if ( ! $config ) {
			wp_send_json_error( 'No entity config found.' );
		}

		$prefix = $config['meta_prefix'] ?? '_sd_';
		$fields = $config['fields'] ?? [];

		// Get batch of posts.
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => self::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$processed = 0;
		$fixed     = 0;

		foreach ( $posts as $post ) {
			$processed++;
			$all_meta = get_post_meta( $post->ID );

			// Fill missing meta fields with defaults.
			foreach ( $fields as $field_name => $field_config ) {
				$meta_key = $prefix . $field_name;
				$current  = $all_meta[ $meta_key ][0] ?? '';

				if ( '' !== $current && null !== $current ) {
					continue; // Already has a value, don't overwrite.
				}

				$default = self::get_backfill_value( $post_type, $field_name, $field_config, $post, $all_meta );
				if ( null !== $default && '' !== $default ) {
					update_post_meta( $post->ID, $meta_key, $default );
					$fixed++;
				}
			}

			// Entity-specific fixes.
			$fixed += self::backfill_special_fields( $post_type, $post, $all_meta );
		}

		// Check if we're done.
		$total = wp_count_posts( $post_type );
		$total_count = ( $total->publish ?? 0 ) + ( $total->draft ?? 0 ) + ( $total->private ?? 0 );
		$new_offset  = $offset + count( $posts );

		wp_send_json_success( [
			'processed' => $processed,
			'fixed'     => $fixed,
			'offset'    => $new_offset,
			'total'     => $total_count,
			'done'      => count( $posts ) < self::BATCH_SIZE,
		] );
	}

	/**
	 * Determine the backfill value for a missing field.
	 *
	 * Returns the value to set, or null to skip.
	 */
	private static function get_backfill_value(
		string $post_type,
		string $field_name,
		array $field_config,
		\WP_Post $post,
		array $all_meta
	) {
		// Use schema default if defined.
		if ( isset( $field_config['default'] ) ) {
			return $field_config['default'];
		}

		// Entity-specific inference for fields without schema defaults.
		return match ( $post_type . '.' . $field_name ) {
			// Donations: default allocation to general-fund.
			'sd_donation.allocation' => 'general-fund',

			// Donations/Memorials: fill _sd_date from donation_date.
			'sd_donation.date',
			'sd_memorial.date' => $all_meta['_sd_donation_date'][0] ?? $post->post_date,

			// Memorials: denormalize donor display name.
			'sd_memorial.donor_display_name' => self::resolve_donor_display_name(
				(int) ( $all_meta['_sd_donor_id'][0] ?? 0 ),
				filter_var( $all_meta['_sd_is_anonymous'][0] ?? false, FILTER_VALIDATE_BOOLEAN )
			),

			// Memorials: default type to 'pet' (shelter context).
			'sd_memorial.memorial_type' => 'pet',

			// Donors: fill display_name from post title.
			'sd_donor.display_name' => $post->post_title,

			// Everything else: skip (don't invent data).
			default => null,
		};
	}

	/**
	 * Resolve a donor display name from donor_id.
	 */
	private static function resolve_donor_display_name( int $donor_id, bool $is_anonymous ): string {
		if ( $is_anonymous || ! $donor_id ) {
			return '';
		}

		$name = get_post_meta( $donor_id, '_sd_display_name', true );
		if ( $name ) {
			return $name;
		}

		$donor = get_post( $donor_id );
		return $donor ? $donor->post_title : '';
	}

	/**
	 * Count memorials whose _sd_donor_display_name looks like an email address.
	 *
	 * Uses a direct DB query with LIKE '%@%.%' for efficiency rather than
	 * iterating all posts in PHP.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Memorial post IDs to check.
	 * @return int Number of memorials with email-like display names.
	 */
	private static function count_email_display_names( array $post_ids ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders)
			   AND meta_key = '_sd_donor_display_name'
			   AND meta_value LIKE %s",
			array_merge( $post_ids, [ '%@%.%' ] )
		) );
	}

	/**
	 * Check if a string looks like an email address.
	 *
	 * @since 2.1.0
	 *
	 * @param string $value The string to check.
	 * @return bool True if the value contains @ and looks like an email.
	 */
	private static function looks_like_email( string $value ): bool {
		return ! empty( $value ) && str_contains( $value, '@' ) && is_email( $value );
	}

	/**
	 * Count donors whose _sd_display_name is an email address.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Donor post IDs to check.
	 * @return int Number of donors with email-like display names.
	 */
	private static function count_email_display_names_on_donors( array $post_ids ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders)
			   AND meta_key = '_sd_display_name'
			   AND meta_value LIKE %s",
			array_merge( $post_ids, [ '%@%.%' ] )
		) );
	}

	/**
	 * Count order-linked memorials whose post_date differs from the order date.
	 *
	 * During Legacy Order Sync, memorials processed via Product_Mapper (product
	 * still exists) don't receive a 'date' in the ability input, so the ability
	 * defaults to wp_date() — the sync timestamp instead of the order date.
	 *
	 * Only checks memorials that have a _sd_wc_order_id (i.e. order-linked),
	 * skipping legacy CSV imports which have no order reference.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Memorial post IDs to check.
	 * @return int Number of memorials with date mismatches.
	 */
	private static function count_memorial_date_mismatches( array $post_ids ): int {
		if ( empty( $post_ids ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Get memorials that have a non-zero order ID.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order_linked = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID as memorial_id, p.post_date, pm.meta_value as order_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.ID IN ($id_placeholders)
			   AND pm.meta_key = '_sd_wc_order_id'
			   AND pm.meta_value > 0",
			$post_ids
		) );

		$mismatches = 0;

		foreach ( $order_linked as $row ) {
			$order = wc_get_order( (int) $row->order_id );
			if ( ! $order || ! $order->get_date_created() ) {
				continue;
			}

			$order_date    = $order->get_date_created()->format( 'Y-m-d H:i:s' );
			$memorial_date = $row->post_date;

			if ( abs( strtotime( $memorial_date ) - strtotime( $order_date ) ) > 60 ) {
				$mismatches++;
			}
		}

		return $mismatches;
	}

	/**
	 * Backfill entity-specific fields that aren't simple meta defaults.
	 *
	 * Returns count of fixes applied.
	 */
	private static function backfill_special_fields( string $post_type, \WP_Post $post, array $all_meta ): int {
		$fixed = 0;

		if ( 'sd_memorial' === $post_type ) {
			// Assign sd_memorial_year taxonomy if missing.
			$terms = get_the_terms( $post->ID, 'sd_memorial_year' );
			if ( ! $terms || is_wp_error( $terms ) ) {
				$date = $all_meta['_sd_donation_date'][0] ?? $post->post_date;
				$year = wp_date( 'Y', strtotime( $date ) );
				if ( $year ) {
					wp_set_object_terms( $post->ID, [ $year ], 'sd_memorial_year' );
					$fixed++;
				}
			}

			// Fix email addresses stored as donor display names.
			$current_display = $all_meta['_sd_donor_display_name'][0] ?? '';
			if ( self::looks_like_email( $current_display ) ) {
				$donor_id = (int) ( $all_meta['_sd_donor_id'][0] ?? 0 );
				$is_anon  = filter_var( $all_meta['_sd_is_anonymous'][0] ?? false, FILTER_VALIDATE_BOOLEAN );

				if ( ! $is_anon && $donor_id ) {
					$corrected = self::resolve_donor_display_name( $donor_id, false );

					// If the donor's _sd_display_name is also an email
					// (hasn't been fixed yet), build from first/last directly.
					if ( empty( $corrected ) || self::looks_like_email( $corrected ) ) {
						$first = get_post_meta( $donor_id, '_sd_first_name', true );
						$last  = get_post_meta( $donor_id, '_sd_last_name', true );
						$corrected = trim( "$first $last" );
					}

					// If first/last are also empty (order had no billing name),
					// derive a name from the email address local part.
					if ( empty( $corrected ) || self::looks_like_email( $corrected ) ) {
						$donor_email = get_post_meta( $donor_id, '_sd_email', true );
						if ( ! empty( $donor_email ) ) {
							$corrected = \Starter_Shelter\Admin\Shared\Donor_Lookup::build_display_name( '', '', $donor_email );
						}
					}

					if ( ! empty( $corrected ) && ! self::looks_like_email( $corrected ) ) {
						update_post_meta( $post->ID, '_sd_donor_display_name', $corrected );
						$fixed++;
					}
				}
			}

			// Fix order-linked memorial dates that don't match the order date.
			$order_id = (int) ( $all_meta['_sd_wc_order_id'][0] ?? 0 );
			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order && $order->get_date_created() ) {
					$order_date    = $order->get_date_created()->format( 'Y-m-d H:i:s' );
					$memorial_date = $post->post_date;

					// Compare dates: if the memorial's date differs from the order by
					// more than 60 seconds, the memorial was likely stamped with the
					// sync time rather than the original order date.
					if ( abs( strtotime( $memorial_date ) - strtotime( $order_date ) ) > 60 ) {
						wp_update_post( [
							'ID'        => $post->ID,
							'post_date' => $order_date,
							'post_date_gmt' => get_gmt_from_date( $order_date ),
						] );
						update_post_meta( $post->ID, '_sd_donation_date', $order_date );
						update_post_meta( $post->ID, '_sd_date', $order_date );
						$fixed++;
					}
				}
			}
		}

		// Fix donors whose _sd_display_name is an email address.
		if ( 'sd_donor' === $post_type ) {
			$donor_display = $all_meta['_sd_display_name'][0] ?? '';
			if ( self::looks_like_email( $donor_display ) ) {
				$first = $all_meta['_sd_first_name'][0] ?? '';
				$last  = $all_meta['_sd_last_name'][0] ?? '';
				$name  = trim( "$first $last" );

				// If first/last are empty (order had no billing name),
				// use build_display_name which derives a name from the
				// email local part (e.g. john.doe@example.com → "John Doe").
				if ( empty( $name ) ) {
					$email = $all_meta['_sd_email'][0] ?? $donor_display;
					$name  = \Starter_Shelter\Admin\Shared\Donor_Lookup::build_display_name( '', '', $email );
				}

				if ( ! empty( $name ) && ! self::looks_like_email( $name ) ) {
					update_post_meta( $post->ID, '_sd_display_name', $name );
					$fixed++;

					// Also fix post_title if it's the same email.
					if ( self::looks_like_email( $post->post_title ) ) {
						wp_update_post( [ 'ID' => $post->ID, 'post_title' => $name ] );
					}
				}
			}
		}

		// Generate import hash for records that don't have one (non-donor types).
		if ( 'sd_donor' !== $post_type ) {
			$existing_hash = $all_meta[ \Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY ][0] ?? '';
			if ( '' === $existing_hash ) {
				$hash = self::compute_backfill_hash( $post_type, $post->ID );
				if ( $hash ) {
					update_post_meta( $post->ID, \Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY, $hash );
					$fixed++;
				}
			}
		}

		return $fixed;
	}

	/**
	 * Compute an import hash for an existing record (retroactive backfill).
	 *
	 * Maps post type → entity type key, looks up hash_columns from config,
	 * and delegates to CSV_Importer::compute_post_hash().
	 *
	 * @since 2.1.0
	 *
	 * @param string $post_type The post type slug.
	 * @param int    $post_id   The post ID.
	 * @return string|null The hash, or null if config not available.
	 */
	private static function compute_backfill_hash( string $post_type, int $post_id ): ?string {
		// Map post type → entity type key in import-export.json.
		$entity_type_map = [
			'sd_donation'   => 'donations',
			'sd_membership' => 'memberships',
			'sd_memorial'   => 'memorials',
		];

		$entity_type = $entity_type_map[ $post_type ] ?? null;
		if ( ! $entity_type ) {
			return null;
		}

		$hash_columns = Config::get_path( 'import-export', "entity_types.{$entity_type}.import.hash_columns" );
		if ( empty( $hash_columns ) ) {
			return null;
		}

		return \Starter_Shelter\Admin\Import_Export\CSV_Importer::compute_post_hash(
			$entity_type,
			$post_id,
			$hash_columns
		);
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Purge
	// ─────────────────────────────────────────────────────────────

	/**
	 * Delete a batch of posts for a given post type.
	 *
	 * Expects POST: post_type, confirmation (must match post_type slug), offset
	 * Returns: { deleted, remaining, done }
	 */
	public static function ajax_purge(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_type    = sanitize_key( $_POST['post_type'] ?? '' );
		$confirmation = sanitize_key( $_POST['confirmation'] ?? '' );

		if ( ! isset( self::ENTITY_TYPES[ $post_type ] ) ) {
			wp_send_json_error( 'Invalid post type.' );
		}

		// Double-check confirmation matches.
		if ( $confirmation !== $post_type ) {
			wp_send_json_error( __( 'Confirmation does not match. Type the exact post type slug.', 'starter-shelter' ) );
		}

		// Get a batch of posts to delete.
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => self::BATCH_SIZE,
			'fields'         => 'ids',
		] );

		$deleted = 0;
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true ); // Force delete, skip trash.
			$deleted++;
		}

		// Check remaining.
		$remaining_counts = wp_count_posts( $post_type );
		$remaining = ( $remaining_counts->publish ?? 0 )
			+ ( $remaining_counts->draft ?? 0 )
			+ ( $remaining_counts->private ?? 0 )
			+ ( $remaining_counts->trash ?? 0 );

		wp_send_json_success( [
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => $remaining === 0,
		] );
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Recalculate Donor Stats
	// ─────────────────────────────────────────────────────────────

	/**
	 * Recalculate lifetime_giving for a batch of donors.
	 *
	 * Sums all sd_donation, sd_memorial, and sd_membership amounts
	 * linked to each donor.
	 */
	public static function ajax_recalc_donors(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

		$donors = get_posts( [
			'post_type'      => 'sd_donor',
			'post_status'    => 'any',
			'posts_per_page' => self::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		global $wpdb;

		$processed = 0;
		$updated   = 0;

		foreach ( $donors as $donor_id ) {
			$processed++;

			// Sum amounts across all three entity types in a single query.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(pm_amount.meta_value AS DECIMAL(15,2))), 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id
				     AND pm_donor.meta_key = '_sd_donor_id' AND pm_donor.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id
				     AND pm_amount.meta_key = '_sd_amount'
				 WHERE p.post_type IN ('sd_donation', 'sd_memorial', 'sd_membership')
				 AND p.post_status = 'publish'",
				$donor_id
			) );

			$current = (float) get_post_meta( $donor_id, '_sd_lifetime_giving', true );

			if ( abs( $total - $current ) > 0.005 ) {
				update_post_meta( $donor_id, '_sd_lifetime_giving', $total );
				$updated++;
			}
		}

		// Total donors for progress.
		$total_counts = wp_count_posts( 'sd_donor' );
		$total_donors = ( $total_counts->publish ?? 0 ) + ( $total_counts->draft ?? 0 );
		$new_offset   = $offset + count( $donors );

		wp_send_json_success( [
			'processed' => $processed,
			'updated'   => $updated,
			'offset'    => $new_offset,
			'total'     => $total_donors,
			'done'      => count( $donors ) < self::BATCH_SIZE,
		] );
	}
}
