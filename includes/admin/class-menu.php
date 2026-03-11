<?php
/**
 * Admin Menu - Registers the top-level Shelter Donations admin menu.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

/**
 * Handles the main admin menu structure.
 *
 * @since 1.0.0
 */
class Menu {

    /**
     * Menu slug.
     *
     * @since 1.0.0
     * @var string
     */
    public const MENU_SLUG = 'starter-shelter';

    /**
     * Initialize the admin menu.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 5 );
    }

    /**
     * Register the top-level admin menu.
     *
     * @since 1.0.0
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'Shelter Donations', 'starter-shelter' ),
            __( 'Shelter Donations', 'starter-shelter' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_dashboard_page' ],
            'dashicons-heart',
            26
        );

        // Add "Dashboard" as first submenu (replaces the duplicate parent link).
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'starter-shelter' ),
            __( 'Dashboard', 'starter-shelter' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_dashboard_page' ]
        );
    }

    /**
     * Render the main dashboard page.
     *
     * @since 1.0.0
     */
    public static function render_dashboard_page(): void {
        // Get stats using the dashboard-stats ability.
        $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'shelter-reports/dashboard-stats' ) : null;
        
        $stats = null;
        if ( $ability ) {
            $stats = $ability->execute( [ 'period' => 'month' ] );
            if ( is_wp_error( $stats ) ) {
                $stats = null;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Shelter Donations', 'starter-shelter' ); ?></h1>
            
            <div class="sd-admin-dashboard">
                <?php if ( $stats ) : ?>
                <div class="sd-dashboard-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    
                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #00a32a;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Donations This Month', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( '$' . number_format( $stats['donations']['total'] ?? 0, 2 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: donation count */
                                esc_html__( '%d donations', 'starter-shelter' ),
                                $stats['donations']['count'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Active Members', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['memberships']['active'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: new members count */
                                esc_html__( '%d new this month', 'starter-shelter' ),
                                $stats['memberships']['new'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo ( $stats['memberships']['expiring_soon'] ?? 0 ) > 0 ? '#dba617' : '#c3c4c7'; ?>;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Expiring Soon', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['memberships']['expiring_soon'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php esc_html_e( 'within 30 days', 'starter-shelter' ); ?>
                        </p>
                    </div>

                    <div class="sd-dashboard-card" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; border-left: 4px solid #8c6db8;">
                        <h3 style="margin: 0 0 10px; font-size: 14px; color: #646970;">
                            <?php esc_html_e( 'Total Donors', 'starter-shelter' ); ?>
                        </h3>
                        <p style="margin: 0; font-size: 28px; font-weight: 600;">
                            <?php echo esc_html( number_format( $stats['donors']['total'] ?? 0 ) ); ?>
                        </p>
                        <p style="margin: 5px 0 0; color: #646970;">
                            <?php 
                            printf(
                                /* translators: %d: new donors count */
                                esc_html__( '%d new this month', 'starter-shelter' ),
                                $stats['donors']['new'] ?? 0
                            );
                            ?>
                        </p>
                    </div>

                </div>
                <?php else : ?>
                <p><?php esc_html_e( 'Welcome to Shelter Donations! Statistics will appear here once you start receiving donations.', 'starter-shelter' ); ?></p>
                <?php endif; ?>

                <div class="sd-quick-links" style="margin-top: 30px;">
                    <h2><?php esc_html_e( 'Quick Links', 'starter-shelter' ); ?></h2>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_donation' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Donations', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_membership' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Memberships', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_memorial' ) ); ?>" class="button">
                            <?php esc_html_e( 'View Memorials', 'starter-shelter' ); ?>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=starter-shelter-reports' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'View Reports', 'starter-shelter' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get the menu slug.
     *
     * @since 1.0.0
     *
     * @return string The menu slug.
     */
    public static function get_menu_slug(): string {
        return self::MENU_SLUG;
    }
}
