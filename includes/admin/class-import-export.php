<?php
/**
 * Admin Import/Export - Bulk data import and export tools.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Handles import and export functionality for shelter data.
 *
 * @since 1.0.0
 */
class Import_Export {

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-import-export';

    /**
     * Nonce action.
     *
     * @var string
     */
    private const NONCE_ACTION = 'sd_import_export';

    /**
     * Initialize import/export.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        
        // Export handlers.
        add_action( 'admin_post_sd_export_donations', [ self::class, 'handle_export_donations' ] );
        add_action( 'admin_post_sd_export_memberships', [ self::class, 'handle_export_memberships' ] );
        add_action( 'admin_post_sd_export_donors', [ self::class, 'handle_export_donors' ] );
        add_action( 'admin_post_sd_export_memorials', [ self::class, 'handle_export_memorials' ] );
        
        // Import handlers (legacy form submissions).
        add_action( 'admin_post_sd_import_donors', [ self::class, 'handle_import_donors' ] );
        add_action( 'admin_post_sd_import_donations', [ self::class, 'handle_import_donations' ] );
        
        // AJAX handlers.
        add_action( 'wp_ajax_sd_preview_import', [ self::class, 'ajax_preview_import' ] );
        add_action( 'wp_ajax_sd_process_import', [ self::class, 'ajax_process_import' ] );
        add_action( 'wp_ajax_sd_download_template', [ self::class, 'ajax_download_template' ] );
        add_action( 'wp_ajax_sd_get_export_counts', [ self::class, 'ajax_get_export_counts' ] );
        
        // React UI AJAX import handlers.
        add_action( 'wp_ajax_sd_process_import_donors', [ self::class, 'ajax_process_import_donors' ] );
        add_action( 'wp_ajax_sd_process_import_donations', [ self::class, 'ajax_process_import_donations' ] );
        add_action( 'wp_ajax_sd_process_import_memorials', [ self::class, 'ajax_process_import_memorials' ] );
        add_action( 'wp_ajax_sd_process_import_memberships', [ self::class, 'ajax_process_import_memberships' ] );
        
        // Legacy format memorial import handler.
        add_action( 'wp_ajax_sd_process_import_memorials_legacy', [ self::class, 'ajax_process_import_memorials_legacy' ] );
        add_action( 'wp_ajax_sd_preview_import_memorials_legacy', [ self::class, 'ajax_preview_import_memorials_legacy' ] );
    }

    /**
     * Add import/export page to admin menu.
     */
    public static function add_menu_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Import / Export', 'starter-shelter' ),
            __( 'Import / Export', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_assets( string $hook ): void {
        // The hook for submenu pages under a custom menu is: {parent-slug}_page_{page-slug}
        // For our case: starter-shelter_page_starter-shelter-import-export
        $expected_hook = Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG;
        
        // Also check alternative hook format (admin.php based pages)
        $alt_hook = 'shelter-donations_page_' . self::PAGE_SLUG;
        
        if ( $hook !== $expected_hook && $hook !== $alt_hook && strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        // Check if we should use the React UI (default) or legacy PHP UI.
        $use_react_ui = apply_filters( 'starter_shelter_import_export_react_ui', true );

        if ( $use_react_ui ) {
            // Enqueue WordPress components and dependencies.
            wp_enqueue_script( 'wp-element' );
            wp_enqueue_script( 'wp-components' );
            wp_enqueue_script( 'wp-i18n' );
            wp_enqueue_script( 'wp-api-fetch' );
            wp_enqueue_style( 'wp-components' );

            // Enqueue our React app.
            wp_enqueue_script(
                'sd-import-export',
                STARTER_SHELTER_URL . 'assets/js/admin-import-export.js',
                [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
                STARTER_SHELTER_VERSION,
                true
            );

            // Get record counts.
            $counts = self::get_record_counts();

            // Localize script data.
            wp_localize_script( 'sd-import-export', 'sdImportExport', [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'adminPostUrl' => admin_url( 'admin-post.php' ),
                'exportNonce'  => wp_create_nonce( self::NONCE_ACTION ),
                'previewNonce' => wp_create_nonce( 'sd_preview_import' ),
                'importNonce'  => wp_create_nonce( 'sd_process_import' ),
                'counts'       => $counts,
                'templateUrls' => [
                    'donors'    => self::get_template_url( 'donors' ),
                    'donations'   => self::get_template_url( 'donations' ),
                    'memorials'   => self::get_template_url( 'memorials' ),
                    'memberships' => self::get_template_url( 'memberships' ),
                ],
                'debug'        => [
                    'hook'     => $hook,
                    'expected' => $expected_hook,
                ],
            ] );

            // Add minimal styles for the React UI.
            wp_add_inline_style( 'wp-components', self::get_react_ui_styles() );
        } else {
            // Legacy PHP UI styles.
            wp_add_inline_style( 'wp-admin', self::get_inline_styles() );
        }
    }

    /**
     * Render the import/export page.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $use_react_ui = apply_filters( 'starter_shelter_import_export_react_ui', true );

        if ( $use_react_ui ) {
            self::render_react_page();
            return;
        }

        // Legacy PHP UI.
        $active_tab = sanitize_key( $_GET['tab'] ?? 'export' );
        
        // Show import results if returning from import.
        self::maybe_show_import_results();
        ?>
        <div class="wrap sd-import-export">
            <h1><?php esc_html_e( 'Import / Export', 'starter-shelter' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'export' ) ); ?>" 
                   class="nav-tab <?php echo 'export' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Export Data', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'import' ) ); ?>" 
                   class="nav-tab <?php echo 'import' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Import Data', 'starter-shelter' ); ?>
                </a>
            </nav>

            <div class="sd-tab-content">
                <?php
                if ( 'import' === $active_tab ) {
                    self::render_import_tab();
                } else {
                    self::render_export_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Show import results notice if returning from import.
     */
    private static function maybe_show_import_results(): void {
        $imported = absint( $_GET['imported'] ?? 0 );
        $updated = absint( $_GET['updated'] ?? 0 );
        $skipped = absint( $_GET['skipped'] ?? 0 );
        $errors = absint( $_GET['errors'] ?? 0 );

        if ( ! $imported && ! $updated && ! $skipped && ! $errors ) {
            return;
        }

        $has_issues = $skipped > 0 || $errors > 0;
        $notice_class = $has_issues ? 'notice-warning' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible sd-import-results">
            <h3><?php esc_html_e( 'Import Complete', 'starter-shelter' ); ?></h3>
            <div class="sd-results-grid">
                <?php if ( $imported > 0 ) : ?>
                <div class="sd-result-item sd-result-success">
                    <span class="sd-result-icon">✓</span>
                    <span class="sd-result-count"><?php echo esc_html( $imported ); ?></span>
                    <span class="sd-result-label"><?php echo esc_html( _n( 'record created', 'records created', $imported, 'starter-shelter' ) ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $updated > 0 ) : ?>
                <div class="sd-result-item sd-result-updated">
                    <span class="sd-result-icon">↻</span>
                    <span class="sd-result-count"><?php echo esc_html( $updated ); ?></span>
                    <span class="sd-result-label"><?php echo esc_html( _n( 'record updated', 'records updated', $updated, 'starter-shelter' ) ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $skipped > 0 ) : ?>
                <div class="sd-result-item sd-result-skipped">
                    <span class="sd-result-icon">⊘</span>
                    <span class="sd-result-count"><?php echo esc_html( $skipped ); ?></span>
                    <span class="sd-result-label"><?php echo esc_html( _n( 'row skipped', 'rows skipped', $skipped, 'starter-shelter' ) ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $errors > 0 ) : ?>
                <div class="sd-result-item sd-result-error">
                    <span class="sd-result-icon">✕</span>
                    <span class="sd-result-count"><?php echo esc_html( $errors ); ?></span>
                    <span class="sd-result-label"><?php echo esc_html( _n( 'error', 'errors', $errors, 'starter-shelter' ) ); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ( $has_issues ) : ?>
            <p class="sd-results-note">
                <?php esc_html_e( 'Some rows were skipped due to validation errors. Check your CSV file and try again.', 'starter-shelter' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the export tab.
     */
    private static function render_export_tab(): void {
        // Get record counts.
        $counts = self::get_record_counts();
        ?>
        <div class="sd-export-section">
            <h2><?php esc_html_e( 'Export Data', 'starter-shelter' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Export your shelter data to CSV files for backup, reporting, or migration purposes.', 'starter-shelter' ); ?>
            </p>

            <div class="sd-export-cards">
                <!-- Donations Export -->
                <div class="sd-export-card" data-type="donations">
                    <div class="sd-export-header">
                        <div class="sd-export-icon">💰</div>
                        <div class="sd-export-count">
                            <span class="sd-count-number"><?php echo esc_html( number_format( $counts['donations'] ) ); ?></span>
                            <span class="sd-count-label"><?php esc_html_e( 'records', 'starter-shelter' ); ?></span>
                        </div>
                    </div>
                    <h3><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Export all donation records with donor info, amounts, allocations, and campaigns.', 'starter-shelter' ); ?></p>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sd_export_donations" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <div class="sd-export-options">
                            <label>
                                <?php esc_html_e( 'Date Range:', 'starter-shelter' ); ?>
                                <select name="date_range" class="sd-date-range-select">
                                    <option value="all"><?php esc_html_e( 'All Time', 'starter-shelter' ); ?></option>
                                    <option value="this_year"><?php esc_html_e( 'This Year', 'starter-shelter' ); ?></option>
                                    <option value="last_year"><?php esc_html_e( 'Last Year', 'starter-shelter' ); ?></option>
                                    <option value="this_month"><?php esc_html_e( 'This Month', 'starter-shelter' ); ?></option>
                                    <option value="custom"><?php esc_html_e( 'Custom Range', 'starter-shelter' ); ?></option>
                                </select>
                            </label>
                            <div class="sd-custom-dates" style="display:none;">
                                <input type="date" name="date_from" placeholder="<?php esc_attr_e( 'From', 'starter-shelter' ); ?>" />
                                <input type="date" name="date_to" placeholder="<?php esc_attr_e( 'To', 'starter-shelter' ); ?>" />
                            </div>
                        </div>
                        
                        <button type="submit" class="button button-primary" <?php disabled( $counts['donations'], 0 ); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Export Donations', 'starter-shelter' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Memberships Export -->
                <div class="sd-export-card" data-type="memberships">
                    <div class="sd-export-header">
                        <div class="sd-export-icon">🏅</div>
                        <div class="sd-export-count">
                            <span class="sd-count-number"><?php echo esc_html( number_format( $counts['memberships'] ) ); ?></span>
                            <span class="sd-count-label"><?php esc_html_e( 'records', 'starter-shelter' ); ?></span>
                        </div>
                    </div>
                    <h3><?php esc_html_e( 'Memberships', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Export membership records including tiers, dates, and business information.', 'starter-shelter' ); ?></p>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sd_export_memberships" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <div class="sd-export-options">
                            <label>
                                <?php esc_html_e( 'Status:', 'starter-shelter' ); ?>
                                <select name="status">
                                    <option value="all"><?php printf( esc_html__( 'All (%d)', 'starter-shelter' ), $counts['memberships'] ); ?></option>
                                    <option value="active"><?php printf( esc_html__( 'Active (%d)', 'starter-shelter' ), $counts['memberships_active'] ); ?></option>
                                    <option value="expired"><?php printf( esc_html__( 'Expired (%d)', 'starter-shelter' ), $counts['memberships_expired'] ); ?></option>
                                </select>
                            </label>
                        </div>
                        
                        <button type="submit" class="button button-primary" <?php disabled( $counts['memberships'], 0 ); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Export Memberships', 'starter-shelter' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Donors Export -->
                <div class="sd-export-card" data-type="donors">
                    <div class="sd-export-header">
                        <div class="sd-export-icon">👥</div>
                        <div class="sd-export-count">
                            <span class="sd-count-number"><?php echo esc_html( number_format( $counts['donors'] ) ); ?></span>
                            <span class="sd-count-label"><?php esc_html_e( 'records', 'starter-shelter' ); ?></span>
                        </div>
                    </div>
                    <h3><?php esc_html_e( 'Donors', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Export donor profiles with contact info, lifetime giving, and donor levels.', 'starter-shelter' ); ?></p>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sd_export_donors" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <div class="sd-export-options">
                            <label>
                                <input type="checkbox" name="include_stats" value="1" checked />
                                <?php esc_html_e( 'Include giving statistics', 'starter-shelter' ); ?>
                            </label>
                        </div>
                        
                        <button type="submit" class="button button-primary" <?php disabled( $counts['donors'], 0 ); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Export Donors', 'starter-shelter' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Memorials Export -->
                <div class="sd-export-card" data-type="memorials">
                    <div class="sd-export-header">
                        <div class="sd-export-icon">❤️</div>
                        <div class="sd-export-count">
                            <span class="sd-count-number"><?php echo esc_html( number_format( $counts['memorials'] ) ); ?></span>
                            <span class="sd-count-label"><?php esc_html_e( 'records', 'starter-shelter' ); ?></span>
                        </div>
                    </div>
                    <h3><?php esc_html_e( 'Memorials', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Export memorial tributes with honoree information and tribute messages.', 'starter-shelter' ); ?></p>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sd_export_memorials" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <button type="submit" class="button button-primary" <?php disabled( $counts['memorials'], 0 ); ?>>
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Export Memorials', 'starter-shelter' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('select[name="date_range"]').on('change', function() {
                var $customDates = $(this).closest('form').find('.sd-custom-dates');
                if ($(this).val() === 'custom') {
                    $customDates.slideDown();
                } else {
                    $customDates.slideUp();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the import tab.
     */
    private static function render_import_tab(): void {
        ?>
        <div class="sd-import-section">
            <h2><?php esc_html_e( 'Import Data', 'starter-shelter' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Import data from CSV files. Download a sample template to see the expected format.', 'starter-shelter' ); ?>
            </p>

            <div class="sd-import-cards">
                <!-- Donors Import -->
                <div class="sd-import-card">
                    <div class="sd-import-icon">👥</div>
                    <h3><?php esc_html_e( 'Import Donors', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Import donor records from a CSV file. Existing donors will be matched by email.', 'starter-shelter' ); ?></p>
                    
                    <div class="sd-template-info">
                        <strong><?php esc_html_e( 'Required columns:', 'starter-shelter' ); ?></strong>
                        <code>email</code>, <code>first_name</code>, <code>last_name</code><br>
                        <strong><?php esc_html_e( 'Optional:', 'starter-shelter' ); ?></strong>
                        <code>phone</code>, <code>address_line_1</code>, <code>city</code>, <code>state</code>, <code>postal_code</code>, <code>country</code>
                    </div>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sd-import-form" data-type="donors">
                        <input type="hidden" name="action" value="sd_import_donors" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <div class="sd-file-upload">
                            <input type="file" name="import_file" accept=".csv" required />
                        </div>
                        
                        <div class="sd-import-options">
                            <label>
                                <input type="checkbox" name="update_existing" value="1" checked />
                                <?php esc_html_e( 'Update existing donors (matched by email)', 'starter-shelter' ); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="skip_errors" value="1" checked />
                                <?php esc_html_e( 'Skip rows with errors and continue', 'starter-shelter' ); ?>
                            </label>
                        </div>
                        
                        <div class="sd-import-actions">
                            <a href="<?php echo esc_url( self::get_template_url( 'donors' ) ); ?>" class="button">
                                <span class="dashicons dashicons-media-spreadsheet"></span>
                                <?php esc_html_e( 'Download Template', 'starter-shelter' ); ?>
                            </a>
                            <button type="button" class="button sd-preview-import">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e( 'Preview', 'starter-shelter' ); ?>
                            </button>
                            <button type="submit" class="button button-primary" disabled>
                                <span class="dashicons dashicons-upload"></span>
                                <?php esc_html_e( 'Import Donors', 'starter-shelter' ); ?>
                            </button>
                        </div>
                        
                        <div class="sd-import-preview" style="display:none;"></div>
                    </form>
                </div>

                <!-- Donations Import -->
                <div class="sd-import-card">
                    <div class="sd-import-icon">💰</div>
                    <h3><?php esc_html_e( 'Import Donations', 'starter-shelter' ); ?></h3>
                    <p><?php esc_html_e( 'Import historical donation records. Donors will be created or matched by email.', 'starter-shelter' ); ?></p>
                    
                    <div class="sd-template-info">
                        <strong><?php esc_html_e( 'Required columns:', 'starter-shelter' ); ?></strong>
                        <code>email</code>, <code>amount</code>, <code>date</code><br>
                        <strong><?php esc_html_e( 'Optional:', 'starter-shelter' ); ?></strong>
                        <code>first_name</code>, <code>last_name</code>, <code>allocation</code>, <code>is_anonymous</code>, <code>dedication</code>
                    </div>
                    
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sd-import-form" data-type="donations">
                        <input type="hidden" name="action" value="sd_import_donations" />
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        
                        <div class="sd-file-upload">
                            <input type="file" name="import_file" accept=".csv" required />
                        </div>
                        
                        <div class="sd-import-options">
                            <label>
                                <input type="checkbox" name="create_donors" value="1" checked />
                                <?php esc_html_e( 'Create new donors if not found', 'starter-shelter' ); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="skip_errors" value="1" checked />
                                <?php esc_html_e( 'Skip rows with errors and continue', 'starter-shelter' ); ?>
                            </label>
                        </div>
                        
                        <div class="sd-import-actions">
                            <a href="<?php echo esc_url( self::get_template_url( 'donations' ) ); ?>" class="button">
                                <span class="dashicons dashicons-media-spreadsheet"></span>
                                <?php esc_html_e( 'Download Template', 'starter-shelter' ); ?>
                            </a>
                            <button type="button" class="button sd-preview-import">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e( 'Preview', 'starter-shelter' ); ?>
                            </button>
                            <button type="submit" class="button button-primary" disabled>
                                <span class="dashicons dashicons-upload"></span>
                                <?php esc_html_e( 'Import Donations', 'starter-shelter' ); ?>
                            </button>
                        </div>
                        
                        <div class="sd-import-preview" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.sd-preview-import').on('click', function() {
                var $form = $(this).closest('form');
                var $fileInput = $form.find('input[type="file"]');
                var $preview = $form.find('.sd-import-preview');
                var $submitBtn = $form.find('button[type="submit"]');
                
                if (!$fileInput[0].files.length) {
                    alert('<?php echo esc_js( __( 'Please select a file first.', 'starter-shelter' ) ); ?>');
                    return;
                }
                
                var formData = new FormData();
                formData.append('action', 'sd_preview_import');
                formData.append('import_type', $form.data('type'));
                formData.append('file', $fileInput[0].files[0]);
                formData.append('nonce', '<?php echo wp_create_nonce( 'sd_preview_import' ); ?>');
                
                $preview.html('<p><?php echo esc_js( __( 'Loading preview...', 'starter-shelter' ) ); ?></p>').slideDown();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $preview.html(response.data.html);
                            if (response.data.valid_count > 0) {
                                $submitBtn.prop('disabled', false);
                            }
                        } else {
                            $preview.html('<p class="error">' + (response.data || 'Error') + '</p>');
                        }
                    },
                    error: function() {
                        $preview.html('<p class="error"><?php echo esc_js( __( 'Error processing file.', 'starter-shelter' ) ); ?></p>');
                    }
                });
            });
            
            $('input[type="file"]').on('change', function() {
                $(this).closest('form').find('button[type="submit"]').prop('disabled', true);
                $(this).closest('form').find('.sd-import-preview').slideUp();
            });
        });
        </script>
        <?php
    }

    /**
     * Handle donations export.
     */
    public static function handle_export_donations(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $date_range = sanitize_key( $_POST['date_range'] ?? 'all' );
        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to = sanitize_text_field( $_POST['date_to'] ?? '' );

        // Build query args.
        $args = [
            'post_type'      => 'sd_donation',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Apply date filter.
        $date_query = self::get_date_query( $date_range, $date_from, $date_to );
        if ( $date_query ) {
            $args['date_query'] = $date_query;
        }

        $query = new \WP_Query( $args );

        // Output CSV.
        $filename = 'shelter-donations-' . wp_date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        // Headers.
        fputcsv( $output, [
            'ID', 'Date', 'Donor Name', 'Donor Email', 'Amount', 'Allocation',
            'Campaign', 'Is Anonymous', 'Dedication', 'Order ID'
        ] );

        // Data rows.
        foreach ( $query->posts as $post ) {
            $donation = Entity_Hydrator::get( 'sd_donation', $post->ID );
            $donor = $donation['donor_id'] ? Entity_Hydrator::get( 'sd_donor', $donation['donor_id'] ) : null;
            
            $campaigns = get_the_terms( $post->ID, 'sd_campaign' );
            $campaign_names = $campaigns && ! is_wp_error( $campaigns ) 
                ? implode( ', ', wp_list_pluck( $campaigns, 'name' ) ) 
                : '';

            fputcsv( $output, [
                $post->ID,
                $donation['donation_date'] ?? $post->post_date,
                $donor ? trim( $donor['first_name'] . ' ' . $donor['last_name'] ) : '',
                $donor['email'] ?? '',
                $donation['amount'] ?? 0,
                $donation['allocation'] ?? '',
                $campaign_names,
                $donation['is_anonymous'] ? 'Yes' : 'No',
                $donation['dedication'] ?? '',
                $donation['wc_order_id'] ?? '',
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle memberships export.
     */
    public static function handle_export_memberships(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $status_filter = sanitize_key( $_POST['status'] ?? 'all' );

        $args = [
            'post_type'      => 'sd_membership',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Apply status filter.
        $today = wp_date( 'Y-m-d' );
        if ( 'active' === $status_filter ) {
            $args['meta_query'] = [
                [ 'key' => '_sd_end_date', 'value' => $today, 'compare' => '>=' ]
            ];
        } elseif ( 'expired' === $status_filter ) {
            $args['meta_query'] = [
                [ 'key' => '_sd_end_date', 'value' => $today, 'compare' => '<' ]
            ];
        }

        $query = new \WP_Query( $args );

        $filename = 'shelter-memberships-' . wp_date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        fputcsv( $output, [
            'ID', 'Member Name', 'Email', 'Type', 'Tier', 'Amount',
            'Start Date', 'End Date', 'Status', 'Business Name', 'Order ID'
        ] );

        foreach ( $query->posts as $post ) {
            $membership = Entity_Hydrator::get( 'sd_membership', $post->ID );
            $donor = $membership['donor_id'] ? Entity_Hydrator::get( 'sd_donor', $membership['donor_id'] ) : null;
            
            $is_active = ! empty( $membership['end_date'] ) && strtotime( $membership['end_date'] ) >= time();

            fputcsv( $output, [
                $post->ID,
                $donor ? trim( $donor['first_name'] . ' ' . $donor['last_name'] ) : '',
                $donor['email'] ?? '',
                $membership['membership_type'] ?? '',
                $membership['tier'] ?? '',
                $membership['amount'] ?? 0,
                $membership['start_date'] ?? '',
                $membership['end_date'] ?? '',
                $is_active ? 'Active' : 'Expired',
                $membership['business_name'] ?? '',
                $membership['wc_order_id'] ?? '',
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle donors export.
     */
    public static function handle_export_donors(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $include_stats = ! empty( $_POST['include_stats'] );

        $args = [
            'post_type'      => 'sd_donor',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $query = new \WP_Query( $args );

        $filename = 'shelter-donors-' . wp_date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        $headers = [
            'ID', 'First Name', 'Last Name', 'Email', 'Phone',
            'Address', 'City', 'State', 'Postal Code', 'Country'
        ];
        
        if ( $include_stats ) {
            $headers = array_merge( $headers, [
                'Lifetime Giving', 'Donation Count', 'Donor Level', 'First Donation', 'Last Donation'
            ] );
        }

        fputcsv( $output, $headers );

        foreach ( $query->posts as $post ) {
            $donor = Entity_Hydrator::get( 'sd_donor', $post->ID );

            $row = [
                $post->ID,
                $donor['first_name'] ?? '',
                $donor['last_name'] ?? '',
                $donor['email'] ?? '',
                $donor['phone'] ?? '',
                $donor['address_line_1'] ?? '',
                $donor['city'] ?? '',
                $donor['state'] ?? '',
                $donor['postal_code'] ?? '',
                $donor['country'] ?? '',
            ];

            if ( $include_stats ) {
                $row = array_merge( $row, [
                    $donor['lifetime_giving'] ?? 0,
                    $donor['donation_count'] ?? 0,
                    $donor['donor_level'] ?? 'new',
                    $donor['first_donation_date'] ?? '',
                    $donor['last_donation_date'] ?? '',
                ] );
            }

            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle memorials export.
     */
    public static function handle_export_memorials(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $args = [
            'post_type'      => 'sd_memorial',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new \WP_Query( $args );

        $filename = 'shelter-memorials-' . wp_date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        fputcsv( $output, [
            'ID', 'Date', 'Honoree Name', 'Type', 'Pet Species', 'Tribute Message',
            'Donor Name', 'Donor Email', 'Amount', 'Is Anonymous', 'Family Notified'
        ] );

        foreach ( $query->posts as $post ) {
            $memorial = Entity_Hydrator::get( 'sd_memorial', $post->ID );
            $donor = $memorial['donor_id'] ? Entity_Hydrator::get( 'sd_donor', $memorial['donor_id'] ) : null;
            
            $notified = get_post_meta( $post->ID, '_sd_family_notified_date', true );

            fputcsv( $output, [
                $post->ID,
                $memorial['donation_date'] ?? $post->post_date,
                $memorial['honoree_name'] ?? '',
                $memorial['memorial_type'] ?? '',
                $memorial['pet_species'] ?? '',
                $memorial['tribute_message'] ?? '',
                $donor ? trim( $donor['first_name'] . ' ' . $donor['last_name'] ) : '',
                $donor['email'] ?? '',
                $memorial['amount'] ?? 0,
                ( $memorial['is_anonymous'] ?? false ) ? 'Yes' : 'No',
                $notified ? 'Yes' : 'No',
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle donor import.
     */
    public static function handle_import_donors(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['import_file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_die( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $update_existing = ! empty( $_POST['update_existing'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_donor_import( $file['tmp_name'], $update_existing, $skip_errors );

        wp_safe_redirect( add_query_arg( [
            'page'     => self::PAGE_SLUG,
            'tab'      => 'import',
            'imported' => $results['created'],
            'updated'  => $results['updated'],
            'skipped'  => $results['skipped'],
            'errors'   => $results['errors'],
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Handle donation import.
     */
    public static function handle_import_donations(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['import_file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_die( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $create_donors = ! empty( $_POST['create_donors'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_donation_import( $file['tmp_name'], $create_donors, $skip_errors );

        wp_safe_redirect( add_query_arg( [
            'page'     => self::PAGE_SLUG,
            'tab'      => 'import',
            'imported' => $results['created'],
            'skipped'  => $results['skipped'],
            'errors'   => $results['errors'],
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * AJAX preview import.
     */
    public static function ajax_preview_import(): void {
        check_ajax_referer( 'sd_preview_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $import_type = sanitize_key( $_POST['import_type'] ?? '' );
        $file = $_FILES['file'] ?? null;

        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $preview = self::generate_import_preview( $file['tmp_name'], $import_type );
        
        wp_send_json_success( $preview );
    }

    /**
     * Generate import preview.
     */
    private static function generate_import_preview( string $filepath, string $type ): array {
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            return [ 'html' => '<p class="error">Could not read file.</p>', 'valid_count' => 0 ];
        }

        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            return [ 'html' => '<p class="error">Empty file or invalid CSV.</p>', 'valid_count' => 0 ];
        }

        // Normalize headers.
        $headers = array_map( 'strtolower', array_map( 'trim', $headers ) );

        // Check required columns.
        $required = 'donors' === $type 
            ? [ 'email', 'first_name', 'last_name' ]
            : [ 'email', 'amount', 'date' ];

        $missing = array_diff( $required, $headers );
        if ( ! empty( $missing ) ) {
            fclose( $handle );
            return [
                'html' => '<p class="error">' . sprintf(
                    __( 'Missing required columns: %s', 'starter-shelter' ),
                    implode( ', ', $missing )
                ) . '</p>',
                'valid_count' => 0
            ];
        }

        // Preview first 5 rows.
        $rows = [];
        $valid_count = 0;
        $row_num = 0;

        while ( ( $data = fgetcsv( $handle ) ) !== false && $row_num < 100 ) {
            $row_num++;
            $row = array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
            
            $errors = self::validate_import_row( $row, $type );
            
            if ( empty( $errors ) ) {
                $valid_count++;
            }

            if ( count( $rows ) < 10 ) {
                $rows[] = [
                    'data'  => $row,
                    'valid' => empty( $errors ),
                    'error' => ! empty( $errors ) ? $errors[0] : null,
                ];
            }
        }

        // Count remaining rows.
        while ( fgetcsv( $handle ) !== false ) {
            $row_num++;
            $valid_count++; // Assume valid for count.
        }

        fclose( $handle );

        // Return data suitable for both React and legacy UI.
        return [
            'headers'    => $headers,
            'rows'       => $rows,
            'totalRows'  => $row_num,
            'validRows'  => $valid_count,
            // Legacy HTML output for PHP UI.
            'html'       => self::generate_preview_html( $headers, $rows, $row_num, $valid_count ),
            'valid_count' => $valid_count,
        ];
    }

    /**
     * Generate HTML preview table (for legacy PHP UI).
     */
    private static function generate_preview_html( array $headers, array $rows, int $total_rows, int $valid_count ): string {
        ob_start();
        ?>
        <div class="sd-preview-summary">
            <p>
                <strong><?php esc_html_e( 'File Summary:', 'starter-shelter' ); ?></strong>
                <?php printf( __( '%d total rows, approximately %d valid', 'starter-shelter' ), $total_rows, $valid_count ); ?>
            </p>
        </div>

        <table class="widefat sd-preview-table">
            <thead>
                <tr>
                    <th>#</th>
                    <?php foreach ( array_slice( $headers, 0, 5 ) as $header ) : ?>
                    <th><?php echo esc_html( ucfirst( str_replace( '_', ' ', $header ) ) ); ?></th>
                    <?php endforeach; ?>
                    <th><?php esc_html_e( 'Status', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $i => $row ) : ?>
                <tr class="<?php echo $row['valid'] ? 'sd-row-valid' : 'sd-row-error'; ?>">
                    <td><?php echo esc_html( $i + 1 ); ?></td>
                    <?php foreach ( array_slice( $headers, 0, 5 ) as $header ) : ?>
                    <td><?php echo esc_html( substr( $row['data'][ $header ] ?? '', 0, 30 ) ); ?></td>
                    <?php endforeach; ?>
                    <td>
                        <?php if ( $row['valid'] ) : ?>
                        <span class="dashicons dashicons-yes" style="color:green;"></span>
                        <?php else : ?>
                        <span class="dashicons dashicons-warning" style="color:red;" title="<?php echo esc_attr( $row['error'] ?? '' ); ?>"></span>
                        <?php echo esc_html( $row['error'] ?? '' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_rows > 5 ) : ?>
        <p class="description"><?php printf( __( '... and %d more rows', 'starter-shelter' ), $total_rows - 5 ); ?></p>
        <?php endif; ?>
        <?php

        return ob_get_clean();
    }

    /**
     * Validate an import row.
     */
    private static function validate_import_row( array $row, string $type ): array {
        $errors = [];

        switch ( $type ) {
            case 'donors':
                if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
                    $errors[] = __( 'Invalid email', 'starter-shelter' );
                }
                if ( empty( $row['first_name'] ) ) {
                    $errors[] = __( 'Missing first name', 'starter-shelter' );
                }
                break;

            case 'donations':
                if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
                    $errors[] = __( 'Invalid email', 'starter-shelter' );
                }
                if ( empty( $row['amount'] ) || ! is_numeric( $row['amount'] ) || $row['amount'] <= 0 ) {
                    $errors[] = __( 'Invalid amount', 'starter-shelter' );
                }
                if ( empty( $row['date'] ) || ! strtotime( $row['date'] ) ) {
                    $errors[] = __( 'Invalid date', 'starter-shelter' );
                }
                break;

            case 'memorials':
                if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
                    $errors[] = __( 'Invalid email', 'starter-shelter' );
                }
                if ( empty( $row['honoree_name'] ) ) {
                    $errors[] = __( 'Missing honoree name', 'starter-shelter' );
                }
                if ( empty( $row['amount'] ) || ! is_numeric( $row['amount'] ) || $row['amount'] <= 0 ) {
                    $errors[] = __( 'Invalid amount', 'starter-shelter' );
                }
                if ( empty( $row['date'] ) || ! strtotime( $row['date'] ) ) {
                    $errors[] = __( 'Invalid date', 'starter-shelter' );
                }
                // Validate memorial_type if provided.
                if ( ! empty( $row['memorial_type'] ) && ! in_array( $row['memorial_type'], [ 'person', 'pet', 'honor' ], true ) ) {
                    $errors[] = __( 'Invalid memorial type (use: person, pet, honor)', 'starter-shelter' );
                }
                break;

            case 'memberships':
                if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
                    $errors[] = __( 'Invalid email', 'starter-shelter' );
                }
                if ( empty( $row['membership_type'] ) || ! in_array( $row['membership_type'], [ 'individual', 'family', 'business' ], true ) ) {
                    $errors[] = __( 'Invalid membership type (use: individual, family, business)', 'starter-shelter' );
                }
                if ( empty( $row['tier'] ) ) {
                    $errors[] = __( 'Missing tier', 'starter-shelter' );
                }
                if ( empty( $row['amount'] ) || ! is_numeric( $row['amount'] ) || $row['amount'] <= 0 ) {
                    $errors[] = __( 'Invalid amount', 'starter-shelter' );
                }
                if ( empty( $row['start_date'] ) || ! strtotime( $row['start_date'] ) ) {
                    $errors[] = __( 'Invalid start date', 'starter-shelter' );
                }
                if ( empty( $row['end_date'] ) || ! strtotime( $row['end_date'] ) ) {
                    $errors[] = __( 'Invalid end date', 'starter-shelter' );
                }
                // Business memberships require business_name.
                if ( ! empty( $row['membership_type'] ) && $row['membership_type'] === 'business' ) {
                    if ( empty( $row['business_name'] ) ) {
                        $errors[] = __( 'Business name required for business memberships', 'starter-shelter' );
                    }
                }
                break;

            default:
                $errors[] = __( 'Unknown import type', 'starter-shelter' );
        }

        return $errors;
    }

    /**
     * Process donor import.
     */
    private static function process_donor_import( string $filepath, bool $update_existing, bool $skip_errors ): array {
        $handle = fopen( $filepath, 'r' );
        $headers = array_map( 'strtolower', array_map( 'trim', fgetcsv( $handle ) ) );

        $results = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 ];

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row = array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
            $errors = self::validate_import_row( $row, 'donors' );

            if ( ! empty( $errors ) ) {
                if ( $skip_errors ) {
                    $results['skipped']++;
                    continue;
                } else {
                    $results['errors']++;
                    continue;
                }
            }

            // Check if donor exists.
            $existing = get_posts( [
                'post_type'  => 'sd_donor',
                'meta_key'   => '_sd_email',
                'meta_value' => sanitize_email( $row['email'] ),
                'numberposts' => 1,
            ] );

            if ( ! empty( $existing ) ) {
                if ( $update_existing ) {
                    $donor_id = $existing[0]->ID;
                    self::update_donor_from_row( $donor_id, $row );
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                self::create_donor_from_row( $row );
                $results['created']++;
            }
        }

        fclose( $handle );
        return $results;
    }

    /**
     * Process donation import.
     */
    private static function process_donation_import( string $filepath, bool $create_donors, bool $skip_errors ): array {
        $handle = fopen( $filepath, 'r' );
        $headers = array_map( 'strtolower', array_map( 'trim', fgetcsv( $handle ) ) );

        $results = [ 'created' => 0, 'skipped' => 0, 'errors' => 0 ];

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row = array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
            $errors = self::validate_import_row( $row, 'donations' );

            if ( ! empty( $errors ) ) {
                if ( $skip_errors ) {
                    $results['skipped']++;
                    continue;
                } else {
                    $results['errors']++;
                    continue;
                }
            }

            // Find or create donor.
            $existing_donor = get_posts( [
                'post_type'  => 'sd_donor',
                'meta_key'   => '_sd_email',
                'meta_value' => sanitize_email( $row['email'] ),
                'numberposts' => 1,
            ] );

            if ( ! empty( $existing_donor ) ) {
                $donor_id = $existing_donor[0]->ID;
            } elseif ( $create_donors ) {
                $donor_id = self::create_donor_from_row( $row );
            } else {
                $results['skipped']++;
                continue;
            }

            // Create donation.
            self::create_donation_from_row( $row, $donor_id );
            $results['created']++;
        }

        fclose( $handle );
        return $results;
    }

    /**
     * Create donor from import row.
     */
    private static function create_donor_from_row( array $row ): int {
        $donor_id = wp_insert_post( [
            'post_type'   => 'sd_donor',
            'post_status' => 'publish',
            'post_title'  => trim( $row['first_name'] . ' ' . ( $row['last_name'] ?? '' ) ),
        ] );

        self::update_donor_from_row( $donor_id, $row );

        return $donor_id;
    }

    /**
     * Update donor from import row.
     */
    private static function update_donor_from_row( int $donor_id, array $row ): void {
        $fields = [
            'first_name'     => 'first_name',
            'last_name'      => 'last_name',
            'email'          => 'email',
            'phone'          => 'phone',
            'address_line_1' => 'address_line_1',
            'address_line_2' => 'address_line_2',
            'city'           => 'city',
            'state'          => 'state',
            'postal_code'    => 'postal_code',
            'country'        => 'country',
        ];

        foreach ( $fields as $csv_field => $meta_field ) {
            if ( isset( $row[ $csv_field ] ) && '' !== $row[ $csv_field ] ) {
                update_post_meta( $donor_id, '_sd_' . $meta_field, sanitize_text_field( $row[ $csv_field ] ) );
            }
        }
    }

    /**
     * Create donation from import row.
     */
    private static function create_donation_from_row( array $row, int $donor_id ): int {
        $donation_id = wp_insert_post( [
            'post_type'   => 'sd_donation',
            'post_status' => 'publish',
            'post_title'  => sprintf( 'Donation - %s', wp_date( 'Y-m-d', strtotime( $row['date'] ) ) ),
            'post_date'   => wp_date( 'Y-m-d H:i:s', strtotime( $row['date'] ) ),
        ] );

        update_post_meta( $donation_id, '_sd_donor_id', $donor_id );
        update_post_meta( $donation_id, '_sd_amount', floatval( $row['amount'] ) );
        update_post_meta( $donation_id, '_sd_donation_date', wp_date( 'Y-m-d H:i:s', strtotime( $row['date'] ) ) );
        
        if ( ! empty( $row['allocation'] ) ) {
            update_post_meta( $donation_id, '_sd_allocation', sanitize_key( $row['allocation'] ) );
        }
        
        if ( isset( $row['is_anonymous'] ) ) {
            $is_anon = in_array( strtolower( $row['is_anonymous'] ), [ 'yes', '1', 'true' ], true );
            update_post_meta( $donation_id, '_sd_is_anonymous', $is_anon ? 1 : 0 );
        }
        
        if ( ! empty( $row['dedication'] ) ) {
            update_post_meta( $donation_id, '_sd_dedication', sanitize_textarea_field( $row['dedication'] ) );
        }

        return $donation_id;
    }

    /**
     * Get date query array based on range selection.
     */
    private static function get_date_query( string $range, string $from = '', string $to = '' ): ?array {
        switch ( $range ) {
            case 'this_year':
                return [ [ 'year' => (int) wp_date( 'Y' ) ] ];
            case 'last_year':
                return [ [ 'year' => (int) wp_date( 'Y' ) - 1 ] ];
            case 'this_month':
                return [ [ 'year' => (int) wp_date( 'Y' ), 'month' => (int) wp_date( 'n' ) ] ];
            case 'custom':
                if ( $from && $to ) {
                    return [ [ 'after' => $from, 'before' => $to, 'inclusive' => true ] ];
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Get template download URL.
     */
    private static function get_template_url( string $type ): string {
        return add_query_arg( [
            'action'   => 'sd_download_template',
            'type'     => $type,
            '_wpnonce' => wp_create_nonce( 'sd_download_template' ),
        ], admin_url( 'admin-ajax.php' ) );
    }

    /**
     * Get inline styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-export-cards, .sd-import-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .sd-export-card, .sd-import-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
            }
            .sd-export-icon, .sd-import-icon {
                font-size: 32px;
            }
            .sd-export-card h3, .sd-import-card h3 {
                margin: 0 0 10px;
            }
            .sd-export-card p, .sd-import-card p {
                color: #646970;
                margin-bottom: 15px;
            }
            .sd-export-options, .sd-import-options {
                margin-bottom: 15px;
            }
            .sd-export-options label, .sd-import-options label {
                display: block;
                margin-bottom: 8px;
            }
            .sd-custom-dates {
                margin-top: 10px;
                display: flex;
                gap: 10px;
            }
            .sd-file-upload {
                margin-bottom: 15px;
            }
            .sd-file-upload input[type="file"] {
                width: 100%;
                padding: 10px;
                border: 2px dashed #c3c4c7;
                border-radius: 4px;
                background: #f6f7f7;
            }
            .sd-import-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .sd-import-preview {
                margin-top: 15px;
                padding: 15px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .sd-preview-table {
                font-size: 12px;
            }
            .sd-preview-table th, .sd-preview-table td {
                padding: 6px 8px;
            }
            .sd-row-error {
                background: #fcf0f1 !important;
            }
            .sd-row-valid {
                background: #edfaef !important;
            }
            
            /* Export header with count */
            .sd-export-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 10px;
            }
            .sd-export-count {
                text-align: right;
            }
            .sd-count-number {
                display: block;
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
                line-height: 1;
            }
            .sd-count-label {
                font-size: 11px;
                color: #646970;
                text-transform: uppercase;
            }
            
            /* Import results notice */
            .sd-import-results {
                padding: 15px 20px;
            }
            .sd-import-results h3 {
                margin: 0 0 15px;
            }
            .sd-results-grid {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            .sd-result-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px 15px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .sd-result-icon {
                font-size: 18px;
            }
            .sd-result-count {
                font-size: 20px;
                font-weight: 600;
            }
            .sd-result-label {
                color: #646970;
            }
            .sd-result-success { background: #d1fae5; }
            .sd-result-success .sd-result-count { color: #065f46; }
            .sd-result-updated { background: #dbeafe; }
            .sd-result-updated .sd-result-count { color: #1e40af; }
            .sd-result-skipped { background: #fef3c7; }
            .sd-result-skipped .sd-result-count { color: #92400e; }
            .sd-result-error { background: #fee2e2; }
            .sd-result-error .sd-result-count { color: #991b1b; }
            .sd-results-note {
                margin: 15px 0 0;
                color: #646970;
            }
            
            /* Template info box */
            .sd-template-info {
                background: #f0f6fc;
                border: 1px solid #c3c4c7;
                border-left: 4px solid #2271b1;
                padding: 12px 15px;
                margin-bottom: 15px;
                border-radius: 0 4px 4px 0;
            }
            .sd-template-info code {
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
            }
        ';
    }

    /**
     * Get record counts for all CPTs.
     *
     * @return array Record counts.
     */
    private static function get_record_counts(): array {
        global $wpdb;

        $today = wp_date( 'Y-m-d' );

        return [
            'donations' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sd_donation' AND post_status = 'publish'"
            ),
            'memberships' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sd_membership' AND post_status = 'publish'"
            ),
            'memberships_active' => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
                 WHERE p.post_type = 'sd_membership' AND p.post_status = 'publish' AND pm.meta_value >= %s",
                $today
            ) ),
            'memberships_expired' => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
                 WHERE p.post_type = 'sd_membership' AND p.post_status = 'publish' AND pm.meta_value < %s",
                $today
            ) ),
            'donors' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sd_donor' AND post_status = 'publish'"
            ),
            'memorials' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sd_memorial' AND post_status = 'publish'"
            ),
        ];
    }

    /**
     * AJAX handler for template download.
     */
    public static function ajax_download_template(): void {
        check_ajax_referer( 'sd_download_template', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $type = sanitize_key( $_GET['type'] ?? '' );
        
        $templates = [
            'donors' => [
                'filename' => 'donor-import-template.csv',
                'headers'  => [ 'email', 'first_name', 'last_name', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ],
                'examples' => [
                    [ 'john@example.com', 'John', 'Doe', '555-123-4567', '123 Main St', 'Apt 4', 'Springfield', 'IL', '62701', 'US' ],
                ],
            ],
            'donations' => [
                'filename' => 'donation-import-template.csv',
                'headers'  => [ 'email', 'first_name', 'last_name', 'amount', 'date', 'allocation', 'is_anonymous', 'dedication' ],
                'examples' => [
                    [ 'john@example.com', 'John', 'Doe', '100.00', '2024-01-15', 'general-fund', 'no', 'In memory of Fluffy' ],
                    [ 'jane@example.com', 'Jane', 'Smith', '250.00', '2024-02-20', 'medical-care', 'yes', '' ],
                ],
            ],
            'memorials' => [
                'filename' => 'memorial-import-template.csv',
                'headers'  => [ 'email', 'first_name', 'last_name', 'honoree_name', 'memorial_type', 'amount', 'date', 'tribute_message', 'pet_species', 'is_anonymous', 'notify_family_email' ],
                'examples' => [
                    [ 'john@example.com', 'John', 'Doe', 'Mary Smith', 'person', '100.00', '2024-01-15', 'In loving memory of a wonderful friend', '', 'no', 'family@example.com' ],
                    [ 'jane@example.com', 'Jane', 'Smith', 'Fluffy', 'pet', '50.00', '2024-02-10', 'Best cat ever', 'cat', 'no', '' ],
                    [ 'bob@example.com', 'Bob', 'Jones', 'Dr. Williams', 'honor', '200.00', '2024-03-05', 'In honor of your retirement', '', 'yes', '' ],
                ],
            ],
            'memberships' => [
                'filename' => 'membership-import-template.csv',
                'headers'  => [ 'email', 'first_name', 'last_name', 'membership_type', 'tier', 'amount', 'start_date', 'end_date', 'business_name', 'business_website', 'business_description' ],
                'examples' => [
                    [ 'john@example.com', 'John', 'Doe', 'individual', 'bronze', '50.00', '2024-01-01', '2025-01-01', '', '', '' ],
                    [ 'jane@example.com', 'Jane', 'Smith', 'family', 'silver', '100.00', '2024-02-15', '2025-02-15', '', '', '' ],
                    [ 'contact@acme.com', 'Bob', 'Wilson', 'business', 'gold', '500.00', '2024-03-01', '2025-03-01', 'Acme Pet Supplies', 'https://acme-pets.com', 'Local pet supply store serving the community since 1990' ],
                ],
            ],
        ];

        if ( ! isset( $templates[ $type ] ) ) {
            wp_die( __( 'Invalid template type.', 'starter-shelter' ) );
        }

        $template = $templates[ $type ];

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $template['filename'] );

        $output = fopen( 'php://output', 'w' );
        
        // Write headers.
        fputcsv( $output, $template['headers'] );

        if ( ! isset( $templates[ $type ] ) ) {
            wp_die( __( 'Invalid template type.', 'starter-shelter' ) );
        }

        $template = $templates[ $type ];

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $template['filename'] );

        $output = fopen( 'php://output', 'w' );
        
        // Write headers.
        fputcsv( $output, $template['headers'] );
        
        // Write example rows.
        foreach ( $template['examples'] as $example ) {
            fputcsv( $output, $example );
        }

        fclose( $output );
        exit;
    }

    /**
     * AJAX handler for getting export counts (for dynamic updates).
     */
    public static function ajax_get_export_counts(): void {
        check_ajax_referer( 'sd_export_counts', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        wp_send_json_success( self::get_record_counts() );
    }

    /**
     * Render the React-based import/export page.
     */
    private static function render_react_page(): void {
        ?>
        <div class="wrap">
            <div id="sd-import-export-root">
                <p><?php esc_html_e( 'Loading Import/Export interface...', 'starter-shelter' ); ?></p>
                <p class="description">
                    <?php esc_html_e( 'If this message persists, check the browser console for errors.', 'starter-shelter' ); ?>
                </p>
            </div>
        </div>
        <script>
        // Debug: Check if dependencies are loaded
        (function() {
            var checks = {
                'wp': typeof wp !== 'undefined',
                'wp.element': typeof wp !== 'undefined' && typeof wp.element !== 'undefined',
                'wp.components': typeof wp !== 'undefined' && typeof wp.components !== 'undefined',
                'wp.i18n': typeof wp !== 'undefined' && typeof wp.i18n !== 'undefined',
                'sdImportExport': typeof sdImportExport !== 'undefined'
            };
            console.log('Starter Shelter Import/Export - Dependency Check:', checks);
            
            // If sdImportExport is not defined after 2 seconds, show error
            setTimeout(function() {
                var container = document.getElementById('sd-import-export-root');
                if (container && container.innerHTML.indexOf('Loading') !== -1) {
                    var missing = [];
                    for (var key in checks) {
                        if (!checks[key]) missing.push(key);
                    }
                    if (missing.length > 0) {
                        container.innerHTML = '<div class="notice notice-error"><p><strong>Import/Export UI failed to load.</strong></p><p>Missing dependencies: ' + missing.join(', ') + '</p><p>This may be caused by a JavaScript error or the script not being enqueued properly.</p></div>';
                    }
                }
            }, 2000);
        })();
        </script>
        <?php
    }

    /**
     * Get styles for the React UI.
     */
    private static function get_react_ui_styles(): string {
        return '
            .sd-import-export-app {
                max-width: 1200px;
            }
            .sd-import-export-tabs .components-tab-panel__tabs {
                margin-bottom: 0;
                border-bottom: 1px solid #c3c4c7;
            }
            .sd-import-export-tabs .components-tab-panel__tabs button {
                font-size: 14px;
                padding: 12px 16px;
            }
            .sd-import-export-tabs .components-tab-panel__tabs button.is-active {
                box-shadow: inset 0 -3px 0 0 #007cba;
            }
            
            .sd-export-card,
            .sd-import-card {
                height: 100%;
            }
            .sd-export-card .components-card__header,
            .sd-import-card .components-card__header {
                border-bottom: 1px solid #e0e0e0;
            }
            .sd-export-card .components-card__footer,
            .sd-import-card .components-card__footer {
                border-top: 1px solid #e0e0e0;
                background: #f6f7f7;
            }
            
            .sd-export-card h3,
            .sd-import-card h3 {
                font-size: 16px;
            }
            
            /* Fix button icons */
            .sd-export-card .components-button svg,
            .sd-import-card .components-button svg {
                margin-right: 6px;
            }
            
            /* Disabled state */
            .sd-export-card .components-button:disabled {
                opacity: 0.6;
            }
        ';
    }

    /**
     * AJAX handler for processing imports (used by React UI).
     */
    public static function ajax_process_import_donors(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $update_existing = ! empty( $_POST['update_existing'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_donor_import( $file['tmp_name'], $update_existing, $skip_errors );

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler for processing donation imports (used by React UI).
     */
    public static function ajax_process_import_donations(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $create_donors = ! empty( $_POST['create_donors'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_donation_import( $file['tmp_name'], $create_donors, $skip_errors );

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler for processing memorial imports (used by React UI).
     */
    public static function ajax_process_import_memorials(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $create_donors = ! empty( $_POST['create_donors'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_memorial_import( $file['tmp_name'], $create_donors, $skip_errors );

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler for processing membership imports (used by React UI).
     */
    public static function ajax_process_import_memberships(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'] ?? null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
        }

        $create_donors = ! empty( $_POST['create_donors'] );
        $skip_errors = ! empty( $_POST['skip_errors'] );

        $results = self::process_membership_import( $file['tmp_name'], $create_donors, $skip_errors );

        wp_send_json_success( $results );
    }

    /**
     * Process memorial import.
     */
    private static function process_memorial_import( string $filepath, bool $create_donors, bool $skip_errors ): array {
        $handle = fopen( $filepath, 'r' );
        $headers = array_map( 'strtolower', array_map( 'trim', fgetcsv( $handle ) ) );

        $results = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 ];

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row = array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
            $errors = self::validate_import_row( $row, 'memorials' );

            if ( ! empty( $errors ) ) {
                if ( $skip_errors ) {
                    $results['skipped']++;
                    continue;
                } else {
                    $results['errors']++;
                    continue;
                }
            }

            // Find or create donor.
            $existing_donor = get_posts( [
                'post_type'   => 'sd_donor',
                'meta_key'    => '_sd_email',
                'meta_value'  => sanitize_email( $row['email'] ),
                'numberposts' => 1,
            ] );

            if ( ! empty( $existing_donor ) ) {
                $donor_id = $existing_donor[0]->ID;
            } elseif ( $create_donors ) {
                $donor_id = self::create_donor_from_row( $row );
            } else {
                $results['skipped']++;
                continue;
            }

            // Create memorial.
            self::create_memorial_from_row( $row, $donor_id );
            $results['created']++;
        }

        fclose( $handle );
        return $results;
    }

    /**
     * Create memorial from import row.
     */
    private static function create_memorial_from_row( array $row, int $donor_id ): int {
        $memorial_date = wp_date( 'Y-m-d H:i:s', strtotime( $row['date'] ) );
        
        $memorial_id = wp_insert_post( [
            'post_type'   => 'sd_memorial',
            'post_status' => 'publish',
            'post_title'  => sprintf( 'Memorial - %s', sanitize_text_field( $row['honoree_name'] ) ),
            'post_date'   => $memorial_date,
        ] );

        // Core fields.
        update_post_meta( $memorial_id, '_sd_donor_id', $donor_id );
        update_post_meta( $memorial_id, '_sd_honoree_name', sanitize_text_field( $row['honoree_name'] ) );
        update_post_meta( $memorial_id, '_sd_amount', floatval( $row['amount'] ) );
        update_post_meta( $memorial_id, '_sd_donation_date', $memorial_date );
        
        // Memorial type (default to 'person').
        $memorial_type = ! empty( $row['memorial_type'] ) ? sanitize_key( $row['memorial_type'] ) : 'person';
        update_post_meta( $memorial_id, '_sd_memorial_type', $memorial_type );
        
        // Optional fields.
        if ( ! empty( $row['tribute_message'] ) ) {
            update_post_meta( $memorial_id, '_sd_tribute_message', sanitize_textarea_field( $row['tribute_message'] ) );
        }
        
        if ( ! empty( $row['pet_species'] ) ) {
            update_post_meta( $memorial_id, '_sd_pet_species', sanitize_text_field( $row['pet_species'] ) );
        }
        
        if ( isset( $row['is_anonymous'] ) ) {
            $is_anon = in_array( strtolower( $row['is_anonymous'] ), [ 'yes', '1', 'true' ], true );
            update_post_meta( $memorial_id, '_sd_is_anonymous', $is_anon ? 1 : 0 );
        }
        
        // Family notification settings.
        if ( ! empty( $row['notify_family_email'] ) && is_email( $row['notify_family_email'] ) ) {
            update_post_meta( $memorial_id, '_sd_notify_family', [
                'enabled' => true,
                'email'   => sanitize_email( $row['notify_family_email'] ),
            ] );
        }

        return $memorial_id;
    }

    /**
     * Process membership import.
     */
    private static function process_membership_import( string $filepath, bool $create_donors, bool $skip_errors ): array {
        $handle = fopen( $filepath, 'r' );
        $headers = array_map( 'strtolower', array_map( 'trim', fgetcsv( $handle ) ) );

        $results = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 ];

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row = array_combine( $headers, array_pad( $data, count( $headers ), '' ) );
            $errors = self::validate_import_row( $row, 'memberships' );

            if ( ! empty( $errors ) ) {
                if ( $skip_errors ) {
                    $results['skipped']++;
                    continue;
                } else {
                    $results['errors']++;
                    continue;
                }
            }

            // Find or create donor.
            $existing_donor = get_posts( [
                'post_type'   => 'sd_donor',
                'meta_key'    => '_sd_email',
                'meta_value'  => sanitize_email( $row['email'] ),
                'numberposts' => 1,
            ] );

            if ( ! empty( $existing_donor ) ) {
                $donor_id = $existing_donor[0]->ID;
            } elseif ( $create_donors ) {
                $donor_id = self::create_donor_from_row( $row );
            } else {
                $results['skipped']++;
                continue;
            }

            // Create membership.
            self::create_membership_from_row( $row, $donor_id );
            $results['created']++;
        }

        fclose( $handle );
        return $results;
    }

    /**
     * Create membership from import row.
     */
    private static function create_membership_from_row( array $row, int $donor_id ): int {
        $membership_type = sanitize_key( $row['membership_type'] );
        $tier = sanitize_text_field( $row['tier'] );
        
        // Build title based on type.
        if ( $membership_type === 'business' && ! empty( $row['business_name'] ) ) {
            $title = sprintf( '%s - %s', sanitize_text_field( $row['business_name'] ), ucfirst( $tier ) );
        } else {
            $donor = get_post( $donor_id );
            $donor_name = $donor ? $donor->post_title : 'Unknown';
            $title = sprintf( '%s - %s %s', $donor_name, ucfirst( $membership_type ), ucfirst( $tier ) );
        }
        
        $membership_id = wp_insert_post( [
            'post_type'   => 'sd_membership',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_date'   => wp_date( 'Y-m-d H:i:s', strtotime( $row['start_date'] ) ),
        ] );

        // Core fields.
        update_post_meta( $membership_id, '_sd_donor_id', $donor_id );
        update_post_meta( $membership_id, '_sd_membership_type', $membership_type );
        update_post_meta( $membership_id, '_sd_tier', $tier );
        update_post_meta( $membership_id, '_sd_amount', floatval( $row['amount'] ) );
        update_post_meta( $membership_id, '_sd_start_date', wp_date( 'Y-m-d', strtotime( $row['start_date'] ) ) );
        update_post_meta( $membership_id, '_sd_end_date', wp_date( 'Y-m-d', strtotime( $row['end_date'] ) ) );
        
        // Business-specific fields.
        if ( $membership_type === 'business' ) {
            if ( ! empty( $row['business_name'] ) ) {
                update_post_meta( $membership_id, '_sd_business_name', sanitize_text_field( $row['business_name'] ) );
            }
            if ( ! empty( $row['business_website'] ) ) {
                update_post_meta( $membership_id, '_sd_business_website', esc_url_raw( $row['business_website'] ) );
            }
            if ( ! empty( $row['business_description'] ) ) {
                update_post_meta( $membership_id, '_sd_business_description', sanitize_textarea_field( $row['business_description'] ) );
            }
            // Default logo status to 'none' for imports.
            update_post_meta( $membership_id, '_sd_logo_status', 'none' );
        }

        return $membership_id;
    }

    /**
     * AJAX handler: Preview legacy memorial CSV import.
     *
     * This handles the shelter's legacy format:
     * - Column A: "In Memory Of" (honoree name)
     * - Column B: "By" (donor name)
     * - Column C: Optional "pet" indicator
     * - Month names as section headers
     *
     * @since 1.0.0
     */
    public static function ajax_preview_import_memorials_legacy(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( __( 'No file uploaded.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'];
        
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload error.', 'starter-shelter' ) );
        }

        $year = absint( $_POST['year'] ?? date( 'Y' ) );
        $parsed = self::parse_legacy_memorial_csv( $file['tmp_name'], $year );

        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( $parsed->get_error_message() );
        }

        wp_send_json_success( [
            'total_rows'   => count( $parsed['rows'] ),
            'preview_rows' => array_slice( $parsed['rows'], 0, 20 ),
            'months_found' => $parsed['months_found'],
            'pet_count'    => $parsed['pet_count'],
            'person_count' => $parsed['person_count'],
            'year'         => $year,
        ] );
    }

    /**
     * AJAX handler: Process legacy memorial CSV import.
     *
     * @since 1.0.0
     */
    public static function ajax_process_import_memorials_legacy(): void {
        check_ajax_referer( 'sd_process_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( __( 'No file uploaded.', 'starter-shelter' ) );
        }

        $file = $_FILES['file'];
        
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'File upload error.', 'starter-shelter' ) );
        }

        $year = absint( $_POST['year'] ?? date( 'Y' ) );
        $skip_duplicates = ! empty( $_POST['skip_duplicates'] );
        $default_amount = floatval( $_POST['default_amount'] ?? 0 );

        $results = self::process_legacy_memorial_import( 
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
     * Parse the legacy memorial CSV format.
     *
     * @since 1.0.0
     *
     * @param string $filepath Path to CSV file.
     * @param int    $year     The year for dating the memorials.
     * @return array|\WP_Error Parsed data or error.
     */
    private static function parse_legacy_memorial_csv( string $filepath, int $year ): array|\WP_Error {
        $handle = fopen( $filepath, 'r' );
        
        if ( ! $handle ) {
            return new \WP_Error( 'file_error', __( 'Could not open file.', 'starter-shelter' ) );
        }

        $rows = [];
        $months_found = [];
        $current_month = null;
        $pet_count = 0;
        $person_count = 0;
        $line_number = 0;

        // Month name to number mapping.
        $month_map = [
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

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $line_number++;

            // Skip completely empty rows.
            $non_empty = array_filter( $data, fn( $cell ) => trim( $cell ) !== '' );
            if ( empty( $non_empty ) ) {
                continue;
            }

            // Get first two columns.
            $col_a = isset( $data[0] ) ? trim( $data[0] ) : '';
            $col_b = isset( $data[1] ) ? trim( $data[1] ) : '';
            $col_c = isset( $data[2] ) ? strtolower( trim( $data[2] ) ) : '';

            // Skip header row.
            if ( $line_number === 1 && stripos( $col_a, 'memory' ) !== false ) {
                continue;
            }

            // Check if this is a month header.
            $col_a_lower = strtolower( $col_a );
            if ( isset( $month_map[ $col_a_lower ] ) && empty( $col_b ) ) {
                $current_month = $month_map[ $col_a_lower ];
                $months_found[] = $col_a;
                continue;
            }

            // Skip rows without both honoree and donor.
            if ( empty( $col_a ) || empty( $col_b ) ) {
                continue;
            }

            // Clean up honoree name for pet names.
            // CSV escapes quotes by doubling them, e.g. """Hector"" Mewes" becomes "Hector" Mewes
            // We want to preserve the quoted pet name like "Hector" Mewes
            $honoree_name = $col_a;
            
            // Handle CSV's triple-quote escaping: """Name""" -> "Name"
            // When CSV has a field like """Hector"" Mewes", it reads as ""Hector" Mewes"
            if ( preg_match( '/^""(.+?)""(.*)$/', $honoree_name, $matches ) ) {
                // Matched pattern like ""Hector"" Mewes -> "Hector" Mewes
                $honoree_name = '"' . $matches[1] . '"' . $matches[2];
            } elseif ( str_starts_with( $honoree_name, '""' ) ) {
                // Handle cases like ""Name"" at start
                $honoree_name = preg_replace( '/^""/', '"', $honoree_name );
                $honoree_name = preg_replace( '/""$/', '"', $honoree_name );
            }
            
            // Clean up any remaining double-double quotes
            $honoree_name = str_replace( '""', '"', $honoree_name );
            $honoree_name = trim( $honoree_name );

            // Determine memorial type.
            $is_pet = ( $col_c === 'pet' );
            if ( $is_pet ) {
                $pet_count++;
            } else {
                $person_count++;
            }

            // Build date from month and year (use day 1).
            $month = $current_month ?? 1;
            $date = sprintf( '%04d-%02d-01', $year, $month );

            // Check if donor name indicates anonymous.
            $donor_name = $col_b;
            $is_anonymous = ( strtolower( $donor_name ) === 'anonymous' );

            $rows[] = [
                'line_number'   => $line_number,
                'honoree_name'  => $honoree_name,
                'donor_name'    => $donor_name,
                'memorial_type' => $is_pet ? 'pet' : 'person',
                'month'         => $current_month ? date( 'F', mktime( 0, 0, 0, $current_month, 1 ) ) : 'Unknown',
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
     * Process legacy memorial import.
     *
     * @since 1.0.0
     *
     * @param string $filepath        Path to CSV file.
     * @param int    $year            The year for dating the memorials.
     * @param bool   $skip_duplicates Whether to skip duplicate honoree/donor combinations.
     * @param float  $default_amount  Default donation amount.
     * @return array|\WP_Error Import results or error.
     */
    private static function process_legacy_memorial_import( 
        string $filepath, 
        int $year, 
        bool $skip_duplicates,
        float $default_amount = 0
    ): array|\WP_Error {
        $parsed = self::parse_legacy_memorial_csv( $filepath, $year );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $results = [
            'created'  => 0,
            'skipped'  => 0,
            'errors'   => 0,
            'donors_created' => 0,
            'details'  => [],
        ];

        foreach ( $parsed['rows'] as $row ) {
            // Find or create donor.
            $donor_id = self::find_or_create_legacy_donor( $row['donor_name'], $row['is_anonymous'] );
            
            if ( is_wp_error( $donor_id ) ) {
                $results['errors']++;
                $results['details'][] = [
                    'line'    => $row['line_number'],
                    'status'  => 'error',
                    'message' => $donor_id->get_error_message(),
                ];
                continue;
            }

            if ( $donor_id['created'] ) {
                $results['donors_created']++;
            }

            // Check for duplicates.
            if ( $skip_duplicates ) {
                $existing = self::find_existing_memorial( $row['honoree_name'], $donor_id['id'] );
                if ( $existing ) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'line'    => $row['line_number'],
                        'status'  => 'skipped',
                        'message' => sprintf( 
                            __( 'Memorial for "%s" by this donor already exists.', 'starter-shelter' ),
                            $row['honoree_name']
                        ),
                    ];
                    continue;
                }
            }

            // Create memorial.
            $memorial_id = self::create_legacy_memorial( $row, $donor_id['id'], $default_amount );

            if ( is_wp_error( $memorial_id ) ) {
                $results['errors']++;
                $results['details'][] = [
                    'line'    => $row['line_number'],
                    'status'  => 'error',
                    'message' => $memorial_id->get_error_message(),
                ];
            } else {
                $results['created']++;
                $results['details'][] = [
                    'line'        => $row['line_number'],
                    'status'      => 'created',
                    'memorial_id' => $memorial_id,
                    'honoree'     => $row['honoree_name'],
                    'donor'       => $row['donor_name'],
                ];
            }
        }

        return $results;
    }

    /**
     * Find or create a donor from legacy import data (name only, no email).
     *
     * @since 1.0.0
     *
     * @param string $donor_name   The donor's name.
     * @param bool   $is_anonymous Whether the donor is anonymous.
     * @return array|\WP_Error Array with 'id' and 'created' keys, or error.
     */
    private static function find_or_create_legacy_donor( string $donor_name, bool $is_anonymous ): array|\WP_Error {
        // For anonymous donors, use a single shared "Anonymous" donor record.
        if ( $is_anonymous ) {
            $existing = get_posts( [
                'post_type'      => 'sd_donor',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'title'          => 'Anonymous',
                'fields'         => 'ids',
            ] );

            if ( ! empty( $existing ) ) {
                return [ 'id' => $existing[0], 'created' => false ];
            }

            // Create anonymous donor.
            $donor_id = wp_insert_post( [
                'post_type'   => 'sd_donor',
                'post_status' => 'publish',
                'post_title'  => 'Anonymous',
                'meta_input'  => [
                    '_sd_email'           => 'anonymous@example.com',
                    '_sd_display_name'    => 'Anonymous',
                    '_sd_is_anonymous'    => true,
                    '_sd_import_source'   => 'legacy_memorial_csv',
                ],
            ], true );

            if ( is_wp_error( $donor_id ) ) {
                return $donor_id;
            }

            return [ 'id' => $donor_id, 'created' => true ];
        }

        // Try to find existing donor by exact name match.
        $existing = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'title'          => $donor_name,
            'fields'         => 'ids',
        ] );

        if ( ! empty( $existing ) ) {
            $donor_id = $existing[0];
            
            // Ensure display_name is set (might be missing on older records).
            $current_display_name = get_post_meta( $donor_id, '_sd_display_name', true );
            if ( empty( $current_display_name ) ) {
                update_post_meta( $donor_id, '_sd_display_name', sanitize_text_field( $donor_name ) );
            }
            
            return [ 'id' => $donor_id, 'created' => false ];
        }

        // Create new donor (without email since legacy data doesn't have it).
        $donor_id = wp_insert_post( [
            'post_type'   => 'sd_donor',
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field( $donor_name ),
            'meta_input'  => [
                '_sd_email'           => '', // No email in legacy data.
                '_sd_display_name'    => sanitize_text_field( $donor_name ),
                '_sd_import_source'   => 'legacy_memorial_csv',
            ],
        ], true );

        if ( is_wp_error( $donor_id ) ) {
            return $donor_id;
        }

        return [ 'id' => $donor_id, 'created' => true ];
    }

    /**
     * Find an existing memorial by honoree name and donor.
     *
     * @since 1.0.0
     *
     * @param string $honoree_name The honoree's name.
     * @param int    $donor_id     The donor's ID.
     * @return int|null Memorial ID if found, null otherwise.
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
            'fields'         => 'ids',
        ] );

        return ! empty( $existing ) ? $existing[0] : null;
    }

    /**
     * Create a memorial from legacy import data.
     *
     * @since 1.0.0
     *
     * @param array $row            The parsed row data.
     * @param int   $donor_id       The donor's ID.
     * @param float $default_amount Default donation amount.
     * @return int|\WP_Error Memorial ID or error.
     */
    private static function create_legacy_memorial( array $row, int $donor_id, float $default_amount = 0 ): int|\WP_Error {
        $memorial_date = $row['date'] . ' 12:00:00';
        
        // Preserve quotes in pet names like "Hector" Mewes.
        // sanitize_text_field strips quotes, so use a lighter sanitization.
        $honoree_name = wp_strip_all_tags( $row['honoree_name'] );
        $honoree_name = trim( $honoree_name );

        $memorial_id = wp_insert_post( [
            'post_type'   => 'sd_memorial',
            'post_status' => 'publish',
            'post_title'  => $honoree_name,
            'post_date'   => $memorial_date,
            'meta_input'  => [
                '_sd_donor_id'        => $donor_id,
                '_sd_honoree_name'    => $honoree_name,
                '_sd_memorial_type'   => $row['memorial_type'],
                '_sd_amount'          => $default_amount,
                '_sd_donation_date'   => $memorial_date,
                '_sd_date'            => $memorial_date,
                '_sd_is_anonymous'    => $row['is_anonymous'] ? 1 : 0,
                '_sd_import_source'   => 'legacy_memorial_csv',
                '_sd_import_line'     => $row['line_number'],
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
}
