<?php
/**
 * Admin Reports - Reports dashboard page.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;

/**
 * Handles the admin reports page with donation and membership statistics.
 *
 * @since 1.0.0
 */
class Reports {

    /**
     * Page slug.
     *
     * @since 1.0.0
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-reports';

    /**
     * Initialize reports page.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_reports_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_sd_export_report', [ self::class, 'handle_export' ] );
    }

    /**
     * Add reports page to admin menu.
     *
     * @since 1.0.0
     */
    public static function add_reports_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Shelter Donations Reports', 'starter-shelter' ),
            __( 'Reports', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_reports_page' ]
        );
    }

    /**
     * Enqueue admin assets for reports page.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_assets( string $hook ): void {
        // The hook for submenu pages under a custom menu is: {parent_slug}_page_{page_slug}
        // For our case: starter-shelter_page_starter-shelter-reports
        if ( Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'sd-reports',
            STARTER_SHELTER_URL . 'assets/css/admin-reports.css',
            [],
            STARTER_SHELTER_VERSION
        );

        wp_enqueue_script(
            'sd-reports',
            STARTER_SHELTER_URL . 'assets/js/admin-reports.js',
            [ 'jquery', 'wp-api-fetch' ],
            STARTER_SHELTER_VERSION,
            true
        );

        wp_localize_script( 'sd-reports', 'sdReports', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sd_reports_nonce' ),
        ] );
    }

    /**
     * Render the reports page.
     *
     * @since 1.0.0
     */
    public static function render_reports_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = sanitize_key( $_GET['tab'] ?? 'donations' );
        $period     = sanitize_key( $_GET['period'] ?? 'month' );

        ?>
        <div class="wrap sd-reports">
            <h1><?php esc_html_e( 'Shelter Donations Reports', 'starter-shelter' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'donations' ) ); ?>" 
                   class="nav-tab <?php echo 'donations' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Donations', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'memberships' ) ); ?>" 
                   class="nav-tab <?php echo 'memberships' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Memberships', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'memorials' ) ); ?>" 
                   class="nav-tab <?php echo 'memorials' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Memorials', 'starter-shelter' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'campaigns' ) ); ?>" 
                   class="nav-tab <?php echo 'campaigns' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Campaigns', 'starter-shelter' ); ?>
                </a>
            </nav>

            <div class="sd-reports-filters">
                <form method="get">
                    <input type="hidden" name="post_type" value="sd_donation" />
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>" />
                    
                    <select name="period" id="sd-period-filter">
                        <option value="today" <?php selected( $period, 'today' ); ?>>
                            <?php esc_html_e( 'Today', 'starter-shelter' ); ?>
                        </option>
                        <option value="week" <?php selected( $period, 'week' ); ?>>
                            <?php esc_html_e( 'This Week', 'starter-shelter' ); ?>
                        </option>
                        <option value="month" <?php selected( $period, 'month' ); ?>>
                            <?php esc_html_e( 'This Month', 'starter-shelter' ); ?>
                        </option>
                        <option value="quarter" <?php selected( $period, 'quarter' ); ?>>
                            <?php esc_html_e( 'This Quarter', 'starter-shelter' ); ?>
                        </option>
                        <option value="year" <?php selected( $period, 'year' ); ?>>
                            <?php esc_html_e( 'This Year', 'starter-shelter' ); ?>
                        </option>
                        <option value="fiscal_year" <?php selected( $period, 'fiscal_year' ); ?>>
                            <?php esc_html_e( 'Fiscal Year', 'starter-shelter' ); ?>
                        </option>
                        <option value="all_time" <?php selected( $period, 'all_time' ); ?>>
                            <?php esc_html_e( 'All Time', 'starter-shelter' ); ?>
                        </option>
                    </select>
                    
                    <button type="submit" class="button">
                        <?php esc_html_e( 'Filter', 'starter-shelter' ); ?>
                    </button>
                    
                    <button type="button" class="button sd-export-btn" data-tab="<?php echo esc_attr( $active_tab ); ?>">
                        <?php esc_html_e( 'Export CSV', 'starter-shelter' ); ?>
                    </button>
                </form>
            </div>

            <div class="sd-reports-content">
                <?php
                switch ( $active_tab ) {
                    case 'memberships':
                        self::render_memberships_report( $period );
                        break;
                    case 'memorials':
                        self::render_memorials_report( $period );
                        break;
                    case 'campaigns':
                        self::render_campaigns_report( $period );
                        break;
                    default:
                        self::render_donations_report( $period );
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render donations report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_donations_report( string $period ): void {
        // Use the dashboard-stats ability.
        $ability = wp_get_ability( 'shelter-donations/get-stats' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Unable to load donation statistics.', 'starter-shelter' ) . '</p>';
            return;
        }

        $stats = $ability->execute( [ 'period' => $period ] );

        if ( is_wp_error( $stats ) ) {
            echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
            return;
        }

        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( $stats['total_formatted'] ?? '$0.00' ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Total Donations', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $stats['donation_count'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Number of Donations', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $stats['donor_count'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Unique Donors', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $stats['average_amount'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Average Donation', 'starter-shelter' ); ?></span>
            </div>
        </div>

        <?php if ( ! empty( $stats['by_allocation'] ) ) : ?>
        <h3><?php esc_html_e( 'By Allocation', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Count', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $stats['by_allocation'] as $allocation => $data ) : ?>
                <tr>
                    <td><?php echo esc_html( \Starter_Shelter\Helpers\get_allocation_label( $allocation ) ); ?></td>
                    <td><?php echo esc_html( number_format( $data['count'] ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $data['total'], 2 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Render memberships report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memberships_report( string $period ): void {
        // Use the dashboard-stats ability.
        $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Unable to load membership statistics.', 'starter-shelter' ) . '</p>';
            return;
        }

        $stats = $ability->execute( [ 'period' => $period ] );

        if ( is_wp_error( $stats ) ) {
            echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
            return;
        }

        $membership_stats = $stats['memberships'] ?? [];

        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['active'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Active Memberships', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['new'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'New This Period', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card sd-stat-warning">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $membership_stats['expiring_soon'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Expiring Soon', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $membership_stats['revenue'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Membership Revenue', 'starter-shelter' ); ?></span>
            </div>
        </div>

        <?php if ( ! empty( $membership_stats['by_tier'] ) ) : ?>
        <h3><?php esc_html_e( 'By Tier', 'starter-shelter' ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Tier', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Active', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Revenue', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $membership_stats['by_tier'] as $tier => $data ) : ?>
                <tr>
                    <td><?php echo esc_html( \Starter_Shelter\Helpers\get_tier_label( $tier ) ); ?></td>
                    <td><?php echo esc_html( number_format( $data['count'] ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $data['revenue'], 2 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Render memorials report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_memorials_report( string $period ): void {
        $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Unable to load memorial statistics.', 'starter-shelter' ) . '</p>';
            return;
        }

        $stats = $ability->execute( [ 'period' => $period ] );

        if ( is_wp_error( $stats ) ) {
            echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
            return;
        }

        $memorial_stats = $stats['memorials'] ?? [];

        ?>
        <div class="sd-stats-cards">
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $memorial_stats['total'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Total Memorials', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( number_format( $memorial_stats['new'] ?? 0 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'New This Period', 'starter-shelter' ); ?></span>
            </div>
            <div class="sd-stat-card">
                <span class="sd-stat-value"><?php echo esc_html( '$' . number_format( $memorial_stats['revenue'] ?? 0, 2 ) ); ?></span>
                <span class="sd-stat-label"><?php esc_html_e( 'Memorial Donations', 'starter-shelter' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render campaigns report tab.
     *
     * @since 1.0.0
     *
     * @param string $period The reporting period.
     */
    private static function render_campaigns_report( string $period ): void {
        // Get active campaigns.
        $campaigns = get_terms( [
            'taxonomy'   => 'sd_campaign',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $campaigns ) || empty( $campaigns ) ) {
            echo '<p>' . esc_html__( 'No campaigns found.', 'starter-shelter' ) . '</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Campaign', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Raised', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Progress', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'starter-shelter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $campaigns as $campaign ) : 
                    $goal   = (float) get_term_meta( $campaign->term_id, '_sd_goal', true );
                    $raised = (float) get_term_meta( $campaign->term_id, '_sd_raised', true );
                    $percent = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $campaign->name ); ?></strong></td>
                    <td><?php echo esc_html( '$' . number_format( $goal, 2 ) ); ?></td>
                    <td><?php echo esc_html( '$' . number_format( $raised, 2 ) ); ?></td>
                    <td>
                        <div class="sd-progress-bar">
                            <div class="sd-progress-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
                        </div>
                        <span><?php echo esc_html( number_format( $percent, 1 ) ); ?>%</span>
                    </td>
                    <td><?php echo esc_html( $campaign->count ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [
                            'action'      => 'sd_export_report',
                            'report'      => 'campaign',
                            'campaign_id' => $campaign->term_id,
                            '_wpnonce'    => wp_create_nonce( 'sd_export_report' ),
                        ], admin_url( 'admin-ajax.php' ) ) ); ?>" class="button button-small">
                            <?php esc_html_e( 'Export', 'starter-shelter' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Handle CSV export via AJAX.
     *
     * @since 1.0.0
     */
    public static function handle_export(): void {
        check_ajax_referer( 'sd_export_report', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $report = sanitize_key( $_GET['report'] ?? 'donations' );
        $period = sanitize_key( $_GET['period'] ?? 'month' );

        $filename = 'shelter-' . $report . '-' . $period . '-' . wp_date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        switch ( $report ) {
            case 'campaign':
                self::export_campaign_report( $output );
                break;
            case 'memberships':
                self::export_memberships_report( $output, $period );
                break;
            default:
                self::export_donations_report( $output, $period );
                break;
        }

        fclose( $output );
        exit;
    }

    /**
     * Export donations report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_donations_report( $output, string $period ): void {
        // CSV headers.
        fputcsv( $output, [
            __( 'Date', 'starter-shelter' ),
            __( 'Donor', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
            __( 'Allocation', 'starter-shelter' ),
            __( 'Campaign', 'starter-shelter' ),
            __( 'Anonymous', 'starter-shelter' ),
        ] );

        // Use the list ability.
        $ability = wp_get_ability( 'shelter-donations/list' );
        if ( ! $ability ) {
            return;
        }

        $date_range = \Starter_Shelter\Helpers\get_date_range_for_period( $period );
        
        $result = $ability->execute( [
            'date_from' => $date_range['start'],
            'date_to'   => $date_range['end'],
            'per_page'  => 1000,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        foreach ( $result['items'] ?? [] as $donation ) {
            fputcsv( $output, [
                $donation['date_formatted'] ?? '',
                $donation['donor']['full_name'] ?? '',
                $donation['donor']['email'] ?? '',
                $donation['amount'] ?? 0,
                $donation['allocation_label'] ?? '',
                $donation['campaign_name'] ?? '',
                $donation['is_anonymous'] ? __( 'Yes', 'starter-shelter' ) : __( 'No', 'starter-shelter' ),
            ] );
        }
    }

    /**
     * Export memberships report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     * @param string   $period Report period.
     */
    private static function export_memberships_report( $output, string $period ): void {
        fputcsv( $output, [
            __( 'Member', 'starter-shelter' ),
            __( 'Email', 'starter-shelter' ),
            __( 'Tier', 'starter-shelter' ),
            __( 'Type', 'starter-shelter' ),
            __( 'Start Date', 'starter-shelter' ),
            __( 'End Date', 'starter-shelter' ),
            __( 'Status', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
        ] );

        $ability = wp_get_ability( 'shelter-memberships/list' );
        if ( ! $ability ) {
            return;
        }

        $result = $ability->execute( [
            'status'   => 'all',
            'per_page' => 1000,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        foreach ( $result['items'] ?? [] as $membership ) {
            fputcsv( $output, [
                $membership['donor']['full_name'] ?? '',
                $membership['donor']['email'] ?? '',
                $membership['tier_label'] ?? '',
                $membership['membership_type'] ?? '',
                $membership['start_date'] ?? '',
                $membership['end_date'] ?? '',
                $membership['is_active'] ? __( 'Active', 'starter-shelter' ) : __( 'Expired', 'starter-shelter' ),
                $membership['amount'] ?? 0,
            ] );
        }
    }

    /**
     * Export campaign report to CSV.
     *
     * @since 1.0.0
     *
     * @param resource $output File handle.
     */
    private static function export_campaign_report( $output ): void {
        $campaign_id = absint( $_GET['campaign_id'] ?? 0 );
        
        if ( ! $campaign_id ) {
            return;
        }

        $ability = wp_get_ability( 'shelter-reports/campaign-report' );
        if ( ! $ability ) {
            return;
        }

        $result = $ability->execute( [
            'campaign_id'       => $campaign_id,
            'include_donations' => true,
        ] );

        if ( is_wp_error( $result ) ) {
            return;
        }

        // Campaign summary.
        fputcsv( $output, [ __( 'Campaign Report', 'starter-shelter' ), $result['campaign']['name'] ?? '' ] );
        fputcsv( $output, [ __( 'Goal', 'starter-shelter' ), $result['campaign']['goal'] ?? 0 ] );
        fputcsv( $output, [ __( 'Raised', 'starter-shelter' ), $result['progress']['total_raised'] ?? 0 ] );
        fputcsv( $output, [ __( 'Progress', 'starter-shelter' ), ( $result['progress']['percent_of_goal'] ?? 0 ) . '%' ] );
        fputcsv( $output, [] );

        // Donations.
        fputcsv( $output, [
            __( 'Date', 'starter-shelter' ),
            __( 'Donor', 'starter-shelter' ),
            __( 'Amount', 'starter-shelter' ),
        ] );

        foreach ( $result['donations'] ?? [] as $donation ) {
            fputcsv( $output, [
                $donation['date_formatted'] ?? '',
                $donation['donor_name'] ?? '',
                $donation['amount'] ?? 0,
            ] );
        }
    }
}
