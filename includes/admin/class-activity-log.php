<?php
/**
 * Admin Activity Log - Tracks important events for auditing.
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
 * Handles activity logging and display.
 *
 * @since 1.0.0
 */
class Activity_Log {

    /**
     * Database table name (without prefix).
     *
     * @var string
     */
    private const TABLE_NAME = 'sd_activity_log';

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-activity';

    /**
     * Initialize activity log.
     */
    public static function init(): void {
        // Create table on activation.
        register_activation_hook( STARTER_SHELTER_FILE, [ self::class, 'create_table' ] );

        // Add admin page.
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        // Log events.
        add_action( 'starter_shelter_donation_created', [ self::class, 'log_donation_created' ], 10, 3 );
        add_action( 'starter_shelter_membership_created', [ self::class, 'log_membership_created' ], 10, 3 );
        add_action( 'starter_shelter_memorial_created', [ self::class, 'log_memorial_created' ], 10, 3 );
        add_action( 'starter_shelter_membership_renewed', [ self::class, 'log_membership_renewed' ], 10, 3 );
        add_action( 'starter_shelter_logo_approved', [ self::class, 'log_logo_approved' ], 10, 3 );
        add_action( 'starter_shelter_logo_rejected', [ self::class, 'log_logo_rejected' ], 10, 3 );
        add_action( 'starter_shelter_order_processed', [ self::class, 'log_order_processed' ], 10, 3 );
        
        // Email events.
        add_action( 'starter_shelter_email_sent', [ self::class, 'log_email_sent' ], 10, 4 );
        
        // Settings changes.
        add_action( 'update_option_starter_shelter_options', [ self::class, 'log_settings_changed' ], 10, 2 );

        // Manual admin actions.
        add_action( 'starter_shelter_membership_extended', [ self::class, 'log_membership_extended' ], 10, 2 );
        add_action( 'starter_shelter_family_notified', [ self::class, 'log_family_notified' ], 10, 2 );

        // Cleanup old logs.
        add_action( 'sd_cleanup_activity_log', [ self::class, 'cleanup_old_logs' ] );
        
        if ( ! wp_next_scheduled( 'sd_cleanup_activity_log' ) ) {
            wp_schedule_event( time(), 'daily', 'sd_cleanup_activity_log' );
        }
    }

    /**
     * Create the activity log table.
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_category varchar(30) NOT NULL,
            message text NOT NULL,
            object_type varchar(30) DEFAULT NULL,
            object_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            meta longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY event_category (event_category),
            KEY object_type_id (object_type, object_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Add activity log page to admin menu.
     */
    public static function add_menu_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Activity Log', 'starter-shelter' ),
            __( 'Activity Log', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Enqueue admin assets.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', self::get_inline_styles() );
    }

    /**
     * Log an activity.
     *
     * @param string      $event_type    Event type identifier.
     * @param string      $category      Event category (donation, membership, email, admin, system).
     * @param string      $message       Human-readable message.
     * @param string|null $object_type   Related object type (post type).
     * @param int|null    $object_id     Related object ID.
     * @param array       $meta          Additional metadata.
     */
    public static function log(
        string $event_type,
        string $category,
        string $message,
        ?string $object_type = null,
        ?int $object_id = null,
        array $meta = []
    ): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->insert(
            $table_name,
            [
                'event_type'     => $event_type,
                'event_category' => $category,
                'message'        => $message,
                'object_type'    => $object_type,
                'object_id'      => $object_id,
                'user_id'        => get_current_user_id() ?: null,
                'ip_address'     => self::get_client_ip(),
                'meta'           => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Log donation created.
     */
    public static function log_donation_created( int $donation_id, int $donor_id, array $data ): void {
        $amount = Helpers\format_currency( $data['amount'] ?? 0 );
        $donor_name = self::get_donor_name( $donor_id );

        self::log(
            'donation_created',
            'donation',
            sprintf( __( '%s donated %s', 'starter-shelter' ), $donor_name, $amount ),
            'sd_donation',
            $donation_id,
            [
                'donor_id'   => $donor_id,
                'amount'     => $data['amount'] ?? 0,
                'allocation' => $data['allocation'] ?? '',
            ]
        );
    }

    /**
     * Log membership created.
     */
    public static function log_membership_created( int $membership_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );
        $tier = $data['tier'] ?? '';
        $type = $data['membership_type'] ?? 'individual';

        self::log(
            'membership_created',
            'membership',
            sprintf( __( '%s joined as %s %s member', 'starter-shelter' ), $donor_name, ucfirst( $tier ), $type ),
            'sd_membership',
            $membership_id,
            [
                'donor_id' => $donor_id,
                'tier'     => $tier,
                'type'     => $type,
                'amount'   => $data['amount'] ?? 0,
            ]
        );
    }

    /**
     * Log memorial created.
     */
    public static function log_memorial_created( int $memorial_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );
        $honoree = $data['honoree_name'] ?? __( 'Unknown', 'starter-shelter' );

        self::log(
            'memorial_created',
            'donation',
            sprintf( __( '%s created memorial for %s', 'starter-shelter' ), $donor_name, $honoree ),
            'sd_memorial',
            $memorial_id,
            [
                'donor_id'     => $donor_id,
                'honoree_name' => $honoree,
                'type'         => $data['memorial_type'] ?? '',
                'amount'       => $data['amount'] ?? 0,
            ]
        );
    }

    /**
     * Log membership renewed.
     */
    public static function log_membership_renewed( int $membership_id, int $donor_id, array $data ): void {
        $donor_name = self::get_donor_name( $donor_id );

        self::log(
            'membership_renewed',
            'membership',
            sprintf( __( '%s renewed membership', 'starter-shelter' ), $donor_name ),
            'sd_membership',
            $membership_id,
            [
                'donor_id' => $donor_id,
                'new_end_date' => $data['new_end_date'] ?? '',
            ]
        );
    }

    /**
     * Log logo approved.
     */
    public static function log_logo_approved( int $membership_id, int $donor_id, array $data ): void {
        $business_name = $data['business_name'] ?? __( 'Unknown Business', 'starter-shelter' );
        $admin_user = wp_get_current_user();

        self::log(
            'logo_approved',
            'admin',
            sprintf( __( 'Logo approved for %s by %s', 'starter-shelter' ), $business_name, $admin_user->display_name ),
            'sd_membership',
            $membership_id,
            [
                'business_name' => $business_name,
                'approved_by'   => get_current_user_id(),
            ]
        );
    }

    /**
     * Log logo rejected.
     */
    public static function log_logo_rejected( int $membership_id, int $donor_id, array $data ): void {
        $business_name = $data['membership']['business_name'] ?? __( 'Unknown Business', 'starter-shelter' );
        $reason = $data['reason'] ?? '';
        $admin_user = wp_get_current_user();

        self::log(
            'logo_rejected',
            'admin',
            sprintf( __( 'Logo rejected for %s by %s: %s', 'starter-shelter' ), $business_name, $admin_user->display_name, $reason ),
            'sd_membership',
            $membership_id,
            [
                'business_name' => $business_name,
                'rejected_by'   => get_current_user_id(),
                'reason'        => $reason,
            ]
        );
    }

    /**
     * Log order processed.
     */
    public static function log_order_processed( int $order_id, array $results, bool $has_errors ): void {
        $status = $has_errors ? __( 'with errors', 'starter-shelter' ) : __( 'successfully', 'starter-shelter' );

        self::log(
            'order_processed',
            'system',
            sprintf( __( 'Order #%d processed %s', 'starter-shelter' ), $order_id, $status ),
            'shop_order',
            $order_id,
            [
                'has_errors'   => $has_errors,
                'items_count'  => count( $results ),
            ]
        );
    }

    /**
     * Log email sent.
     */
    public static function log_email_sent( string $email_type, string $recipient, int $object_id, array $data ): void {
        self::log(
            'email_sent',
            'email',
            sprintf( __( '%s email sent to %s', 'starter-shelter' ), ucfirst( str_replace( '_', ' ', $email_type ) ), $recipient ),
            $data['object_type'] ?? null,
            $object_id,
            [
                'email_type' => $email_type,
                'recipient'  => $recipient,
            ]
        );
    }

    /**
     * Log settings changed.
     */
    public static function log_settings_changed( $old_value, $new_value ): void {
        $admin_user = wp_get_current_user();
        
        // Find what changed.
        $changed = [];
        foreach ( $new_value as $key => $value ) {
            if ( ! isset( $old_value[ $key ] ) || $old_value[ $key ] !== $value ) {
                $changed[] = $key;
            }
        }

        if ( ! empty( $changed ) ) {
            self::log(
                'settings_changed',
                'admin',
                sprintf( __( 'Settings updated by %s: %s', 'starter-shelter' ), $admin_user->display_name, implode( ', ', $changed ) ),
                null,
                null,
                [
                    'changed_fields' => $changed,
                    'changed_by'     => get_current_user_id(),
                ]
            );
        }
    }

    /**
     * Log membership extended.
     */
    public static function log_membership_extended( int $membership_id, string $new_end_date ): void {
        $admin_user = wp_get_current_user();
        $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
        $donor_name = $membership['donor_id'] ? self::get_donor_name( $membership['donor_id'] ) : __( 'Unknown', 'starter-shelter' );

        self::log(
            'membership_extended',
            'admin',
            sprintf( __( 'Membership for %s extended to %s by %s', 'starter-shelter' ), $donor_name, $new_end_date, $admin_user->display_name ),
            'sd_membership',
            $membership_id,
            [
                'new_end_date' => $new_end_date,
                'extended_by'  => get_current_user_id(),
            ]
        );
    }

    /**
     * Log family notified.
     */
    public static function log_family_notified( int $memorial_id, string $family_email ): void {
        $memorial = Entity_Hydrator::get( 'sd_memorial', $memorial_id );
        $honoree = $memorial['honoree_name'] ?? __( 'Unknown', 'starter-shelter' );

        self::log(
            'family_notified',
            'email',
            sprintf( __( 'Family notification sent for memorial of %s to %s', 'starter-shelter' ), $honoree, $family_email ),
            'sd_memorial',
            $memorial_id,
            [
                'family_email' => $family_email,
                'honoree_name' => $honoree,
            ]
        );
    }

    /**
     * Render the activity log page.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            self::create_table();
        }

        // Filters.
        $category_filter = sanitize_key( $_GET['category'] ?? '' );
        $date_filter = sanitize_key( $_GET['date'] ?? '' );
        $search = sanitize_text_field( $_GET['s'] ?? '' );

        // Pagination.
        $per_page = 50;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset = ( $current_page - 1 ) * $per_page;

        // Build query.
        $where = '1=1';
        $params = [];

        if ( $category_filter ) {
            $where .= ' AND event_category = %s';
            $params[] = $category_filter;
        }

        if ( $date_filter ) {
            switch ( $date_filter ) {
                case 'today':
                    $where .= ' AND DATE(created_at) = CURDATE()';
                    break;
                case 'week':
                    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                    break;
                case 'month':
                    $where .= ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                    break;
            }
        }

        if ( $search ) {
            $where .= ' AND message LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        // Get total count.
        $count_query = "SELECT COUNT(*) FROM $table_name WHERE $where";
        $total_items = $params ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ) : $wpdb->get_var( $count_query );

        // Get logs.
        $query = "SELECT * FROM $table_name WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $logs = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        // Get categories for filter.
        $categories = $wpdb->get_col( "SELECT DISTINCT event_category FROM $table_name ORDER BY event_category" );

        ?>
        <div class="wrap sd-activity-log">
            <h1><?php esc_html_e( 'Activity Log', 'starter-shelter' ); ?></h1>

            <!-- Filters -->
            <div class="sd-log-filters">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
                    
                    <select name="category">
                        <option value=""><?php esc_html_e( 'All Categories', 'starter-shelter' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
                            <?php echo esc_html( ucfirst( $cat ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="date">
                        <option value=""><?php esc_html_e( 'All Time', 'starter-shelter' ); ?></option>
                        <option value="today" <?php selected( $date_filter, 'today' ); ?>><?php esc_html_e( 'Today', 'starter-shelter' ); ?></option>
                        <option value="week" <?php selected( $date_filter, 'week' ); ?>><?php esc_html_e( 'Last 7 Days', 'starter-shelter' ); ?></option>
                        <option value="month" <?php selected( $date_filter, 'month' ); ?>><?php esc_html_e( 'Last 30 Days', 'starter-shelter' ); ?></option>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'starter-shelter' ); ?>" />

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'starter-shelter' ); ?></button>
                </form>
            </div>

            <!-- Stats summary -->
            <div class="sd-log-stats">
                <?php
                $today_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = CURDATE()" );
                $week_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
                ?>
                <span><strong><?php echo esc_html( $today_count ); ?></strong> <?php esc_html_e( 'today', 'starter-shelter' ); ?></span>
                <span><strong><?php echo esc_html( $week_count ); ?></strong> <?php esc_html_e( 'this week', 'starter-shelter' ); ?></span>
                <span><strong><?php echo esc_html( $total_items ); ?></strong> <?php esc_html_e( 'total', 'starter-shelter' ); ?></span>
            </div>

            <!-- Log table -->
            <?php if ( empty( $logs ) ) : ?>
            <div class="sd-empty-state">
                <span class="dashicons dashicons-list-view"></span>
                <p><?php esc_html_e( 'No activity logged yet.', 'starter-shelter' ); ?></p>
            </div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped sd-log-table">
                <thead>
                    <tr>
                        <th class="column-time"><?php esc_html_e( 'Time', 'starter-shelter' ); ?></th>
                        <th class="column-category"><?php esc_html_e( 'Category', 'starter-shelter' ); ?></th>
                        <th class="column-message"><?php esc_html_e( 'Activity', 'starter-shelter' ); ?></th>
                        <th class="column-user"><?php esc_html_e( 'User', 'starter-shelter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td class="column-time">
                            <span class="sd-log-date"><?php echo esc_html( wp_date( 'M j', strtotime( $log->created_at ) ) ); ?></span>
                            <span class="sd-log-time"><?php echo esc_html( wp_date( 'g:i a', strtotime( $log->created_at ) ) ); ?></span>
                        </td>
                        <td class="column-category">
                            <span class="sd-category-badge sd-category-<?php echo esc_attr( $log->event_category ); ?>">
                                <?php echo esc_html( self::get_category_icon( $log->event_category ) ); ?>
                                <?php echo esc_html( ucfirst( $log->event_category ) ); ?>
                            </span>
                        </td>
                        <td class="column-message">
                            <?php echo esc_html( $log->message ); ?>
                            <?php if ( $log->object_type && $log->object_id ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $log->object_id ) ); ?>" class="sd-log-link">
                                #<?php echo esc_html( $log->object_id ); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="column-user">
                            <?php
                            if ( $log->user_id ) {
                                $user = get_user_by( 'id', $log->user_id );
                                echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'starter-shelter' );
                            } else {
                                echo '<span class="sd-system-user">' . esc_html__( 'System', 'starter-shelter' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php
            $total_pages = ceil( $total_items / $per_page );
            if ( $total_pages > 1 ) :
                $page_links = paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ] );
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo $page_links; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get category icon.
     */
    private static function get_category_icon( string $category ): string {
        $icons = [
            'donation'   => '💰',
            'membership' => '🏅',
            'email'      => '✉️',
            'admin'      => '👤',
            'system'     => '⚙️',
        ];

        return $icons[ $category ] ?? '📋';
    }

    /**
     * Get donor name by ID.
     */
    private static function get_donor_name( int $donor_id ): string {
        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
        
        if ( ! $donor ) {
            return __( 'Unknown Donor', 'starter-shelter' );
        }

        return $donor['display_name'] ?? trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) ) ?: __( 'Unknown Donor', 'starter-shelter' );
    }

    /**
     * Get client IP address.
     */
    private static function get_client_ip(): ?string {
        $ip_keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = explode( ',', $_SERVER[ $key ] )[0];
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Cleanup old log entries (older than 90 days).
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $days_to_keep = apply_filters( 'starter_shelter_activity_log_retention_days', 90 );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_to_keep
        ) );
    }

    /**
     * Get inline styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-log-filters {
                background: #fff;
                padding: 15px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .sd-log-filters form {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .sd-log-filters select,
            .sd-log-filters input[type="search"] {
                min-width: 150px;
            }
            
            .sd-log-stats {
                margin-bottom: 15px;
                display: flex;
                gap: 20px;
            }
            .sd-log-stats span {
                color: #646970;
            }
            
            .sd-empty-state {
                text-align: center;
                padding: 60px 20px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .sd-empty-state .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #c3c4c7;
            }
            
            .sd-log-table .column-time { width: 100px; }
            .sd-log-table .column-category { width: 120px; }
            .sd-log-table .column-user { width: 150px; }
            
            .sd-log-date {
                display: block;
                font-weight: 600;
            }
            .sd-log-time {
                color: #646970;
                font-size: 12px;
            }
            
            .sd-category-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .sd-category-donation { background: #d1fae5; color: #065f46; }
            .sd-category-membership { background: #dbeafe; color: #1e40af; }
            .sd-category-email { background: #fef3c7; color: #92400e; }
            .sd-category-admin { background: #ede9fe; color: #5b21b6; }
            .sd-category-system { background: #f3f4f6; color: #374151; }
            
            .sd-log-link {
                margin-left: 5px;
                color: #2271b1;
                text-decoration: none;
            }
            
            .sd-system-user {
                color: #888;
                font-style: italic;
            }
        ';
    }
}
