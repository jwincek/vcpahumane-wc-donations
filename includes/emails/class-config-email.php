<?php
/**
 * Config Email - WooCommerce email class driven by configuration.
 *
 * @package Starter_Shelter
 * @subpackage Emails
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Emails;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WC_Email;

/**
 * Generic WooCommerce email class that uses configuration for behavior.
 *
 * @since 1.0.0
 */
class Config_Email extends WC_Email {

    /**
     * Email ID (slug from config).
     *
     * @since 1.0.0
     * @var string
     */
    protected string $email_id;

    /**
     * Email configuration from JSON.
     *
     * @since 1.0.0
     * @var array
     */
    protected array $config;

    /**
     * Hydrated entity data for template.
     *
     * @since 1.0.0
     * @var array
     */
    protected array $entity_data = [];

    /**
     * Raw trigger arguments.
     *
     * @since 1.0.0
     * @var array
     */
    protected array $trigger_args = [];

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param string $email_id The email ID.
     * @param array  $config   The email configuration.
     */
    public function __construct( string $email_id, array $config ) {
        $this->email_id = $email_id;
        $this->config = $config;

        // Set WC_Email properties.
        $this->id = 'sd_' . str_replace( '-', '_', $email_id );
        $this->title = $config['title'] ?? ucwords( str_replace( '-', ' ', $email_id ) );
        $this->description = $config['description'] ?? '';

        // Template paths.
        $this->template_html = $config['template'] ?? "emails/{$email_id}.php";
        $this->template_plain = str_replace( '.php', '-plain.php', $this->template_html );
        $this->template_base = STARTER_SHELTER_PATH . 'templates/';

        // Customer email by default.
        $this->customer_email = true;

        // Default placeholders.
        $this->placeholders = [
            '{site_name}'  => $this->get_blogname(),
            '{site_url}'   => home_url(),
            '{admin_email}' => get_option( 'admin_email' ),
        ];

        // Hook to trigger event.
        $trigger_hook = $config['trigger_hook'] ?? '';
        if ( $trigger_hook ) {
            $arg_count = count( $config['trigger_args'] ?? [] );
            add_action( $trigger_hook, [ $this, 'trigger' ], 10, $arg_count );
        }

        // Call parent constructor.
        parent::__construct();

        // Set default enabled state.
        $this->enabled = 'yes';
    }

    /**
     * Trigger the email.
     *
     * @since 1.0.0
     *
     * @param mixed ...$args Trigger arguments matching trigger_args config.
     */
    public function trigger( ...$args ): void {
        // Map arguments to named keys.
        $arg_names = $this->config['trigger_args'] ?? [];
        $this->trigger_args = [];

        foreach ( $arg_names as $index => $name ) {
            $this->trigger_args[ $name ] = $args[ $index ] ?? null;
        }

        // Hydrate entities.
        $this->hydrate_entities();

        // Store args for placeholder access.
        $this->entity_data['args'] = $this->trigger_args;

        // Check condition if specified.
        if ( isset( $this->config['condition'] ) ) {
            $condition_result = $this->resolve_path( $this->config['condition'] );
            if ( ! $condition_result ) {
                return;
            }
        }

        // Resolve placeholders.
        $this->resolve_placeholders();

        // Get recipient.
        $this->recipient = $this->get_recipient_email();

        if ( ! $this->recipient ) {
            return;
        }

        // Check if enabled.
        if ( ! $this->is_enabled() ) {
            return;
        }

        // Send the email.
        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    /**
     * Manual trigger with pre-built arguments.
     *
     * @since 1.0.0
     *
     * @param array $args Named arguments.
     * @return bool True if email was sent.
     */
    public function manual_trigger( array $args ): bool {
        $this->trigger_args = $args;
        $this->hydrate_entities();
        $this->entity_data['args'] = $this->trigger_args;

        // Check condition.
        if ( isset( $this->config['condition'] ) ) {
            if ( ! $this->resolve_path( $this->config['condition'] ) ) {
                return false;
            }
        }

        $this->resolve_placeholders();
        $this->recipient = $this->get_recipient_email();

        if ( ! $this->recipient || ! $this->is_enabled() ) {
            return false;
        }

        return $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    /**
     * Hydrate entities from configuration.
     *
     * @since 1.0.0
     */
    protected function hydrate_entities(): void {
        $this->entity_data = [];

        foreach ( $this->config['entities'] ?? [] as $name => $entity_config ) {
            $id_field = $entity_config['id_from'] ?? '';
            $entity_type = $entity_config['entity'] ?? '';

            if ( ! $id_field || ! $entity_type ) {
                continue;
            }

            // Get ID from trigger args.
            $entity_id = $this->trigger_args[ $id_field ] ?? null;

            if ( ! $entity_id ) {
                continue;
            }

            // Hydrate the entity.
            $entity = Entity_Hydrator::get( $entity_type, (int) $entity_id );

            if ( $entity ) {
                $this->entity_data[ $name ] = $entity;
            }
        }
    }

    /**
     * Resolve all configured placeholders.
     *
     * @since 1.0.0
     */
    protected function resolve_placeholders(): void {
        foreach ( $this->config['placeholders'] ?? [] as $key => $path ) {
            $value = $this->resolve_path( $path );
            $this->placeholders[ '{' . $key . '}' ] = $this->format_placeholder_value( $value );
        }
    }

    /**
     * Resolve a dot-notation path to a value.
     *
     * @since 1.0.0
     *
     * @param string $path The path (e.g., "donor.full_name").
     * @return mixed The resolved value.
     */
    protected function resolve_path( string $path ) {
        $parts = explode( '.', $path );
        $value = $this->entity_data;

        foreach ( $parts as $part ) {
            if ( is_array( $value ) && isset( $value[ $part ] ) ) {
                $value = $value[ $part ];
            } elseif ( is_object( $value ) && isset( $value->$part ) ) {
                $value = $value->$part;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Format a placeholder value for display.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value to format.
     * @return string Formatted string.
     */
    protected function format_placeholder_value( $value ): string {
        if ( null === $value ) {
            return '';
        }

        if ( is_bool( $value ) ) {
            return $value ? __( 'Yes', 'starter-shelter' ) : __( 'No', 'starter-shelter' );
        }

        if ( is_array( $value ) ) {
            return implode( ', ', array_filter( $value, 'is_string' ) );
        }

        return (string) $value;
    }

    /**
     * Get recipient email address.
     *
     * @since 1.0.0
     *
     * @return string|null Email address or null.
     */
    protected function get_recipient_email(): ?string {
        $recipient_type = $this->config['recipient_type'] ?? 'donor';

        switch ( $recipient_type ) {
            case 'donor':
                return $this->entity_data['donor']['email'] ?? null;

            case 'admin':
                return get_option( 'admin_email' );

            case 'custom':
                $field = $this->config['recipient_field'] ?? '';
                return $field ? $this->resolve_path( $field ) : null;

            default:
                return null;
        }
    }

    /**
     * Get email subject.
     *
     * @since 1.0.0
     *
     * @return string Email subject.
     */
    public function get_default_subject(): string {
        return $this->config['subject'] ?? __( 'Notification from {site_name}', 'starter-shelter' );
    }

    /**
     * Get email heading.
     *
     * @since 1.0.0
     *
     * @return string Email heading.
     */
    public function get_default_heading(): string {
        return $this->config['heading'] ?? $this->title;
    }

    /**
     * Get HTML content.
     *
     * @since 1.0.0
     *
     * @return string HTML content.
     */
    public function get_content_html(): string {
        return wc_get_template_html(
            $this->template_html,
            [
                'email'       => $this,
                'email_id'    => $this->email_id,
                'heading'     => $this->get_heading(),
                'data'        => $this->entity_data,
                'args'        => $this->trigger_args,
                'sent_to_admin' => false,
                'plain_text'  => false,
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Get plain text content.
     *
     * @since 1.0.0
     *
     * @return string Plain text content.
     */
    public function get_content_plain(): string {
        return wc_get_template_html(
            $this->template_plain,
            [
                'email'       => $this,
                'email_id'    => $this->email_id,
                'heading'     => $this->get_heading(),
                'data'        => $this->entity_data,
                'args'        => $this->trigger_args,
                'sent_to_admin' => false,
                'plain_text'  => true,
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Get entity data for templates.
     *
     * @since 1.0.0
     *
     * @param string|null $key Optional entity key.
     * @return array Entity data.
     */
    public function get_data( ?string $key = null ): array {
        if ( $key ) {
            return $this->entity_data[ $key ] ?? [];
        }
        return $this->entity_data;
    }

    /**
     * Get a specific value from entity data.
     *
     * @since 1.0.0
     *
     * @param string $path    The dot-notation path.
     * @param mixed  $default Default value if not found.
     * @return mixed The value.
     */
    public function get_value( string $path, $default = null ) {
        $value = $this->resolve_path( $path );
        return $value ?? $default;
    }

    /**
     * Initialize form fields for WooCommerce email settings.
     *
     * @since 1.0.0
     */
    public function init_form_fields(): void {
        $placeholder_text = $this->get_placeholder_text();

        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'starter-shelter' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email notification', 'starter-shelter' ),
                'default' => 'yes',
            ],
            'subject' => [
                'title'       => __( 'Subject', 'starter-shelter' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => sprintf(
                    /* translators: %s: placeholder list */
                    __( 'Available placeholders: %s', 'starter-shelter' ),
                    $placeholder_text
                ),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
            ],
            'heading' => [
                'title'       => __( 'Email heading', 'starter-shelter' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => sprintf(
                    /* translators: %s: placeholder list */
                    __( 'Available placeholders: %s', 'starter-shelter' ),
                    $placeholder_text
                ),
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
            ],
            'email_type' => [
                'title'       => __( 'Email type', 'starter-shelter' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'starter-shelter' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Get placeholder text for settings description.
     *
     * @since 1.0.0
     *
     * @return string Comma-separated placeholder list.
     */
    protected function get_placeholder_text(): string {
        $placeholders = array_keys( $this->placeholders );
        return '<code>' . implode( '</code>, <code>', $placeholders ) . '</code>';
    }

    /**
     * Get email type.
     *
     * @since 1.0.0
     *
     * @return string Email type.
     */
    public function get_email_type(): string {
        return $this->get_option( 'email_type', 'html' );
    }

    /**
     * Check if email template exists.
     *
     * @since 1.0.0
     *
     * @param string $template Template path.
     * @return bool True if template exists.
     */
    public function template_exists( string $template ): bool {
        $located = wc_locate_template( $template, '', $this->template_base );
        return ! empty( $located ) && file_exists( $located );
    }

    /**
     * Get the email ID.
     *
     * @since 1.0.0
     *
     * @return string Email ID.
     */
    public function get_email_id(): string {
        return $this->email_id;
    }

    /**
     * Get the email configuration.
     *
     * @since 1.0.0
     *
     * @return array Email configuration.
     */
    public function get_config(): array {
        return $this->config;
    }
}
