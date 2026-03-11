<?php
/**
 * Admin Logo Moderation - Business membership logo review queue.
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
 * Handles the logo moderation queue for business memberships.
 *
 * @since 1.0.0
 */
class Logo_Moderation {

    /**
     * Page slug.
     *
     * @since 1.0.0
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-logos';

    /**
     * Nonce action.
     *
     * @since 1.0.0
     * @var string
     */
    private const NONCE_ACTION = 'sd_logo_moderation';

    /**
     * Initialize logo moderation.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_sd_approve_logo', [ self::class, 'ajax_approve_logo' ] );
        add_action( 'wp_ajax_sd_reject_logo', [ self::class, 'ajax_reject_logo' ] );
        add_action( 'admin_post_sd_bulk_logo_action', [ self::class, 'handle_bulk_action' ] );
        
        // Add pending count to menu.
        add_action( 'admin_menu', [ self::class, 'add_pending_count_bubble' ], 99 );
    }

    /**
     * Add logo moderation page to admin menu.
     *
     * @since 1.0.0
     */
    public static function add_menu_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Logo Moderation', 'starter-shelter' ),
            __( 'Logo Moderation', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Add pending count bubble to menu item.
     *
     * @since 1.0.0
     */
    public static function add_pending_count_bubble(): void {
        global $submenu;

        $pending_count = self::get_pending_count();
        
        if ( $pending_count > 0 && isset( $submenu[ Menu::MENU_SLUG ] ) ) {
            foreach ( $submenu[ Menu::MENU_SLUG ] as $key => $item ) {
                if ( self::PAGE_SLUG === $item[2] ) {
                    $submenu[ Menu::MENU_SLUG ][ $key ][0] .= sprintf(
                        ' <span class="awaiting-mod">%d</span>',
                        $pending_count
                    );
                    break;
                }
            }
        }
    }

    /**
     * Get count of pending logos.
     *
     * @since 1.0.0
     *
     * @return int Pending count.
     */
    public static function get_pending_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id 
                AND pm_type.meta_key = '_sd_membership_type' 
                AND pm_type.meta_value = 'business'
            JOIN {$wpdb->postmeta} pm_logo ON p.ID = pm_logo.post_id 
                AND pm_logo.meta_key = '_sd_logo_attachment_id' 
                AND pm_logo.meta_value > 0
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
                AND pm_status.meta_key = '_sd_logo_status'
            WHERE p.post_type = 'sd_membership'
            AND p.post_status = 'publish'
            AND (pm_status.meta_value IS NULL OR pm_status.meta_value = 'pending')
        " );
    }

    /**
     * Enqueue admin assets.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style( 'thickbox' );
        wp_enqueue_script( 'thickbox' );

        wp_enqueue_script(
            'sd-logo-moderation',
            STARTER_SHELTER_URL . 'assets/js/admin-logo-moderation.js',
            [ 'jquery', 'thickbox' ],
            STARTER_SHELTER_VERSION,
            true
        );

        wp_localize_script( 'sd-logo-moderation', 'sdLogoMod', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
            'confirmReject' => __( 'Are you sure you want to reject this logo?', 'starter-shelter' ),
            'approving'     => __( 'Approving...', 'starter-shelter' ),
            'rejecting'     => __( 'Rejecting...', 'starter-shelter' ),
            'approved'      => __( 'Approved!', 'starter-shelter' ),
            'rejected'      => __( 'Rejected', 'starter-shelter' ),
            'error'         => __( 'Error occurred. Please try again.', 'starter-shelter' ),
        ] );

        wp_add_inline_style( 'wp-admin', self::get_inline_styles() );
    }

    /**
     * Render the logo moderation page.
     *
     * @since 1.0.0
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle filter.
        $status_filter = sanitize_key( $_GET['status'] ?? 'pending' );
        $logos = self::get_logos( $status_filter );

        ?>
        <div class="wrap sd-logo-moderation">
            <h1><?php esc_html_e( 'Logo Moderation', 'starter-shelter' ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Review and approve business membership logos before they appear on the donor wall.', 'starter-shelter' ); ?>
            </p>

            <!-- Status filter tabs -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url( add_query_arg( 'status', 'pending' ) ); ?>" 
                       class="<?php echo 'pending' === $status_filter ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Pending', 'starter-shelter' ); ?>
                        <span class="count">(<?php echo esc_html( self::get_count_by_status( 'pending' ) ); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( 'status', 'approved' ) ); ?>"
                       class="<?php echo 'approved' === $status_filter ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Approved', 'starter-shelter' ); ?>
                        <span class="count">(<?php echo esc_html( self::get_count_by_status( 'approved' ) ); ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( 'status', 'rejected' ) ); ?>"
                       class="<?php echo 'rejected' === $status_filter ? 'current' : ''; ?>">
                        <?php esc_html_e( 'Rejected', 'starter-shelter' ); ?>
                        <span class="count">(<?php echo esc_html( self::get_count_by_status( 'rejected' ) ); ?>)</span>
                    </a>
                </li>
            </ul>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sd_bulk_logo_action" />
                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>" />

                <?php if ( 'pending' === $status_filter && ! empty( $logos ) ) : ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value=""><?php esc_html_e( 'Bulk Actions', 'starter-shelter' ); ?></option>
                            <option value="approve"><?php esc_html_e( 'Approve Selected', 'starter-shelter' ); ?></option>
                            <option value="reject"><?php esc_html_e( 'Reject Selected', 'starter-shelter' ); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'starter-shelter' ); ?>" />
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( empty( $logos ) ) : ?>
                <div class="sd-empty-state">
                    <?php if ( 'pending' === $status_filter ) : ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p><?php esc_html_e( 'No logos pending review. Great job!', 'starter-shelter' ); ?></p>
                    <?php else : ?>
                        <span class="dashicons dashicons-format-image"></span>
                        <p><?php esc_html_e( 'No logos found with this status.', 'starter-shelter' ); ?></p>
                    <?php endif; ?>
                </div>
                <?php else : ?>

                <div class="sd-logo-grid">
                    <?php foreach ( $logos as $logo ) : ?>
                    <div class="sd-logo-card" data-membership-id="<?php echo esc_attr( $logo['membership_id'] ); ?>">
                        <?php if ( 'pending' === $status_filter ) : ?>
                        <label class="sd-logo-checkbox">
                            <input type="checkbox" name="membership_ids[]" value="<?php echo esc_attr( $logo['membership_id'] ); ?>" />
                        </label>
                        <?php endif; ?>

                        <div class="sd-logo-preview">
                            <a href="<?php echo esc_url( $logo['logo_url_full'] ); ?>" class="thickbox">
                                <img src="<?php echo esc_url( $logo['logo_url'] ); ?>" 
                                     alt="<?php echo esc_attr( $logo['business_name'] ); ?>" />
                            </a>
                        </div>

                        <div class="sd-logo-info">
                            <h3 class="sd-business-name">
                                <a href="<?php echo esc_url( get_edit_post_link( $logo['membership_id'] ) ); ?>">
                                    <?php echo esc_html( $logo['business_name'] ); ?>
                                </a>
                            </h3>
                            
                            <p class="sd-membership-tier">
                                <?php echo esc_html( $logo['tier_label'] ); ?> • 
                                <?php echo esc_html( $logo['donor_name'] ); ?>
                            </p>
                            
                            <p class="sd-upload-date">
                                <?php 
                                printf(
                                    /* translators: %s: date */
                                    esc_html__( 'Uploaded %s', 'starter-shelter' ),
                                    esc_html( human_time_diff( strtotime( $logo['upload_date'] ), time() ) . ' ago' )
                                );
                                ?>
                            </p>

                            <?php if ( 'rejected' === $status_filter && ! empty( $logo['rejection_reason'] ) ) : ?>
                            <p class="sd-rejection-reason">
                                <strong><?php esc_html_e( 'Reason:', 'starter-shelter' ); ?></strong>
                                <?php echo esc_html( $logo['rejection_reason'] ); ?>
                            </p>
                            <?php endif; ?>
                        </div>

                        <div class="sd-logo-actions">
                            <?php if ( 'pending' === $status_filter ) : ?>
                            <button type="button" 
                                    class="button button-primary sd-approve-btn" 
                                    data-id="<?php echo esc_attr( $logo['membership_id'] ); ?>">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e( 'Approve', 'starter-shelter' ); ?>
                            </button>
                            
                            <button type="button" 
                                    class="button sd-reject-btn" 
                                    data-id="<?php echo esc_attr( $logo['membership_id'] ); ?>">
                                <span class="dashicons dashicons-no"></span>
                                <?php esc_html_e( 'Reject', 'starter-shelter' ); ?>
                            </button>
                            <?php elseif ( 'approved' === $status_filter ) : ?>
                            <span class="sd-status-badge sd-status--approved">
                                <?php esc_html_e( 'Approved', 'starter-shelter' ); ?>
                            </span>
                            <?php elseif ( 'rejected' === $status_filter ) : ?>
                            <span class="sd-status-badge sd-status--rejected">
                                <?php esc_html_e( 'Rejected', 'starter-shelter' ); ?>
                            </span>
                            <button type="button" 
                                    class="button sd-approve-btn" 
                                    data-id="<?php echo esc_attr( $logo['membership_id'] ); ?>">
                                <?php esc_html_e( 'Approve', 'starter-shelter' ); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </form>

            <!-- Rejection reason modal -->
            <div id="sd-reject-modal" class="sd-modal" style="display:none;">
                <div class="sd-modal-content">
                    <h2><?php esc_html_e( 'Reject Logo', 'starter-shelter' ); ?></h2>
                    <p><?php esc_html_e( 'Please select a reason for rejection:', 'starter-shelter' ); ?></p>
                    
                    <select id="sd-reject-reason" class="widefat">
                        <option value=""><?php esc_html_e( 'Select a reason...', 'starter-shelter' ); ?></option>
                        <option value="inappropriate"><?php esc_html_e( 'Inappropriate content', 'starter-shelter' ); ?></option>
                        <option value="low_quality"><?php esc_html_e( 'Low image quality', 'starter-shelter' ); ?></option>
                        <option value="wrong_format"><?php esc_html_e( 'Wrong format or dimensions', 'starter-shelter' ); ?></option>
                        <option value="copyright"><?php esc_html_e( 'Copyright concerns', 'starter-shelter' ); ?></option>
                        <option value="other"><?php esc_html_e( 'Other (specify below)', 'starter-shelter' ); ?></option>
                    </select>
                    
                    <textarea id="sd-reject-notes" class="widefat" rows="3" 
                              placeholder="<?php esc_attr_e( 'Additional notes (optional)...', 'starter-shelter' ); ?>"></textarea>
                    
                    <div class="sd-modal-actions">
                        <button type="button" class="button sd-modal-cancel">
                            <?php esc_html_e( 'Cancel', 'starter-shelter' ); ?>
                        </button>
                        <button type="button" class="button button-primary sd-modal-confirm">
                            <?php esc_html_e( 'Reject Logo', 'starter-shelter' ); ?>
                        </button>
                    </div>
                    
                    <input type="hidden" id="sd-reject-membership-id" value="" />
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get logos by status.
     *
     * @since 1.0.0
     *
     * @param string $status Logo status filter.
     * @return array Logo data.
     */
    private static function get_logos( string $status = 'pending' ): array {
        global $wpdb;

        $status_condition = '';
        if ( 'pending' === $status ) {
            $status_condition = "AND (pm_status.meta_value IS NULL OR pm_status.meta_value = 'pending')";
        } else {
            $status_condition = $wpdb->prepare( "AND pm_status.meta_value = %s", $status );
        }

        $results = $wpdb->get_results( "
            SELECT 
                p.ID as membership_id,
                pm_logo.meta_value as logo_id,
                pm_business.meta_value as business_name,
                pm_tier.meta_value as tier,
                pm_donor.meta_value as donor_id,
                pm_status.meta_value as logo_status,
                pm_reason.meta_value as rejection_reason,
                p.post_date as upload_date
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id 
                AND pm_type.meta_key = '_sd_membership_type' 
                AND pm_type.meta_value = 'business'
            JOIN {$wpdb->postmeta} pm_logo ON p.ID = pm_logo.post_id 
                AND pm_logo.meta_key = '_sd_logo_attachment_id' 
                AND pm_logo.meta_value > 0
            LEFT JOIN {$wpdb->postmeta} pm_business ON p.ID = pm_business.post_id 
                AND pm_business.meta_key = '_sd_business_name'
            LEFT JOIN {$wpdb->postmeta} pm_tier ON p.ID = pm_tier.post_id 
                AND pm_tier.meta_key = '_sd_tier'
            LEFT JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id 
                AND pm_donor.meta_key = '_sd_donor_id'
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
                AND pm_status.meta_key = '_sd_logo_status'
            LEFT JOIN {$wpdb->postmeta} pm_reason ON p.ID = pm_reason.post_id 
                AND pm_reason.meta_key = '_sd_logo_rejection_reason'
            WHERE p.post_type = 'sd_membership'
            AND p.post_status = 'publish'
            {$status_condition}
            ORDER BY p.post_date DESC
        ", ARRAY_A );

        $logos = [];
        foreach ( $results as $row ) {
            $logo_id = (int) $row['logo_id'];
            $donor = $row['donor_id'] ? Entity_Hydrator::get( 'sd_donor', (int) $row['donor_id'] ) : null;
            
            $tier = $row['tier'] ?? '';
            $tiers = \Starter_Shelter\Core\Config::get_item( 'tiers', 'business', [] );
            $tier_data = $tiers[ $tier ] ?? null;

            $logos[] = [
                'membership_id'    => (int) $row['membership_id'],
                'logo_id'          => $logo_id,
                'logo_url'         => wp_get_attachment_image_url( $logo_id, 'medium' ),
                'logo_url_full'    => wp_get_attachment_image_url( $logo_id, 'full' ),
                'business_name'    => $row['business_name'] ?: __( 'Unnamed Business', 'starter-shelter' ),
                'tier'             => $tier,
                'tier_label'       => $tier_data['label'] ?? ucfirst( $tier ),
                'donor_id'         => (int) $row['donor_id'],
                'donor_name'       => $donor ? ( $donor['display_name'] ?? $donor['first_name'] . ' ' . $donor['last_name'] ) : '',
                'upload_date'      => $row['upload_date'],
                'logo_status'      => $row['logo_status'] ?: 'pending',
                'rejection_reason' => $row['rejection_reason'] ?? '',
            ];
        }

        return $logos;
    }

    /**
     * Get count of logos by status.
     *
     * @since 1.0.0
     *
     * @param string $status Status to count.
     * @return int Count.
     */
    private static function get_count_by_status( string $status ): int {
        global $wpdb;

        if ( 'pending' === $status ) {
            return self::get_pending_count();
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id 
                AND pm_type.meta_key = '_sd_membership_type' 
                AND pm_type.meta_value = 'business'
            JOIN {$wpdb->postmeta} pm_logo ON p.ID = pm_logo.post_id 
                AND pm_logo.meta_key = '_sd_logo_attachment_id' 
                AND pm_logo.meta_value > 0
            JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id 
                AND pm_status.meta_key = '_sd_logo_status'
                AND pm_status.meta_value = %s
            WHERE p.post_type = 'sd_membership'
            AND p.post_status = 'publish'
        ", $status ) );
    }

    /**
     * Handle AJAX logo approval.
     *
     * @since 1.0.0
     */
    public static function ajax_approve_logo(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'starter-shelter' ) ] );
        }

        $membership_id = absint( $_POST['membership_id'] ?? 0 );
        if ( ! $membership_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid membership ID.', 'starter-shelter' ) ] );
        }

        // Update logo status.
        update_post_meta( $membership_id, '_sd_logo_status', 'approved' );
        update_post_meta( $membership_id, '_sd_logo_approved_date', current_time( 'mysql' ) );
        update_post_meta( $membership_id, '_sd_logo_approved_by', get_current_user_id() );

        // Get membership data for email.
        $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
        $donor_id = $membership['donor_id'] ?? 0;

        // Trigger email notification.
        do_action( 'starter_shelter_logo_approved', $membership_id, $donor_id, $membership );

        wp_send_json_success( [
            'message' => __( 'Logo approved successfully.', 'starter-shelter' ),
        ] );
    }

    /**
     * Handle AJAX logo rejection.
     *
     * @since 1.0.0
     */
    public static function ajax_reject_logo(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'starter-shelter' ) ] );
        }

        $membership_id = absint( $_POST['membership_id'] ?? 0 );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $membership_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid membership ID.', 'starter-shelter' ) ] );
        }

        if ( ! $reason ) {
            wp_send_json_error( [ 'message' => __( 'Please select a rejection reason.', 'starter-shelter' ) ] );
        }

        // Build full rejection reason.
        $reason_labels = [
            'inappropriate' => __( 'Inappropriate content', 'starter-shelter' ),
            'low_quality'   => __( 'Low image quality', 'starter-shelter' ),
            'wrong_format'  => __( 'Wrong format or dimensions', 'starter-shelter' ),
            'copyright'     => __( 'Copyright concerns', 'starter-shelter' ),
            'other'         => __( 'Other', 'starter-shelter' ),
        ];
        
        $full_reason = $reason_labels[ $reason ] ?? $reason;
        if ( $notes ) {
            $full_reason .= ': ' . $notes;
        }

        // Update logo status.
        update_post_meta( $membership_id, '_sd_logo_status', 'rejected' );
        update_post_meta( $membership_id, '_sd_logo_rejection_reason', $full_reason );
        update_post_meta( $membership_id, '_sd_logo_rejected_date', current_time( 'mysql' ) );
        update_post_meta( $membership_id, '_sd_logo_rejected_by', get_current_user_id() );

        // Get membership data for email.
        $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
        $donor_id = $membership['donor_id'] ?? 0;

        // Trigger email notification.
        do_action( 'starter_shelter_logo_rejected', $membership_id, $donor_id, [
            'membership' => $membership,
            'reason'     => $full_reason,
        ] );

        wp_send_json_success( [
            'message' => __( 'Logo rejected.', 'starter-shelter' ),
        ] );
    }

    /**
     * Handle bulk logo actions.
     *
     * @since 1.0.0
     */
    public static function handle_bulk_action(): void {
        check_admin_referer( self::NONCE_ACTION );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $membership_ids = array_map( 'absint', $_POST['membership_ids'] ?? [] );
        $status_filter = sanitize_key( $_POST['status_filter'] ?? 'pending' );

        if ( empty( $action ) || empty( $membership_ids ) ) {
            wp_safe_redirect( add_query_arg( 'status', $status_filter, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }

        $processed = 0;

        foreach ( $membership_ids as $membership_id ) {
            if ( 'approve' === $action ) {
                update_post_meta( $membership_id, '_sd_logo_status', 'approved' );
                update_post_meta( $membership_id, '_sd_logo_approved_date', current_time( 'mysql' ) );
                update_post_meta( $membership_id, '_sd_logo_approved_by', get_current_user_id() );

                $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
                do_action( 'starter_shelter_logo_approved', $membership_id, $membership['donor_id'] ?? 0, $membership );
                
                $processed++;
            } elseif ( 'reject' === $action ) {
                update_post_meta( $membership_id, '_sd_logo_status', 'rejected' );
                update_post_meta( $membership_id, '_sd_logo_rejection_reason', __( 'Bulk rejection', 'starter-shelter' ) );
                update_post_meta( $membership_id, '_sd_logo_rejected_date', current_time( 'mysql' ) );
                update_post_meta( $membership_id, '_sd_logo_rejected_by', get_current_user_id() );

                $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
                do_action( 'starter_shelter_logo_rejected', $membership_id, $membership['donor_id'] ?? 0, [
                    'membership' => $membership,
                    'reason'     => __( 'Bulk rejection', 'starter-shelter' ),
                ] );
                
                $processed++;
            }
        }

        wp_safe_redirect( add_query_arg( [
            'status'    => $status_filter,
            'processed' => $processed,
            'action'    => $action,
        ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    /**
     * Get inline styles for logo moderation page.
     *
     * @since 1.0.0
     *
     * @return string CSS styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-logo-moderation .subsubsub { margin-bottom: 20px; }
            
            .sd-empty-state {
                text-align: center;
                padding: 60px 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .sd-empty-state .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #ccc;
                margin-bottom: 10px;
            }
            .sd-empty-state .dashicons-yes-alt { color: #00a32a; }
            
            .sd-logo-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            
            .sd-logo-card {
                position: relative;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                overflow: hidden;
                transition: box-shadow 0.2s;
            }
            .sd-logo-card:hover {
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            
            .sd-logo-checkbox {
                position: absolute;
                top: 10px;
                left: 10px;
                z-index: 10;
                background: #fff;
                border-radius: 3px;
                padding: 2px;
            }
            .sd-logo-checkbox input { margin: 0; }
            
            .sd-logo-preview {
                aspect-ratio: 16/9;
                background: #f0f0f1;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
            }
            .sd-logo-preview img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }
            
            .sd-logo-info {
                padding: 15px;
            }
            .sd-business-name {
                margin: 0 0 5px;
                font-size: 14px;
            }
            .sd-business-name a {
                text-decoration: none;
                color: #1d2327;
            }
            .sd-business-name a:hover { color: #2271b1; }
            
            .sd-membership-tier,
            .sd-upload-date {
                margin: 0;
                font-size: 12px;
                color: #646970;
            }
            
            .sd-rejection-reason {
                margin: 10px 0 0;
                padding: 8px;
                background: #fcf0f1;
                border-radius: 3px;
                font-size: 12px;
                color: #8a2424;
            }
            
            .sd-logo-actions {
                padding: 10px 15px 15px;
                display: flex;
                gap: 8px;
            }
            .sd-logo-actions .button {
                flex: 1;
                text-align: center;
                justify-content: center;
            }
            .sd-logo-actions .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                margin-right: 3px;
                vertical-align: text-bottom;
            }
            
            .sd-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .sd-status--approved {
                background: #d1fae5;
                color: #065f46;
            }
            .sd-status--rejected {
                background: #fee2e2;
                color: #991b1b;
            }
            
            /* Modal */
            .sd-modal {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.5);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sd-modal-content {
                background: #fff;
                padding: 20px;
                border-radius: 4px;
                width: 100%;
                max-width: 400px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            }
            .sd-modal-content h2 {
                margin-top: 0;
            }
            .sd-modal-content select,
            .sd-modal-content textarea {
                margin-bottom: 15px;
            }
            .sd-modal-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
            }
        ';
    }
}
