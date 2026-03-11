<?php
/**
 * Renewal Reminder - Cron job for membership renewal emails.
 *
 * @package Starter_Shelter
 * @subpackage Cron
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Cron;

use Starter_Shelter\Admin\Settings;
use Starter_Shelter\Core\Query;

/**
 * Handles scheduled membership renewal reminder emails.
 *
 * @since 1.0.0
 */
class Renewal_Reminder {

    /**
     * Cron hook name.
     *
     * @since 1.0.0
     * @var string
     */
    private const CRON_HOOK = 'starter_shelter_renewal_reminders';

    /**
     * Meta key to track sent reminders.
     *
     * @since 1.0.0
     * @var string
     */
    private const REMINDER_SENT_META = '_sd_renewal_reminder_sent';

    /**
     * Initialize the cron handler.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        // Register the cron action.
        add_action( self::CRON_HOOK, [ self::class, 'process_reminders' ] );

        // Schedule the cron if not already scheduled.
        add_action( 'init', [ self::class, 'schedule_cron' ] );

        // Clean up on deactivation.
        register_deactivation_hook( STARTER_SHELTER_FILE, [ self::class, 'unschedule_cron' ] );
    }

    /**
     * Schedule the daily cron job.
     *
     * @since 1.0.0
     */
    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Schedule for 9 AM local time.
            $timestamp = strtotime( 'today 9:00 AM', current_time( 'timestamp' ) );
            
            // If 9 AM has already passed today, schedule for tomorrow.
            if ( $timestamp < current_time( 'timestamp' ) ) {
                $timestamp = strtotime( 'tomorrow 9:00 AM', current_time( 'timestamp' ) );
            }

            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the cron job.
     *
     * @since 1.0.0
     */
    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Process renewal reminders.
     *
     * @since 1.0.0
     */
    public static function process_reminders(): void {
        // Check if feature is enabled.
        if ( ! Settings::is_feature_enabled( 'renewal_reminders' ) ) {
            return;
        }

        // Get reminder days setting.
        $reminder_days = Settings::get( 'renewal_reminder_days', 30 );

        // Calculate the target date.
        $target_date = wp_date( 'Y-m-d', strtotime( "+{$reminder_days} days" ) );

        // Query memberships expiring on the target date.
        $expiring_memberships = self::get_expiring_memberships( $target_date );

        foreach ( $expiring_memberships as $membership ) {
            self::send_reminder( $membership );
        }

        /**
         * Fires after renewal reminders have been processed.
         *
         * @since 1.0.0
         *
         * @param int    $count       Number of reminders sent.
         * @param string $target_date The target expiration date.
         */
        do_action( 'starter_shelter_renewal_reminders_processed', count( $expiring_memberships ), $target_date );
    }

    /**
     * Get memberships expiring on a specific date.
     *
     * @since 1.0.0
     *
     * @param string $target_date The target expiration date (Y-m-d).
     * @return array Array of membership data.
     */
    private static function get_expiring_memberships( string $target_date ): array {
        return Query::for( 'sd_membership' )
            ->where( 'end_date', $target_date )
            ->whereCompare( 'status', 'cancelled', '!=' )
            ->whereNotExists( self::REMINDER_SENT_META )
            ->get();
    }

    /**
     * Send a renewal reminder for a membership.
     *
     * @since 1.0.0
     *
     * @param array $membership The membership data.
     */
    private static function send_reminder( array $membership ): void {
        $membership_id = $membership['id'] ?? 0;
        $donor_id      = $membership['donor_id'] ?? 0;

        if ( ! $membership_id || ! $donor_id ) {
            return;
        }

        // Check if reminder was already sent (double-check).
        $already_sent = get_post_meta( $membership_id, self::REMINDER_SENT_META, true );
        
        if ( $already_sent ) {
            return;
        }

        // Check donor's email preferences (if implemented).
        $donor_prefs = get_post_meta( $donor_id, '_sd_email_preferences', true );
        
        if ( is_array( $donor_prefs ) && isset( $donor_prefs['renewal_reminders'] ) && ! $donor_prefs['renewal_reminders'] ) {
            // Donor has opted out of renewal reminders.
            return;
        }

        /**
         * Fires to send a membership expiring reminder email.
         *
         * This hook is listened to by the Config_Email class which sends
         * the "membership-renewal" email.
         *
         * @since 1.0.0
         *
         * @param int $membership_id The membership post ID.
         * @param int $donor_id      The donor post ID.
         */
        do_action( 'starter_shelter_membership_expiring', $membership_id, $donor_id );

        // Mark reminder as sent.
        update_post_meta( $membership_id, self::REMINDER_SENT_META, wp_date( 'Y-m-d H:i:s' ) );

        /**
         * Fires after a renewal reminder has been sent.
         *
         * @since 1.0.0
         *
         * @param int   $membership_id The membership post ID.
         * @param int   $donor_id      The donor post ID.
         * @param array $membership    The membership data.
         */
        do_action( 'starter_shelter_renewal_reminder_sent', $membership_id, $donor_id, $membership );
    }

    /**
     * Manually trigger a reminder for a specific membership.
     *
     * @since 1.0.0
     *
     * @param int  $membership_id The membership post ID.
     * @param bool $force         Whether to force send even if already sent.
     * @return bool True if reminder was sent.
     */
    public static function send_manual_reminder( int $membership_id, bool $force = false ): bool {
        // Get membership data.
        $membership = \Starter_Shelter\Core\Entity_Hydrator::get( 'sd_membership', $membership_id );
        
        if ( ! $membership ) {
            return false;
        }

        // Check if already sent (unless forcing).
        if ( ! $force ) {
            $already_sent = get_post_meta( $membership_id, self::REMINDER_SENT_META, true );
            
            if ( $already_sent ) {
                return false;
            }
        }

        // Clear the sent flag if forcing.
        if ( $force ) {
            delete_post_meta( $membership_id, self::REMINDER_SENT_META );
        }

        self::send_reminder( $membership );
        
        return true;
    }

    /**
     * Reset the reminder sent flag for a membership.
     *
     * Useful when a membership is renewed but the user wants another reminder.
     *
     * @since 1.0.0
     *
     * @param int $membership_id The membership post ID.
     */
    public static function reset_reminder_flag( int $membership_id ): void {
        delete_post_meta( $membership_id, self::REMINDER_SENT_META );
    }

    /**
     * Check if a reminder has been sent for a membership.
     *
     * @since 1.0.0
     *
     * @param int $membership_id The membership post ID.
     * @return bool True if reminder was sent.
     */
    public static function was_reminder_sent( int $membership_id ): bool {
        return (bool) get_post_meta( $membership_id, self::REMINDER_SENT_META, true );
    }

    /**
     * Get the date a reminder was sent.
     *
     * @since 1.0.0
     *
     * @param int $membership_id The membership post ID.
     * @return string|null The date/time or null if not sent.
     */
    public static function get_reminder_sent_date( int $membership_id ): ?string {
        $sent = get_post_meta( $membership_id, self::REMINDER_SENT_META, true );
        
        return $sent ?: null;
    }
}
