<?php
/**
 * Admin Quick Actions - Handles admin row actions and bulk operations.
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
 * Handles quick actions from CPT list tables.
 *
 * @since 1.0.0
 */
class Quick_Actions {

    /**
     * Initialize quick actions.
     */
    public static function init(): void {
        // Admin post handlers for row actions.
        add_action( 'admin_post_sd_send_reminder', [ self::class, 'handle_send_reminder' ] );
        add_action( 'admin_post_sd_extend_membership', [ self::class, 'handle_extend_membership' ] );
        add_action( 'admin_post_sd_notify_family', [ self::class, 'handle_notify_family' ] );
        add_action( 'admin_post_sd_send_statement', [ self::class, 'handle_send_statement' ] );
        add_action( 'admin_post_sd_resend_receipt', [ self::class, 'handle_resend_receipt' ] );

        // AJAX handlers.
        add_action( 'wp_ajax_sd_view_receipt', [ self::class, 'ajax_view_receipt' ] );
        add_action( 'wp_ajax_sd_quick_stats', [ self::class, 'ajax_quick_stats' ] );

        // Bulk action handlers.
        add_filter( 'bulk_actions-edit-sd_membership', [ self::class, 'register_membership_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-sd_membership', [ self::class, 'handle_membership_bulk_actions' ], 10, 3 );
        
        add_filter( 'bulk_actions-edit-sd_memorial', [ self::class, 'register_memorial_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-sd_memorial', [ self::class, 'handle_memorial_bulk_actions' ], 10, 3 );

        add_filter( 'bulk_actions-edit-sd_donation', [ self::class, 'register_donation_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-sd_donation', [ self::class, 'handle_donation_bulk_actions' ], 10, 3 );

        // Admin notices for action results.
        add_action( 'admin_notices', [ self::class, 'display_action_notices' ] );

        // Add filters for CPT list tables.
        add_action( 'restrict_manage_posts', [ self::class, 'add_list_filters' ] );
        add_action( 'pre_get_posts', [ self::class, 'apply_list_filters' ] );
    }

    /**
     * Handle sending renewal reminder.
     */
    public static function handle_send_reminder(): void {
        $membership_id = absint( $_GET['id'] ?? 0 );
        
        check_admin_referer( 'sd_send_reminder_' . $membership_id );

        if ( ! current_user_can( 'manage_options' ) || ! $membership_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
        if ( ! $membership ) {
            wp_die( __( 'Membership not found.', 'starter-shelter' ) );
        }

        $donor_id = $membership['donor_id'] ?? 0;

        // Trigger the renewal reminder email.
        do_action( 'starter_shelter_membership_renewal_reminder', $membership_id, $donor_id, $membership );

        // Track that reminder was sent.
        update_post_meta( $membership_id, '_sd_reminder_sent_date', current_time( 'mysql' ) );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'sd_membership',
            'sd_action' => 'reminder_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle extending membership by 30 days.
     */
    public static function handle_extend_membership(): void {
        $membership_id = absint( $_GET['id'] ?? 0 );
        
        check_admin_referer( 'sd_extend_' . $membership_id );

        if ( ! current_user_can( 'manage_options' ) || ! $membership_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $end_date = get_post_meta( $membership_id, '_sd_end_date', true );
        
        if ( $end_date ) {
            // Extend from current end date or today, whichever is later.
            $base_date = max( strtotime( $end_date ), time() );
            $new_end_date = wp_date( 'Y-m-d', strtotime( '+30 days', $base_date ) );
        } else {
            $new_end_date = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
        }

        update_post_meta( $membership_id, '_sd_end_date', $new_end_date );

        // Add note.
        $note = sprintf(
            /* translators: 1: new end date, 2: admin user */
            __( 'Membership extended to %1$s by %2$s', 'starter-shelter' ),
            Helpers\format_date( $new_end_date ),
            wp_get_current_user()->display_name
        );
        add_post_meta( $membership_id, '_sd_admin_notes', [
            'date' => current_time( 'mysql' ),
            'note' => $note,
            'user' => get_current_user_id(),
        ] );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'sd_membership',
            'sd_action' => 'extended',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle sending family notification for memorial.
     */
    public static function handle_notify_family(): void {
        $memorial_id = absint( $_GET['id'] ?? 0 );
        
        check_admin_referer( 'sd_notify_family_' . $memorial_id );

        if ( ! current_user_can( 'manage_options' ) || ! $memorial_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $memorial = Entity_Hydrator::get( 'sd_memorial', $memorial_id );
        if ( ! $memorial ) {
            wp_die( __( 'Memorial not found.', 'starter-shelter' ) );
        }

        // Check if notification is enabled.
        $notify_family = $memorial['notify_family'] ?? [];
        if ( empty( $notify_family['enabled'] ) || empty( $notify_family['email'] ) ) {
            wp_die( __( 'Family notification not configured for this memorial.', 'starter-shelter' ) );
        }

        $donor_id = $memorial['donor_id'] ?? 0;

        // Trigger the family notification email.
        do_action( 'starter_shelter_memorial_family_notification', $memorial_id, $donor_id, $memorial );

        // Track that notification was sent.
        update_post_meta( $memorial_id, '_sd_family_notified_date', current_time( 'mysql' ) );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'sd_memorial',
            'sd_action' => 'family_notified',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle sending annual statement to donor.
     */
    public static function handle_send_statement(): void {
        $donor_id = absint( $_GET['id'] ?? 0 );
        
        check_admin_referer( 'sd_send_statement_' . $donor_id );

        if ( ! current_user_can( 'manage_options' ) || ! $donor_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
        if ( ! $donor ) {
            wp_die( __( 'Donor not found.', 'starter-shelter' ) );
        }

        // Get current year's donations.
        $year = (int) wp_date( 'Y' );

        // Trigger the annual summary email.
        do_action( 'starter_shelter_donor_annual_summary', $donor_id, [
            'donor' => $donor,
            'year'  => $year,
        ] );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'sd_donor',
            'sd_action' => 'statement_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handle resending donation receipt.
     */
    public static function handle_resend_receipt(): void {
        $donation_id = absint( $_GET['id'] ?? 0 );
        
        check_admin_referer( 'sd_resend_receipt_' . $donation_id );

        if ( ! current_user_can( 'manage_options' ) || ! $donation_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $donation = Entity_Hydrator::get( 'sd_donation', $donation_id );
        if ( ! $donation ) {
            wp_die( __( 'Donation not found.', 'starter-shelter' ) );
        }

        $donor_id = $donation['donor_id'] ?? 0;

        // Trigger the donation receipt email.
        do_action( 'starter_shelter_donation_receipt_resend', $donation_id, $donor_id, $donation );

        wp_safe_redirect( add_query_arg( [
            'post_type' => 'sd_donation',
            'sd_action' => 'receipt_sent',
            'sd_count'  => 1,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * AJAX handler for viewing donation receipt.
     */
    public static function ajax_view_receipt(): void {
        $donation_id = absint( $_GET['id'] ?? 0 );
        
        check_ajax_referer( 'sd_view_receipt_' . $donation_id, '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) || ! $donation_id ) {
            wp_die( __( 'Invalid request.', 'starter-shelter' ) );
        }

        $donation = Entity_Hydrator::get( 'sd_donation', $donation_id );
        if ( ! $donation ) {
            wp_die( __( 'Donation not found.', 'starter-shelter' ) );
        }

        $donor = $donation['donor_id'] ? Entity_Hydrator::get( 'sd_donor', $donation['donor_id'] ) : null;

        // Render receipt preview.
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php esc_html_e( 'Donation Receipt', 'starter-shelter' ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; }
                .receipt-header { text-align: center; margin-bottom: 30px; }
                .receipt-header h1 { margin: 0 0 10px; font-size: 24px; }
                .receipt-details { background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .receipt-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e7eb; }
                .receipt-row:last-child { border-bottom: none; }
                .receipt-amount { font-size: 32px; font-weight: bold; color: #059669; text-align: center; margin: 20px 0; }
                .receipt-footer { text-align: center; color: #6b7280; font-size: 14px; margin-top: 30px; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <h1><?php echo esc_html( Settings::get( 'org_name', get_bloginfo( 'name' ) ) ); ?></h1>
                <p><?php esc_html_e( 'Donation Receipt', 'starter-shelter' ); ?></p>
            </div>

            <div class="receipt-amount">
                <?php echo esc_html( Helpers\format_currency( $donation['amount'] ?? 0 ) ); ?>
            </div>

            <div class="receipt-details">
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Receipt #', 'starter-shelter' ); ?></span>
                    <strong><?php echo esc_html( $donation_id ); ?></strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Date', 'starter-shelter' ); ?></span>
                    <strong><?php echo esc_html( Helpers\format_date( $donation['donation_date'] ?? '' ) ); ?></strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Donor', 'starter-shelter' ); ?></span>
                    <strong>
                        <?php 
                        if ( $donation['is_anonymous'] ?? false ) {
                            esc_html_e( 'Anonymous', 'starter-shelter' );
                        } else {
                            echo esc_html( $donor['display_name'] ?? $donor['first_name'] . ' ' . $donor['last_name'] ?? 'N/A' );
                        }
                        ?>
                    </strong>
                </div>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Allocation', 'starter-shelter' ); ?></span>
                    <strong><?php echo esc_html( Helpers\get_allocation_label( $donation['allocation'] ?? '' ) ); ?></strong>
                </div>
                <?php if ( ! empty( $donation['dedication'] ) ) : ?>
                <div class="receipt-row">
                    <span><?php esc_html_e( 'Dedication', 'starter-shelter' ); ?></span>
                    <strong><?php echo esc_html( $donation['dedication'] ); ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <div class="receipt-footer">
                <?php 
                $ein = Settings::get( 'org_ein', '' );
                if ( $ein ) {
                    printf(
                        /* translators: %s: EIN number */
                        esc_html__( 'Tax ID (EIN): %s', 'starter-shelter' ),
                        esc_html( $ein )
                    );
                    echo '<br>';
                }
                ?>
                <?php esc_html_e( 'Thank you for your generous support!', 'starter-shelter' ); ?>
                <br><br>
                <button onclick="window.print();" style="padding: 10px 20px; cursor: pointer;">
                    <?php esc_html_e( 'Print Receipt', 'starter-shelter' ); ?>
                </button>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Register bulk actions for memberships.
     */
    public static function register_membership_bulk_actions( array $actions ): array {
        $actions['sd_send_reminders'] = __( 'Send Renewal Reminders', 'starter-shelter' );
        $actions['sd_extend_30_days'] = __( 'Extend 30 Days', 'starter-shelter' );
        $actions['sd_export_selected'] = __( 'Export Selected', 'starter-shelter' );
        return $actions;
    }

    /**
     * Handle bulk actions for memberships.
     */
    public static function handle_membership_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( ! in_array( $action, [ 'sd_send_reminders', 'sd_extend_30_days', 'sd_export_selected' ], true ) ) {
            return $redirect_url;
        }

        $count = 0;

        switch ( $action ) {
            case 'sd_send_reminders':
                foreach ( $post_ids as $membership_id ) {
                    $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
                    if ( $membership ) {
                        do_action( 'starter_shelter_membership_renewal_reminder', $membership_id, $membership['donor_id'] ?? 0, $membership );
                        update_post_meta( $membership_id, '_sd_reminder_sent_date', current_time( 'mysql' ) );
                        $count++;
                    }
                }
                return add_query_arg( [ 'sd_action' => 'reminders_sent', 'sd_count' => $count ], $redirect_url );

            case 'sd_extend_30_days':
                foreach ( $post_ids as $membership_id ) {
                    $end_date = get_post_meta( $membership_id, '_sd_end_date', true );
                    $base_date = $end_date ? max( strtotime( $end_date ), time() ) : time();
                    $new_end_date = wp_date( 'Y-m-d', strtotime( '+30 days', $base_date ) );
                    update_post_meta( $membership_id, '_sd_end_date', $new_end_date );
                    $count++;
                }
                return add_query_arg( [ 'sd_action' => 'memberships_extended', 'sd_count' => $count ], $redirect_url );

            case 'sd_export_selected':
                // Store IDs in transient and redirect to export.
                set_transient( 'sd_export_membership_ids_' . get_current_user_id(), $post_ids, HOUR_IN_SECONDS );
                return admin_url( 'admin-ajax.php?action=sd_export_selected_memberships&_wpnonce=' . wp_create_nonce( 'sd_export_memberships' ) );
        }

        return $redirect_url;
    }

    /**
     * Register bulk actions for memorials.
     */
    public static function register_memorial_bulk_actions( array $actions ): array {
        $actions['sd_notify_families'] = __( 'Send Family Notifications', 'starter-shelter' );
        $actions['sd_export_selected'] = __( 'Export Selected', 'starter-shelter' );
        return $actions;
    }

    /**
     * Handle bulk actions for memorials.
     */
    public static function handle_memorial_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( 'sd_notify_families' !== $action ) {
            return $redirect_url;
        }

        $count = 0;

        foreach ( $post_ids as $memorial_id ) {
            $memorial = Entity_Hydrator::get( 'sd_memorial', $memorial_id );
            $notify_family = $memorial['notify_family'] ?? [];
            $already_sent = get_post_meta( $memorial_id, '_sd_family_notified_date', true );

            if ( ! empty( $notify_family['enabled'] ) && ! empty( $notify_family['email'] ) && ! $already_sent ) {
                do_action( 'starter_shelter_memorial_family_notification', $memorial_id, $memorial['donor_id'] ?? 0, $memorial );
                update_post_meta( $memorial_id, '_sd_family_notified_date', current_time( 'mysql' ) );
                $count++;
            }
        }

        return add_query_arg( [ 'sd_action' => 'families_notified', 'sd_count' => $count ], $redirect_url );
    }

    /**
     * Register bulk actions for donations.
     */
    public static function register_donation_bulk_actions( array $actions ): array {
        $actions['sd_resend_receipts'] = __( 'Resend Receipts', 'starter-shelter' );
        $actions['sd_export_selected'] = __( 'Export Selected', 'starter-shelter' );
        return $actions;
    }

    /**
     * Handle bulk actions for donations.
     */
    public static function handle_donation_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
        if ( 'sd_resend_receipts' !== $action ) {
            return $redirect_url;
        }

        $count = 0;

        foreach ( $post_ids as $donation_id ) {
            $donation = Entity_Hydrator::get( 'sd_donation', $donation_id );
            if ( $donation ) {
                do_action( 'starter_shelter_donation_receipt_resend', $donation_id, $donation['donor_id'] ?? 0, $donation );
                $count++;
            }
        }

        return add_query_arg( [ 'sd_action' => 'receipts_sent', 'sd_count' => $count ], $redirect_url );
    }

    /**
     * Display admin notices for completed actions.
     */
    public static function display_action_notices(): void {
        $action = sanitize_key( $_GET['sd_action'] ?? '' );
        $count = absint( $_GET['sd_count'] ?? 0 );

        if ( ! $action || ! $count ) {
            return;
        }

        $messages = [
            'reminder_sent'       => _n( 'Renewal reminder sent.', '%d renewal reminders sent.', $count, 'starter-shelter' ),
            'reminders_sent'      => _n( '%d renewal reminder sent.', '%d renewal reminders sent.', $count, 'starter-shelter' ),
            'extended'            => _n( 'Membership extended by 30 days.', '%d memberships extended by 30 days.', $count, 'starter-shelter' ),
            'memberships_extended'=> _n( '%d membership extended.', '%d memberships extended.', $count, 'starter-shelter' ),
            'family_notified'     => __( 'Family notification sent.', 'starter-shelter' ),
            'families_notified'   => _n( '%d family notification sent.', '%d family notifications sent.', $count, 'starter-shelter' ),
            'statement_sent'      => __( 'Annual statement sent.', 'starter-shelter' ),
            'receipt_sent'        => __( 'Donation receipt sent.', 'starter-shelter' ),
            'receipts_sent'       => _n( '%d receipt sent.', '%d receipts sent.', $count, 'starter-shelter' ),
        ];

        if ( isset( $messages[ $action ] ) ) {
            $message = sprintf( $messages[ $action ], $count );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }

    /**
     * Add filter dropdowns to CPT list tables.
     */
    public static function add_list_filters( string $post_type ): void {
        switch ( $post_type ) {
            case 'sd_membership':
                self::render_membership_filters();
                break;
            case 'sd_memorial':
                self::render_memorial_filters();
                break;
            case 'sd_donation':
                self::render_donation_filters();
                break;
            case 'sd_donor':
                self::render_donor_filters();
                break;
        }
    }

    /**
     * Render membership list filters.
     */
    private static function render_membership_filters(): void {
        $status = sanitize_key( $_GET['membership_status'] ?? '' );
        $tier = sanitize_key( $_GET['membership_tier'] ?? '' );
        $type = sanitize_key( $_GET['membership_type'] ?? '' );

        ?>
        <select name="membership_status">
            <option value=""><?php esc_html_e( 'All Statuses', 'starter-shelter' ); ?></option>
            <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'starter-shelter' ); ?></option>
            <option value="expiring" <?php selected( $status, 'expiring' ); ?>><?php esc_html_e( 'Expiring Soon (30 days)', 'starter-shelter' ); ?></option>
            <option value="expired" <?php selected( $status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'starter-shelter' ); ?></option>
        </select>

        <select name="membership_type">
            <option value=""><?php esc_html_e( 'All Types', 'starter-shelter' ); ?></option>
            <option value="individual" <?php selected( $type, 'individual' ); ?>><?php esc_html_e( 'Individual', 'starter-shelter' ); ?></option>
            <option value="family" <?php selected( $type, 'family' ); ?>><?php esc_html_e( 'Family', 'starter-shelter' ); ?></option>
            <option value="business" <?php selected( $type, 'business' ); ?>><?php esc_html_e( 'Business', 'starter-shelter' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render memorial list filters.
     */
    private static function render_memorial_filters(): void {
        $type = sanitize_key( $_GET['memorial_type'] ?? '' );
        $notify = sanitize_key( $_GET['notify_status'] ?? '' );

        ?>
        <select name="memorial_type">
            <option value=""><?php esc_html_e( 'All Types', 'starter-shelter' ); ?></option>
            <option value="human" <?php selected( $type, 'human' ); ?>><?php esc_html_e( 'Person', 'starter-shelter' ); ?></option>
            <option value="pet" <?php selected( $type, 'pet' ); ?>><?php esc_html_e( 'Pet', 'starter-shelter' ); ?></option>
            <option value="honor" <?php selected( $type, 'honor' ); ?>><?php esc_html_e( 'In Honor Of', 'starter-shelter' ); ?></option>
        </select>

        <select name="notify_status">
            <option value=""><?php esc_html_e( 'All Notifications', 'starter-shelter' ); ?></option>
            <option value="pending" <?php selected( $notify, 'pending' ); ?>><?php esc_html_e( 'Pending', 'starter-shelter' ); ?></option>
            <option value="sent" <?php selected( $notify, 'sent' ); ?>><?php esc_html_e( 'Sent', 'starter-shelter' ); ?></option>
            <option value="not_requested" <?php selected( $notify, 'not_requested' ); ?>><?php esc_html_e( 'Not Requested', 'starter-shelter' ); ?></option>
        </select>
        <?php
    }

    /**
     * Render donation list filters.
     */
    private static function render_donation_filters(): void {
        $allocation = sanitize_key( $_GET['allocation'] ?? '' );
        $allocations = \Starter_Shelter\Core\Config::get_item( 'settings', 'allocations', [] );

        ?>
        <select name="allocation">
            <option value=""><?php esc_html_e( 'All Allocations', 'starter-shelter' ); ?></option>
            <?php foreach ( $allocations as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $allocation, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render donor list filters.
     */
    private static function render_donor_filters(): void {
        $level = sanitize_key( $_GET['donor_level'] ?? '' );
        $has_membership = sanitize_key( $_GET['has_membership'] ?? '' );

        ?>
        <select name="donor_level">
            <option value=""><?php esc_html_e( 'All Levels', 'starter-shelter' ); ?></option>
            <option value="new" <?php selected( $level, 'new' ); ?>><?php esc_html_e( 'New', 'starter-shelter' ); ?></option>
            <option value="bronze" <?php selected( $level, 'bronze' ); ?>><?php esc_html_e( 'Bronze', 'starter-shelter' ); ?></option>
            <option value="silver" <?php selected( $level, 'silver' ); ?>><?php esc_html_e( 'Silver', 'starter-shelter' ); ?></option>
            <option value="gold" <?php selected( $level, 'gold' ); ?>><?php esc_html_e( 'Gold', 'starter-shelter' ); ?></option>
            <option value="platinum" <?php selected( $level, 'platinum' ); ?>><?php esc_html_e( 'Platinum', 'starter-shelter' ); ?></option>
        </select>

        <select name="has_membership">
            <option value=""><?php esc_html_e( 'All Donors', 'starter-shelter' ); ?></option>
            <option value="yes" <?php selected( $has_membership, 'yes' ); ?>><?php esc_html_e( 'With Membership', 'starter-shelter' ); ?></option>
            <option value="no" <?php selected( $has_membership, 'no' ); ?>><?php esc_html_e( 'Without Membership', 'starter-shelter' ); ?></option>
        </select>
        <?php
    }

    /**
     * Apply list filters to query.
     */
    public static function apply_list_filters( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        switch ( $post_type ) {
            case 'sd_membership':
                self::apply_membership_filters( $query );
                break;
            case 'sd_memorial':
                self::apply_memorial_filters( $query );
                break;
            case 'sd_donation':
                self::apply_donation_filters( $query );
                break;
            case 'sd_donor':
                self::apply_donor_filters( $query );
                break;
        }
    }

    /**
     * Apply membership filters.
     */
    private static function apply_membership_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Status filter.
        $status = sanitize_key( $_GET['membership_status'] ?? '' );
        if ( $status ) {
            $today = wp_date( 'Y-m-d' );
            $in_30_days = wp_date( 'Y-m-d', strtotime( '+30 days' ) );

            switch ( $status ) {
                case 'active':
                    $meta_query[] = [ 'key' => '_sd_end_date', 'value' => $today, 'compare' => '>=' ];
                    break;
                case 'expiring':
                    $meta_query[] = [ 'key' => '_sd_end_date', 'value' => [ $today, $in_30_days ], 'compare' => 'BETWEEN' ];
                    break;
                case 'expired':
                    $meta_query[] = [ 'key' => '_sd_end_date', 'value' => $today, 'compare' => '<' ];
                    break;
            }
        }

        // Type filter.
        $type = sanitize_key( $_GET['membership_type'] ?? '' );
        if ( $type ) {
            $meta_query[] = [ 'key' => '_sd_membership_type', 'value' => $type ];
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply memorial filters.
     */
    private static function apply_memorial_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Type filter.
        $type = sanitize_key( $_GET['memorial_type'] ?? '' );
        if ( $type ) {
            $meta_query[] = [ 'key' => '_sd_memorial_type', 'value' => $type ];
        }

        // Notification status filter.
        $notify = sanitize_key( $_GET['notify_status'] ?? '' );
        if ( $notify ) {
            switch ( $notify ) {
                case 'pending':
                    $meta_query[] = [ 'key' => '_sd_notify_family_enabled', 'value' => '1' ];
                    $meta_query[] = [
                        'relation' => 'OR',
                        [ 'key' => '_sd_family_notified_date', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => '_sd_family_notified_date', 'value' => '' ],
                    ];
                    break;
                case 'sent':
                    $meta_query[] = [ 'key' => '_sd_family_notified_date', 'compare' => 'EXISTS' ];
                    $meta_query[] = [ 'key' => '_sd_family_notified_date', 'value' => '', 'compare' => '!=' ];
                    break;
                case 'not_requested':
                    $meta_query[] = [
                        'relation' => 'OR',
                        [ 'key' => '_sd_notify_family_enabled', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => '_sd_notify_family_enabled', 'value' => '0' ],
                    ];
                    break;
            }
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply donation filters.
     */
    private static function apply_donation_filters( \WP_Query $query ): void {
        $allocation = sanitize_key( $_GET['allocation'] ?? '' );
        
        if ( $allocation ) {
            $meta_query = $query->get( 'meta_query' ) ?: [];
            $meta_query[] = [ 'key' => '_sd_allocation', 'value' => $allocation ];
            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Apply donor filters.
     */
    private static function apply_donor_filters( \WP_Query $query ): void {
        $meta_query = $query->get( 'meta_query' ) ?: [];

        // Level filter.
        $level = sanitize_key( $_GET['donor_level'] ?? '' );
        if ( $level ) {
            $meta_query[] = [ 'key' => '_sd_donor_level', 'value' => $level ];
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }
}
