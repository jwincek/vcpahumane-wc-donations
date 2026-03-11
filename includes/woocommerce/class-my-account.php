<?php
/**
 * My Account Integration - Custom endpoints for donor dashboard.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Manages WooCommerce My Account integration for donors.
 *
 * @since 1.0.0
 */
class My_Account {

    /**
     * Custom endpoint slugs.
     *
     * @since 1.0.0
     * @var array
     */
    private const ENDPOINTS = [
        'donor-dashboard'  => 'donor-dashboard',
        'giving-history'   => 'giving-history',
        'my-memberships'   => 'my-memberships',
        'my-memorials'     => 'my-memorials',
        'annual-statement' => 'annual-statement',
    ];

    /**
     * Initialize My Account integration.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'init', [ self::class, 'register_endpoints' ] );
        add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_items' ] );

        foreach ( self::ENDPOINTS as $endpoint => $slug ) {
            add_action( "woocommerce_account_{$slug}_endpoint", [ self::class, 'render_' . str_replace( '-', '_', $endpoint ) ] );
        }

        add_filter( 'the_title', [ self::class, 'endpoint_titles' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
    }

    /**
     * Register custom endpoints.
     *
     * @since 1.0.0
     */
    public static function register_endpoints(): void {
        foreach ( self::ENDPOINTS as $slug ) {
            add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES );
        }
    }

    /**
     * Add custom query vars.
     *
     * @since 1.0.0
     *
     * @param array $vars Query vars.
     * @return array Modified query vars.
     */
    public static function add_query_vars( array $vars ): array {
        foreach ( self::ENDPOINTS as $slug ) {
            $vars[] = $slug;
        }
        return $vars;
    }

    /**
     * Add menu items to My Account navigation.
     *
     * @since 1.0.0
     *
     * @param array $items Menu items.
     * @return array Modified menu items.
     */
    public static function add_menu_items( array $items ): array {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            return $items;
        }

        $new_items = [];
        
        foreach ( $items as $key => $label ) {
            if ( 'customer-logout' === $key ) {
                $new_items['donor-dashboard'] = __( 'Donor Dashboard', 'starter-shelter' );
                $new_items['giving-history'] = __( 'Giving History', 'starter-shelter' );
                $new_items['my-memberships'] = __( 'My Memberships', 'starter-shelter' );
                $new_items['my-memorials'] = __( 'My Memorials', 'starter-shelter' );
                $new_items['annual-statement'] = __( 'Annual Statement', 'starter-shelter' );
            }
            $new_items[ $key ] = $label;
        }

        return $new_items;
    }

    /**
     * Set endpoint titles.
     *
     * @since 1.0.0
     *
     * @param string $title The page title.
     * @param int    $id    The post ID.
     * @return string Modified title.
     */
    public static function endpoint_titles( string $title, int $id ): string {
        global $wp_query;

        if ( ! is_main_query() || ! in_the_loop() || ! is_account_page() ) {
            return $title;
        }

        $endpoint_titles = [
            'donor-dashboard'  => __( 'Donor Dashboard', 'starter-shelter' ),
            'giving-history'   => __( 'Giving History', 'starter-shelter' ),
            'my-memberships'   => __( 'My Memberships', 'starter-shelter' ),
            'my-memorials'     => __( 'My Memorials', 'starter-shelter' ),
            'annual-statement' => __( 'Annual Statement', 'starter-shelter' ),
        ];

        foreach ( $endpoint_titles as $endpoint => $endpoint_title ) {
            if ( isset( $wp_query->query_vars[ $endpoint ] ) ) {
                return $endpoint_title;
            }
        }

        return $title;
    }

    /**
     * Get current user's donor ID.
     *
     * @since 1.0.0
     *
     * @return int|null Donor ID or null.
     */
    public static function get_current_donor_id(): ?int {
        $user_id = get_current_user_id();
        
        if ( ! $user_id ) {
            return null;
        }

        $donors = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_user_id',
                    'value' => $user_id,
                ],
            ],
            'fields'         => 'ids',
        ] );

        if ( ! empty( $donors ) ) {
            return $donors[0];
        }

        $user = get_user_by( 'id', $user_id );
        
        if ( $user ) {
            $donors = get_posts( [
                'post_type'      => 'sd_donor',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_sd_email',
                        'value' => $user->user_email,
                    ],
                ],
                'fields'         => 'ids',
            ] );

            if ( ! empty( $donors ) ) {
                update_post_meta( $donors[0], '_sd_user_id', $user_id );
                return $donors[0];
            }
        }

        return null;
    }

    /**
     * Render donor dashboard endpoint.
     *
     * @since 1.0.0
     */
    public static function render_donor_dashboard(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );

        $recent_donations = Query::for( 'sd_donation' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( 1, 5 );

        $membership = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->whereCompare( 'end_date', wp_date( 'Y-m-d' ), '>=', 'DATE' )
            ->orderBy( 'end_date', 'DESC' )
            ->first();

        $stats = [
            'lifetime_giving' => $donor['lifetime_giving'] ?? 0,
            'donor_level'     => $donor['donor_level'] ?? 'new',
            'donation_count'  => Query::for( 'sd_donation' )->where( 'donor_id', $donor_id )->count(),
            'memorial_count'  => Query::for( 'sd_memorial' )->where( 'donor_id', $donor_id )->count(),
        ];

        self::render_template( 'donor-dashboard', compact( 'donor', 'recent_donations', 'membership', 'stats' ) );
    }

    /**
     * Render giving history endpoint.
     *
     * @since 1.0.0
     */
    public static function render_giving_history(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $paged = isset( $_GET['history-page'] ) ? absint( $_GET['history-page'] ) : 1;
        $year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : null;

        $donations = Query::for( 'sd_donation' )
            ->where( 'donor_id', $donor_id )
            ->whereYear( 'donation_date', $year )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( $paged, 10 );

        $years = self::get_donation_years( $donor_id );

        self::render_template( 'giving-history', [
            'donations'    => $donations,
            'years'        => $years,
            'current_year' => $year,
        ] );
    }

    /**
     * Render memberships endpoint.
     *
     * @since 1.0.0
     */
    public static function render_my_memberships(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $memberships = Query::for( 'sd_membership' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'end_date', 'DESC' )
            ->get();

        $active = array_filter( $memberships, fn( $m ) => $m['is_active'] ?? false );
        $expired = array_filter( $memberships, fn( $m ) => ! ( $m['is_active'] ?? false ) );

        self::render_template( 'my-memberships', [
            'active_memberships'  => $active,
            'expired_memberships' => $expired,
        ] );
    }

    /**
     * Render memorials endpoint.
     *
     * @since 1.0.0
     */
    public static function render_my_memorials(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $paged = isset( $_GET['memorial-page'] ) ? absint( $_GET['memorial-page'] ) : 1;

        $memorials = Query::for( 'sd_memorial' )
            ->where( 'donor_id', $donor_id )
            ->orderBy( 'donation_date', 'DESC' )
            ->paginate( $paged, 12 );

        self::render_template( 'my-memorials', [ 'memorials' => $memorials ] );
    }

    /**
     * Render annual statement endpoint.
     *
     * @since 1.0.0
     */
    public static function render_annual_statement(): void {
        $donor_id = self::get_current_donor_id();

        if ( ! $donor_id ) {
            self::render_no_donor_message();
            return;
        }

        $year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) wp_date( 'Y' ) - 1;

        $ability = wp_get_ability( 'shelter-reports/annual-summary' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Statement generation is temporarily unavailable.', 'starter-shelter' ) . '</p>';
            return;
        }

        $summary = $ability->execute( [ 'donor_id' => $donor_id, 'year' => $year ] );

        if ( is_wp_error( $summary ) ) {
            echo '<p>' . esc_html( $summary->get_error_message() ) . '</p>';
            return;
        }

        $years = self::get_donation_years( $donor_id );

        self::render_template( 'annual-statement', [
            'summary'   => $summary,
            'year'      => $year,
            'years'     => $years,
            'print_url' => add_query_arg( [ 'print' => '1', 'year' => $year ] ),
        ] );
    }

    /**
     * Get years that have donations for a donor.
     *
     * @since 1.0.0
     *
     * @param int $donor_id The donor ID.
     * @return array Array of years.
     */
    private static function get_donation_years( int $donor_id ): array {
        global $wpdb;

        $years = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT YEAR(pm.meta_value) as year
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donation_date'
            INNER JOIN {$wpdb->postmeta} pd ON p.ID = pd.post_id AND pd.meta_key = '_sd_donor_id' AND pd.meta_value = %d
            WHERE p.post_type IN ('sd_donation', 'sd_memorial', 'sd_membership')
            AND p.post_status = 'publish'
            ORDER BY year DESC",
            $donor_id
        ) );

        return array_map( 'intval', $years );
    }

    /**
     * Render a template.
     *
     * @since 1.0.0
     *
     * @param string $template The template name.
     * @param array  $args     Template arguments.
     */
    private static function render_template( string $template, array $args = [] ): void {
        $template_path = locate_template( "starter-shelter/myaccount/{$template}.php" );

        if ( ! $template_path ) {
            $template_path = STARTER_SHELTER_PATH . "templates/myaccount/{$template}.php";
        }

        if ( file_exists( $template_path ) ) {
            extract( $args ); // phpcs:ignore
            include $template_path;
        } else {
            self::render_inline_template( $template, $args );
        }
    }

    /**
     * Render inline template when file doesn't exist.
     *
     * @since 1.0.0
     *
     * @param string $template The template name.
     * @param array  $args     Template arguments.
     */
    private static function render_inline_template( string $template, array $args ): void {
        extract( $args ); // phpcs:ignore

        switch ( $template ) {
            case 'donor-dashboard':
                ?>
                <div class="sd-donor-dashboard">
                    <div class="sd-dashboard-header">
                        <h2><?php echo esc_html( sprintf( __( 'Welcome, %s!', 'starter-shelter' ), $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) ) ); ?></h2>
                        <p class="sd-donor-level"><?php echo esc_html( sprintf( __( 'Donor Level: %s', 'starter-shelter' ), Helpers\get_donor_level_label( $stats['donor_level'] ) ) ); ?></p>
                    </div>

                    <div class="sd-dashboard-stats">
                        <div class="sd-stat">
                            <span class="sd-stat-value"><?php echo esc_html( Helpers\format_currency( $stats['lifetime_giving'] ) ); ?></span>
                            <span class="sd-stat-label"><?php esc_html_e( 'Lifetime Giving', 'starter-shelter' ); ?></span>
                        </div>
                        <div class="sd-stat">
                            <span class="sd-stat-value"><?php echo esc_html( $stats['donation_count'] ); ?></span>
                            <span class="sd-stat-label"><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></span>
                        </div>
                        <div class="sd-stat">
                            <span class="sd-stat-value"><?php echo esc_html( $stats['memorial_count'] ); ?></span>
                            <span class="sd-stat-label"><?php esc_html_e( 'Memorials', 'starter-shelter' ); ?></span>
                        </div>
                    </div>

                    <?php if ( $membership ) : ?>
                    <div class="sd-membership-status">
                        <h3><?php esc_html_e( 'Membership Status', 'starter-shelter' ); ?></h3>
                        <p>
                            <strong><?php echo esc_html( $membership['tier_label'] ?? $membership['tier'] ); ?></strong><br>
                            <?php echo esc_html( sprintf( __( 'Expires: %s', 'starter-shelter' ), Helpers\format_date( $membership['end_date'] ) ) ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $recent_donations['items'] ) ) : ?>
                    <div class="sd-recent-donations">
                        <h3><?php esc_html_e( 'Recent Donations', 'starter-shelter' ); ?></h3>
                        <ul>
                            <?php foreach ( $recent_donations['items'] as $donation ) : ?>
                            <li><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ) . ' — ' . $donation['amount_formatted'] ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'giving-history' ) ); ?>"><?php esc_html_e( 'View All', 'starter-shelter' ); ?></a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'giving-history':
                ?>
                <div class="sd-giving-history">
                    <?php if ( ! empty( $years ) ) : ?>
                    <form method="get" class="sd-year-filter">
                        <label for="year"><?php esc_html_e( 'Filter by Year:', 'starter-shelter' ); ?></label>
                        <select name="year" id="year" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e( 'All Years', 'starter-shelter' ); ?></option>
                            <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $current_year, $y ); ?>><?php echo esc_html( $y ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>

                    <?php if ( empty( $donations['items'] ) ) : ?>
                    <p><?php esc_html_e( 'No donations found.', 'starter-shelter' ); ?></p>
                    <?php else : ?>
                    <table class="sd-donations-table woocommerce-orders-table">
                        <thead><tr><th><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th><th><?php esc_html_e( 'Amount', 'starter-shelter' ); ?></th><th><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $donations['items'] as $donation ) : ?>
                            <tr>
                                <td><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ) ); ?></td>
                                <td><?php echo esc_html( $donation['amount_formatted'] ); ?></td>
                                <td><?php echo esc_html( $donation['allocation_label'] ?? $donation['allocation'] ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'my-memberships':
                ?>
                <div class="sd-my-memberships">
                    <?php if ( ! empty( $active_memberships ) ) : ?>
                    <h3><?php esc_html_e( 'Active Memberships', 'starter-shelter' ); ?></h3>
                    <?php foreach ( $active_memberships as $m ) : ?>
                    <div class="sd-membership-card sd-active">
                        <h4><?php echo esc_html( $m['tier_label'] ?? $m['tier'] ); ?></h4>
                        <p><?php echo esc_html( sprintf( __( 'Valid until: %s', 'starter-shelter' ), Helpers\format_date( $m['end_date'] ) ) ); ?></p>
                    </div>
                    <?php endforeach; ?>
                    <?php else : ?>
                    <p><?php esc_html_e( 'You don\'t have any active memberships.', 'starter-shelter' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'my-memorials':
                ?>
                <div class="sd-my-memorials">
                    <?php if ( empty( $memorials['items'] ) ) : ?>
                    <p><?php esc_html_e( 'You haven\'t created any memorial tributes yet.', 'starter-shelter' ); ?></p>
                    <?php else : ?>
                    <div class="sd-memorials-grid">
                        <?php foreach ( $memorials['items'] as $memorial ) : ?>
                        <div class="sd-memorial-card">
                            <h4><?php echo esc_html( $memorial['honoree_name'] ); ?></h4>
                            <p><?php echo esc_html( Helpers\format_date( $memorial['donation_date'] ) ); ?></p>
                            <a href="<?php echo esc_url( get_permalink( $memorial['id'] ) ); ?>"><?php esc_html_e( 'View', 'starter-shelter' ); ?></a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'annual-statement':
                ?>
                <div class="sd-annual-statement">
                    <div class="sd-statement-header">
                        <form method="get" class="sd-year-selector">
                            <label for="statement-year"><?php esc_html_e( 'Select Year:', 'starter-shelter' ); ?></label>
                            <select name="year" id="statement-year" onchange="this.form.submit()">
                                <?php foreach ( $years as $y ) : ?>
                                <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <a href="<?php echo esc_url( $print_url ); ?>" class="button" target="_blank"><?php esc_html_e( 'Print', 'starter-shelter' ); ?></a>
                    </div>

                    <div class="sd-statement-content">
                        <h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
                        <h3><?php esc_html_e( 'Charitable Contribution Statement', 'starter-shelter' ); ?></h3>
                        <p><strong><?php echo esc_html( $summary['donor']['name'] ); ?></strong></p>
                        <p><?php echo esc_html( sprintf( __( 'Year: %d', 'starter-shelter' ), $year ) ); ?></p>
                        
                        <table class="sd-statement-summary">
                            <tr><td><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['donations']['formatted'] ); ?></td></tr>
                            <tr><td><?php esc_html_e( 'Memorials', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['memorials']['formatted'] ); ?></td></tr>
                            <tr><td><?php esc_html_e( 'Memberships', 'starter-shelter' ); ?></td><td><?php echo esc_html( $summary['memberships']['formatted'] ); ?></td></tr>
                            <tr class="sd-total"><td><strong><?php esc_html_e( 'Total', 'starter-shelter' ); ?></strong></td><td><strong><?php echo esc_html( $summary['grand_formatted'] ); ?></strong></td></tr>
                        </table>
                    </div>
                </div>
                <?php
                break;
        }
    }

    /**
     * Render message when user has no donor profile.
     *
     * @since 1.0.0
     */
    private static function render_no_donor_message(): void {
        ?>
        <div class="sd-no-donor">
            <p><?php esc_html_e( 'You don\'t have a donor profile yet. Make your first donation to get started!', 'starter-shelter' ); ?></p>
            <a href="<?php echo esc_url( home_url( '/donate/' ) ); ?>" class="button"><?php esc_html_e( 'Donate Now', 'starter-shelter' ); ?></a>
        </div>
        <?php
    }

    /**
     * Enqueue My Account styles.
     *
     * @since 1.0.0
     */
    public static function enqueue_styles(): void {
        if ( ! is_account_page() ) {
            return;
        }

        wp_add_inline_style( 'woocommerce-general', self::get_styles() );
    }

    /**
     * Get inline styles.
     *
     * @since 1.0.0
     *
     * @return string CSS code.
     */
    private static function get_styles(): string {
        return '
.sd-dashboard-stats { display: flex; gap: 20px; margin: 20px 0; }
.sd-stat { flex: 1; background: #f8f8f8; padding: 20px; text-align: center; border-radius: 4px; }
.sd-stat-value { display: block; font-size: 1.8em; font-weight: bold; }
.sd-stat-label { display: block; color: #666; margin-top: 5px; }
.sd-membership-status, .sd-recent-donations { background: #f8f8f8; padding: 20px; margin: 20px 0; border-radius: 4px; }
.sd-membership-card { background: #f8f8f8; padding: 20px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #2271b1; }
.sd-membership-card.sd-active { border-left-color: #00a32a; }
.sd-memorials-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
.sd-memorial-card { background: #f8f8f8; padding: 20px; border-radius: 4px; }
.sd-statement-content { background: #fff; border: 1px solid #ddd; padding: 40px; margin-top: 20px; }
.sd-statement-summary { width: 100%; margin: 20px 0; border-collapse: collapse; }
.sd-statement-summary td { padding: 10px; border-bottom: 1px solid #ddd; }
.sd-statement-summary .sd-total td { border-top: 2px solid #333; font-size: 1.1em; }
.sd-no-donor { text-align: center; padding: 40px; background: #f8f8f8; border-radius: 4px; }
';
    }

    /**
     * Flush rewrite rules on activation.
     *
     * @since 1.0.0
     */
    public static function flush_rewrite_rules(): void {
        self::register_endpoints();
        flush_rewrite_rules();
    }
}
