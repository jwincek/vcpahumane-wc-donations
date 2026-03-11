<?php
/**
 * Dashboard Widget - Enhanced stats overview with action items.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Helpers;

/**
 * Adds a dashboard widget with shelter donation statistics and action items.
 *
 * @since 1.0.0
 */
class Dashboard_Widget {

    /**
     * Widget ID.
     *
     * @var string
     */
    private const WIDGET_ID = 'sd_dashboard_widget';

    /**
     * Initialize the dashboard widget.
     */
    public static function init(): void {
        add_action( 'wp_dashboard_setup', [ self::class, 'register_widget' ] );
        add_action( 'wp_ajax_sd_dashboard_refresh', [ self::class, 'ajax_refresh' ] );
    }

    /**
     * Register the dashboard widget.
     */
    public static function register_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __( 'Shelter Donations Overview', 'starter-shelter' ),
            [ self::class, 'render_widget' ],
            [ self::class, 'render_widget_config' ],
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget.
     */
    public static function render_widget(): void {
        $period = get_user_option( 'sd_dashboard_period' ) ?: 'month';
        
        // Get stats using the dashboard-stats ability.
        $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );
        
        if ( ! $ability ) {
            echo '<p>' . esc_html__( 'Unable to load statistics.', 'starter-shelter' ) . '</p>';
            return;
        }

        $stats = $ability->execute( [ 'period' => $period ] );

        if ( is_wp_error( $stats ) ) {
            echo '<p class="error">' . esc_html( $stats->get_error_message() ) . '</p>';
            return;
        }

        $donation_stats   = $stats['donations'] ?? [];
        $membership_stats = $stats['memberships'] ?? [];
        
        // Get action items.
        $action_items = self::get_action_items();

        self::render_styles();
        ?>

        <!-- Period Tabs -->
        <div class="sd-widget-tabs">
            <?php
            $periods = [
                'today'  => __( 'Today', 'starter-shelter' ),
                'week'   => __( 'Week', 'starter-shelter' ),
                'month'  => __( 'Month', 'starter-shelter' ),
                'year'   => __( 'Year', 'starter-shelter' ),
            ];
            foreach ( $periods as $key => $label ) :
            ?>
            <button type="button" 
                    class="sd-tab <?php echo $period === $key ? 'active' : ''; ?>" 
                    data-period="<?php echo esc_attr( $key ); ?>">
                <?php echo esc_html( $label ); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Stats Grid -->
        <div class="sd-widget-stats" id="sd-widget-stats">
            <div class="sd-widget-stat sd-stat-primary">
                <span class="sd-stat-icon">💰</span>
                <div class="sd-stat-content">
                    <span class="sd-widget-stat-value">
                        <?php echo esc_html( Helpers\format_currency( $donation_stats['total'] ?? 0 ) ); ?>
                    </span>
                    <span class="sd-widget-stat-label"><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></span>
                </div>
            </div>
            
            <div class="sd-widget-stat">
                <span class="sd-stat-icon">📝</span>
                <div class="sd-stat-content">
                    <span class="sd-widget-stat-value">
                        <?php echo esc_html( number_format( $donation_stats['count'] ?? 0 ) ); ?>
                    </span>
                    <span class="sd-widget-stat-label"><?php esc_html_e( 'Transactions', 'starter-shelter' ); ?></span>
                </div>
            </div>
            
            <div class="sd-widget-stat">
                <span class="sd-stat-icon">👥</span>
                <div class="sd-stat-content">
                    <span class="sd-widget-stat-value">
                        <?php echo esc_html( number_format( $donation_stats['unique_donors'] ?? 0 ) ); ?>
                    </span>
                    <span class="sd-widget-stat-label"><?php esc_html_e( 'Donors', 'starter-shelter' ); ?></span>
                </div>
            </div>
            
            <div class="sd-widget-stat">
                <span class="sd-stat-icon">🏅</span>
                <div class="sd-stat-content">
                    <span class="sd-widget-stat-value">
                        <?php echo esc_html( number_format( $membership_stats['active'] ?? 0 ) ); ?>
                    </span>
                    <span class="sd-widget-stat-label"><?php esc_html_e( 'Members', 'starter-shelter' ); ?></span>
                </div>
            </div>
        </div>

        <?php if ( ! empty( $action_items ) ) : ?>
        <!-- Action Items -->
        <div class="sd-widget-actions">
            <h4>
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <?php esc_html_e( 'Action Required', 'starter-shelter' ); ?>
            </h4>
            <ul>
                <?php foreach ( $action_items as $item ) : ?>
                <li>
                    <a href="<?php echo esc_url( $item['url'] ); ?>">
                        <span class="sd-action-count"><?php echo esc_html( $item['count'] ); ?></span>
                        <?php echo esc_html( $item['label'] ); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Recent Activity -->
        <div class="sd-widget-recent">
            <h4><?php esc_html_e( 'Recent Activity', 'starter-shelter' ); ?></h4>
            <?php 
            $recent = self::get_recent_activity( 5 );
            if ( ! empty( $recent ) ) :
            ?>
            <ul>
                <?php foreach ( $recent as $activity ) : ?>
                <li>
                    <span class="sd-activity-icon"><?php echo esc_html( $activity['icon'] ); ?></span>
                    <span class="sd-activity-text">
                        <?php echo wp_kses_post( $activity['text'] ); ?>
                        <span class="sd-activity-time"><?php echo esc_html( $activity['time'] ); ?></span>
                    </span>
                    <span class="sd-activity-amount"><?php echo esc_html( $activity['amount'] ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else : ?>
            <p class="sd-no-activity"><?php esc_html_e( 'No recent activity.', 'starter-shelter' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Footer Links -->
        <div class="sd-widget-footer">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=starter-shelter-reports' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'View Reports', 'starter-shelter' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sd_donation' ) ); ?>" class="button">
                <?php esc_html_e( 'All Donations', 'starter-shelter' ); ?>
            </a>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.sd-widget-tabs .sd-tab').on('click', function() {
                var period = $(this).data('period');
                var $tabs = $('.sd-widget-tabs .sd-tab');
                var $stats = $('#sd-widget-stats');
                
                $tabs.removeClass('active');
                $(this).addClass('active');
                $stats.css('opacity', '0.5');
                
                $.post(ajaxurl, {
                    action: 'sd_dashboard_refresh',
                    period: period,
                    nonce: '<?php echo wp_create_nonce( 'sd_dashboard_refresh' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $stats.html(response.data.html).css('opacity', '1');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render widget configuration form.
     */
    public static function render_widget_config(): void {
        if ( isset( $_POST['sd_dashboard_period'] ) ) {
            update_user_option( get_current_user_id(), 'sd_dashboard_period', sanitize_key( $_POST['sd_dashboard_period'] ) );
        }
        
        $period = get_user_option( 'sd_dashboard_period' ) ?: 'month';
        ?>
        <p>
            <label for="sd_dashboard_period"><?php esc_html_e( 'Default period:', 'starter-shelter' ); ?></label>
            <select name="sd_dashboard_period" id="sd_dashboard_period">
                <option value="today" <?php selected( $period, 'today' ); ?>><?php esc_html_e( 'Today', 'starter-shelter' ); ?></option>
                <option value="week" <?php selected( $period, 'week' ); ?>><?php esc_html_e( 'This Week', 'starter-shelter' ); ?></option>
                <option value="month" <?php selected( $period, 'month' ); ?>><?php esc_html_e( 'This Month', 'starter-shelter' ); ?></option>
                <option value="year" <?php selected( $period, 'year' ); ?>><?php esc_html_e( 'This Year', 'starter-shelter' ); ?></option>
            </select>
        </p>
        <?php
    }

    /**
     * AJAX handler for refreshing stats.
     */
    public static function ajax_refresh(): void {
        check_ajax_referer( 'sd_dashboard_refresh', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $period = sanitize_key( $_POST['period'] ?? 'month' );
        
        // Save preference.
        update_user_option( get_current_user_id(), 'sd_dashboard_period', $period );

        $ability = wp_get_ability( 'shelter-reports/dashboard-stats' );
        if ( ! $ability ) {
            wp_send_json_error();
        }

        $stats = $ability->execute( [ 'period' => $period ] );
        if ( is_wp_error( $stats ) ) {
            wp_send_json_error();
        }

        $donation_stats   = $stats['donations'] ?? [];
        $membership_stats = $stats['memberships'] ?? [];

        ob_start();
        ?>
        <div class="sd-widget-stat sd-stat-primary">
            <span class="sd-stat-icon">💰</span>
            <div class="sd-stat-content">
                <span class="sd-widget-stat-value"><?php echo esc_html( Helpers\format_currency( $donation_stats['total'] ?? 0 ) ); ?></span>
                <span class="sd-widget-stat-label"><?php esc_html_e( 'Donations', 'starter-shelter' ); ?></span>
            </div>
        </div>
        <div class="sd-widget-stat">
            <span class="sd-stat-icon">📝</span>
            <div class="sd-stat-content">
                <span class="sd-widget-stat-value"><?php echo esc_html( number_format( $donation_stats['count'] ?? 0 ) ); ?></span>
                <span class="sd-widget-stat-label"><?php esc_html_e( 'Transactions', 'starter-shelter' ); ?></span>
            </div>
        </div>
        <div class="sd-widget-stat">
            <span class="sd-stat-icon">👥</span>
            <div class="sd-stat-content">
                <span class="sd-widget-stat-value"><?php echo esc_html( number_format( $donation_stats['unique_donors'] ?? 0 ) ); ?></span>
                <span class="sd-widget-stat-label"><?php esc_html_e( 'Donors', 'starter-shelter' ); ?></span>
            </div>
        </div>
        <div class="sd-widget-stat">
            <span class="sd-stat-icon">🏅</span>
            <div class="sd-stat-content">
                <span class="sd-widget-stat-value"><?php echo esc_html( number_format( $membership_stats['active'] ?? 0 ) ); ?></span>
                <span class="sd-widget-stat-label"><?php esc_html_e( 'Members', 'starter-shelter' ); ?></span>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * Get action items that need attention.
     *
     * @return array Action items.
     */
    private static function get_action_items(): array {
        $items = [];

        // Pending logo reviews.
        $pending_logos = Logo_Moderation::get_pending_count();
        if ( $pending_logos > 0 ) {
            $items[] = [
                'count' => $pending_logos,
                'label' => _n( 'logo pending review', 'logos pending review', $pending_logos, 'starter-shelter' ),
                'url'   => admin_url( 'admin.php?page=starter-shelter-logos' ),
            ];
        }

        // Expiring memberships (next 7 days).
        global $wpdb;
        $expiring_soon = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
            WHERE p.post_type = 'sd_membership'
            AND p.post_status = 'publish'
            AND pm.meta_value BETWEEN %s AND %s
        ", wp_date( 'Y-m-d' ), wp_date( 'Y-m-d', strtotime( '+7 days' ) ) ) );

        if ( $expiring_soon > 0 ) {
            $items[] = [
                'count' => $expiring_soon,
                'label' => _n( 'membership expiring in 7 days', 'memberships expiring in 7 days', $expiring_soon, 'starter-shelter' ),
                'url'   => admin_url( 'edit.php?post_type=sd_membership&expiring=7' ),
            ];
        }

        // Pending family notifications.
        $pending_notifications = (int) $wpdb->get_var( "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_notify ON p.ID = pm_notify.post_id 
                AND pm_notify.meta_key = '_sd_notify_family_enabled' 
                AND pm_notify.meta_value = '1'
            LEFT JOIN {$wpdb->postmeta} pm_sent ON p.ID = pm_sent.post_id 
                AND pm_sent.meta_key = '_sd_family_notified_date'
            WHERE p.post_type = 'sd_memorial'
            AND p.post_status = 'publish'
            AND (pm_sent.meta_value IS NULL OR pm_sent.meta_value = '')
        " );

        if ( $pending_notifications > 0 ) {
            $items[] = [
                'count' => $pending_notifications,
                'label' => _n( 'family notification pending', 'family notifications pending', $pending_notifications, 'starter-shelter' ),
                'url'   => admin_url( 'edit.php?post_type=sd_memorial&notify_pending=1' ),
            ];
        }

        return $items;
    }

    /**
     * Get recent activity for the widget.
     *
     * @param int $count Number of items.
     * @return array Recent activity.
     */
    private static function get_recent_activity( int $count = 5 ): array {
        global $wpdb;

        // Get recent donations, memberships, and memorials.
        $results = $wpdb->get_results( $wpdb->prepare( "
            (
                SELECT 
                    p.ID, 
                    p.post_type, 
                    p.post_date,
                    pm_amount.meta_value as amount,
                    pm_donor.meta_value as donor_id,
                    pm_anon.meta_value as is_anonymous,
                    NULL as honoree_name,
                    NULL as tier
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_sd_amount'
                LEFT JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
                LEFT JOIN {$wpdb->postmeta} pm_anon ON p.ID = pm_anon.post_id AND pm_anon.meta_key = '_sd_is_anonymous'
                WHERE p.post_type = 'sd_donation' AND p.post_status = 'publish'
                ORDER BY p.post_date DESC
                LIMIT %d
            )
            UNION ALL
            (
                SELECT 
                    p.ID, 
                    p.post_type, 
                    p.post_date,
                    pm_amount.meta_value as amount,
                    pm_donor.meta_value as donor_id,
                    NULL as is_anonymous,
                    NULL as honoree_name,
                    pm_tier.meta_value as tier
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_sd_amount'
                LEFT JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
                LEFT JOIN {$wpdb->postmeta} pm_tier ON p.ID = pm_tier.post_id AND pm_tier.meta_key = '_sd_tier'
                WHERE p.post_type = 'sd_membership' AND p.post_status = 'publish'
                ORDER BY p.post_date DESC
                LIMIT %d
            )
            UNION ALL
            (
                SELECT 
                    p.ID, 
                    p.post_type, 
                    p.post_date,
                    pm_amount.meta_value as amount,
                    pm_donor.meta_value as donor_id,
                    pm_anon.meta_value as is_anonymous,
                    pm_honoree.meta_value as honoree_name,
                    NULL as tier
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_sd_amount'
                LEFT JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
                LEFT JOIN {$wpdb->postmeta} pm_anon ON p.ID = pm_anon.post_id AND pm_anon.meta_key = '_sd_is_anonymous'
                LEFT JOIN {$wpdb->postmeta} pm_honoree ON p.ID = pm_honoree.post_id AND pm_honoree.meta_key = '_sd_honoree_name'
                WHERE p.post_type = 'sd_memorial' AND p.post_status = 'publish'
                ORDER BY p.post_date DESC
                LIMIT %d
            )
            ORDER BY post_date DESC
            LIMIT %d
        ", $count, $count, $count, $count ) );

        $activity = [];
        foreach ( $results as $row ) {
            $donor_name = __( 'Someone', 'starter-shelter' );
            if ( $row->donor_id && ! $row->is_anonymous ) {
                $donor = Entity_Hydrator::get( 'sd_donor', (int) $row->donor_id );
                if ( $donor ) {
                    $donor_name = $donor['display_name'] ?? $donor['first_name'] ?? $donor_name;
                }
            } elseif ( $row->is_anonymous ) {
                $donor_name = __( 'Anonymous', 'starter-shelter' );
            }

            switch ( $row->post_type ) {
                case 'sd_donation':
                    $activity[] = [
                        'icon'   => '💰',
                        'text'   => sprintf( '<strong>%s</strong> donated', esc_html( $donor_name ) ),
                        'amount' => Helpers\format_currency( (float) $row->amount ),
                        'time'   => human_time_diff( strtotime( $row->post_date ), time() ) . ' ago',
                    ];
                    break;

                case 'sd_membership':
                    $tier_label = ucfirst( $row->tier ?? '' );
                    $activity[] = [
                        'icon'   => '🏅',
                        'text'   => sprintf( '<strong>%s</strong> joined as %s', esc_html( $donor_name ), esc_html( $tier_label ) ),
                        'amount' => Helpers\format_currency( (float) $row->amount ),
                        'time'   => human_time_diff( strtotime( $row->post_date ), time() ) . ' ago',
                    ];
                    break;

                case 'sd_memorial':
                    $honoree = $row->honoree_name ?? __( 'someone special', 'starter-shelter' );
                    $activity[] = [
                        'icon'   => '❤️',
                        'text'   => sprintf( 'Memorial for <strong>%s</strong>', esc_html( $honoree ) ),
                        'amount' => Helpers\format_currency( (float) $row->amount ),
                        'time'   => human_time_diff( strtotime( $row->post_date ), time() ) . ' ago',
                    ];
                    break;
            }
        }

        return $activity;
    }

    /**
     * Render inline styles for the widget.
     */
    private static function render_styles(): void {
        ?>
        <style>
            .sd-widget-tabs {
                display: flex;
                gap: 5px;
                margin-bottom: 15px;
                border-bottom: 1px solid #c3c4c7;
                padding-bottom: 10px;
            }
            .sd-widget-tabs .sd-tab {
                padding: 5px 12px;
                border: none;
                background: #f0f0f1;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
                transition: all 0.2s;
            }
            .sd-widget-tabs .sd-tab:hover { background: #dcdcde; }
            .sd-widget-tabs .sd-tab.active { background: #2271b1; color: #fff; }

            .sd-widget-stats {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-bottom: 15px;
                transition: opacity 0.2s;
            }
            .sd-widget-stat {
                display: flex;
                align-items: center;
                gap: 10px;
                background: #f6f7f7;
                padding: 12px;
                border-radius: 4px;
            }
            .sd-widget-stat.sd-stat-primary {
                background: linear-gradient(135deg, #059669 0%, #047857 100%);
                color: #fff;
            }
            .sd-widget-stat.sd-stat-primary .sd-widget-stat-label { color: rgba(255,255,255,0.8); }
            .sd-stat-icon { font-size: 24px; }
            .sd-stat-content { flex: 1; }
            .sd-widget-stat-value {
                font-size: 20px;
                font-weight: 600;
                display: block;
                line-height: 1.2;
            }
            .sd-widget-stat-label {
                font-size: 11px;
                color: #646970;
                text-transform: uppercase;
            }

            .sd-widget-actions {
                background: #fff8e5;
                border: 1px solid #f0c33c;
                border-radius: 4px;
                padding: 12px;
                margin-bottom: 15px;
            }
            .sd-widget-actions h4 {
                margin: 0 0 8px;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .sd-widget-actions ul { margin: 0; padding: 0; list-style: none; }
            .sd-widget-actions li { margin: 5px 0; }
            .sd-widget-actions a { text-decoration: none; color: #1d2327; }
            .sd-widget-actions a:hover { color: #2271b1; }
            .sd-action-count {
                display: inline-block;
                background: #d63638;
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                padding: 2px 6px;
                border-radius: 10px;
                margin-right: 5px;
            }

            .sd-widget-recent { margin-bottom: 15px; }
            .sd-widget-recent h4 { margin: 0 0 10px; font-size: 13px; }
            .sd-widget-recent ul { margin: 0; padding: 0; list-style: none; }
            .sd-widget-recent li {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .sd-widget-recent li:last-child { border-bottom: none; }
            .sd-activity-icon { font-size: 16px; }
            .sd-activity-text { flex: 1; }
            .sd-activity-time { display: block; font-size: 11px; color: #888; }
            .sd-activity-amount { font-weight: 600; color: #059669; }
            .sd-no-activity { color: #888; font-style: italic; margin: 0; }

            .sd-widget-footer {
                display: flex;
                gap: 10px;
                padding-top: 15px;
                border-top: 1px solid #c3c4c7;
            }
        </style>
        <?php
    }
}
