<?php
/**
 * Email Factory - Config-driven WooCommerce email registration.
 *
 * @package Starter_Shelter
 * @subpackage Emails
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Emails;

use Starter_Shelter\Core\Config;

/**
 * Registers WooCommerce emails from configuration.
 *
 * @since 1.0.0
 */
class Email_Factory {

    /**
     * Cached email configurations.
     *
     * @since 1.0.0
     * @var array
     */
    private static array $email_configs = [];

    /**
     * Initialize the email factory.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        self::$email_configs = Config::get_item( 'emails', 'emails', [] );

        // Register emails with WooCommerce.
        add_filter( 'woocommerce_email_classes', [ self::class, 'register_emails' ] );

        // Add email settings section.
        add_filter( 'woocommerce_get_sections_email', [ self::class, 'add_email_section' ] );
    }

    /**
     * Register all configured emails with WooCommerce.
     *
     * @since 1.0.0
     *
     * @param array $emails Existing email classes.
     * @return array Modified email classes.
     */
    public static function register_emails( array $emails ): array {
        // Load the Config_Email class now that WC_Email is available.
        if ( ! class_exists( 'Starter_Shelter\\Emails\\Config_Email' ) ) {
            require_once STARTER_SHELTER_PATH . 'includes/emails/class-config-email.php';
        }

        foreach ( self::$email_configs as $email_id => $config ) {
            $class_key = 'SD_Email_' . self::to_class_name( $email_id );
            $emails[ $class_key ] = new Config_Email( $email_id, $config );
        }

        return $emails;
    }

    /**
     * Add shelter donations email section.
     *
     * @since 1.0.0
     *
     * @param array $sections Email sections.
     * @return array Modified sections.
     */
    public static function add_email_section( array $sections ): array {
        $sections['shelter_donations'] = __( 'Shelter Donations', 'starter-shelter' );
        return $sections;
    }

    /**
     * Get an email instance by ID.
     *
     * @since 1.0.0
     *
     * @param string $email_id The email ID.
     * @return Config_Email|null The email instance or null.
     */
    public static function get_email( string $email_id ): ?Config_Email {
        if ( ! isset( self::$email_configs[ $email_id ] ) ) {
            return null;
        }

        $emails = WC()->mailer()->get_emails();
        $class_key = 'SD_Email_' . self::to_class_name( $email_id );

        return $emails[ $class_key ] ?? null;
    }

    /**
     * Manually trigger an email.
     *
     * @since 1.0.0
     *
     * @param string $email_id The email ID.
     * @param array  $args     Trigger arguments.
     * @return bool True if email was sent.
     */
    public static function trigger_email( string $email_id, array $args ): bool {
        $email = self::get_email( $email_id );

        if ( ! $email ) {
            return false;
        }

        return $email->manual_trigger( $args );
    }

    /**
     * Convert email ID to class name format.
     *
     * @since 1.0.0
     *
     * @param string $email_id The email ID (e.g., "donation-receipt").
     * @return string Class name format (e.g., "Donation_Receipt").
     */
    private static function to_class_name( string $email_id ): string {
        return str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $email_id ) ) );
    }

    /**
     * Get all registered email IDs.
     *
     * @since 1.0.0
     *
     * @return array Array of email IDs.
     */
    public static function get_email_ids(): array {
        return array_keys( self::$email_configs );
    }

    /**
     * Check if an email is enabled.
     *
     * @since 1.0.0
     *
     * @param string $email_id The email ID.
     * @return bool True if enabled.
     */
    public static function is_email_enabled( string $email_id ): bool {
        $email = self::get_email( $email_id );
        return $email && $email->is_enabled();
    }
}
