<?php
/**
 * Checkout Fields - Dynamic checkout fields based on cart products.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Core\Config;

/**
 * Manages dynamic checkout fields for shelter donation products.
 *
 * @since 1.0.0
 */
class Checkout_Fields {

    /**
     * Field definitions for each product type.
     *
     * @since 1.0.0
     * @var array
     */
    private static array $field_definitions = [];

    /**
     * Initialize checkout fields.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        self::load_field_definitions();

        // Add checkout fields.
        add_filter( 'woocommerce_checkout_fields', [ self::class, 'add_checkout_fields' ] );

        // Display fields after order notes.
        add_action( 'woocommerce_after_order_notes', [ self::class, 'display_conditional_fields' ] );

        // Save fields to order meta.
        add_action( 'woocommerce_checkout_update_order_meta', [ self::class, 'save_checkout_fields' ] );

        // Validate required fields.
        add_action( 'woocommerce_checkout_process', [ self::class, 'validate_checkout_fields' ] );

        // Display fields in admin order.
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ self::class, 'display_admin_order_fields' ] );

        // Display fields in order emails.
        add_action( 'woocommerce_email_after_order_table', [ self::class, 'display_email_order_fields' ], 10, 2 );

        // Enqueue scripts for conditional display.
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_scripts' ] );
    }

    /**
     * Load field definitions from configuration.
     *
     * @since 1.0.0
     */
    private static function load_field_definitions(): void {
        self::$field_definitions = [
            // Common fields.
            'is_anonymous' => [
                'type'        => 'checkbox',
                'label'       => __( 'Make my donation anonymous', 'starter-shelter' ),
                'description' => __( 'Your name will not be publicly listed.', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 10,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_is_anonymous',
            ],

            // Donation fields.
            'dedication' => [
                'type'        => 'text',
                'label'       => __( 'Dedication (optional)', 'starter-shelter' ),
                'placeholder' => __( 'In honor of...', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 20,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_dedication',
                'product_types' => [ 'donation' ],
            ],

            'campaign_id' => [
                'type'        => 'select',
                'label'       => __( 'Support a Campaign (optional)', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 30,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_campaign_id',
                'options'     => 'campaigns', // Dynamic options.
                'product_types' => [ 'donation' ],
            ],

            // Business membership fields.
            'business_name' => [
                'type'        => 'text',
                'label'       => __( 'Business Name', 'starter-shelter' ),
                'placeholder' => __( 'Your business or organization name', 'starter-shelter' ),
                'required'    => true,
                'priority'    => 10,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_business_name',
                'product_types' => [ 'business_membership' ],
            ],

            // Memorial fields.
            'honoree_name' => [
                'type'        => 'text',
                'label'       => __( 'Name of Person or Pet Being Honored', 'starter-shelter' ),
                'placeholder' => __( 'Enter name', 'starter-shelter' ),
                'required'    => true,
                'priority'    => 10,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_honoree_name',
                'product_types' => [ 'memorial' ],
            ],

            'pet_species' => [
                'type'        => 'select',
                'label'       => __( 'Species (if pet)', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 15,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_pet_species',
                'options'     => [
                    ''       => __( 'Not applicable / Human', 'starter-shelter' ),
                    'dog'    => __( 'Dog', 'starter-shelter' ),
                    'cat'    => __( 'Cat', 'starter-shelter' ),
                    'bird'   => __( 'Bird', 'starter-shelter' ),
                    'rabbit' => __( 'Rabbit', 'starter-shelter' ),
                    'other'  => __( 'Other', 'starter-shelter' ),
                ],
                'product_types' => [ 'memorial' ],
            ],

            'tribute_message' => [
                'type'        => 'textarea',
                'label'       => __( 'Tribute Message', 'starter-shelter' ),
                'placeholder' => __( 'Share a memory or message...', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 20,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_tribute_message',
                'product_types' => [ 'memorial' ],
            ],

            'notify_family' => [
                'type'        => 'checkbox',
                'label'       => __( 'Notify family of this tribute', 'starter-shelter' ),
                'description' => __( 'We will send a card to the family letting them know of your gift.', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 30,
                'class'       => [ 'form-row-wide' ],
                'meta_key'    => '_sd_notify_family',
                'product_types' => [ 'memorial' ],
            ],

            'family_name' => [
                'type'        => 'text',
                'label'       => __( 'Family Member Name', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 31,
                'class'       => [ 'form-row-wide', 'sd-family-field' ],
                'meta_key'    => '_sd_family_name',
                'product_types' => [ 'memorial' ],
                'conditional' => 'notify_family',
            ],

            'family_email' => [
                'type'        => 'email',
                'label'       => __( 'Family Email (for digital notification)', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 32,
                'class'       => [ 'form-row-first', 'sd-family-field' ],
                'meta_key'    => '_sd_family_email',
                'product_types' => [ 'memorial' ],
                'conditional' => 'notify_family',
            ],

            'family_address' => [
                'type'        => 'textarea',
                'label'       => __( 'Family Address (for mailed card)', 'starter-shelter' ),
                'placeholder' => __( 'Street address, City, State, ZIP', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 33,
                'class'       => [ 'form-row-wide', 'sd-family-field' ],
                'meta_key'    => '_sd_family_address',
                'product_types' => [ 'memorial' ],
                'conditional' => 'notify_family',
            ],

            'send_card' => [
                'type'        => 'checkbox',
                'label'       => __( 'Send a physical card (in addition to email)', 'starter-shelter' ),
                'required'    => false,
                'priority'    => 34,
                'class'       => [ 'form-row-wide', 'sd-family-field' ],
                'meta_key'    => '_sd_send_card',
                'product_types' => [ 'memorial' ],
                'conditional' => 'notify_family',
            ],
        ];

        /**
         * Filters the checkout field definitions.
         *
         * @since 1.0.0
         *
         * @param array $field_definitions The field definitions.
         */
        self::$field_definitions = apply_filters( 'starter_shelter_checkout_field_definitions', self::$field_definitions );
    }

    /**
     * Add shelter donation fields to WooCommerce checkout.
     *
     * @since 1.0.0
     *
     * @param array $fields Checkout fields.
     * @return array Modified checkout fields.
     */
    public static function add_checkout_fields( array $fields ): array {
        // We add our fields via display_conditional_fields instead.
        // This method is here for any standard field modifications.
        return $fields;
    }

    /**
     * Display conditional checkout fields based on cart contents.
     *
     * @since 1.0.0
     */
    public static function display_conditional_fields(): void {
        $cart_product_types = self::get_cart_product_types();

        if ( empty( $cart_product_types ) ) {
            return;
        }

        // Get fields to display.
        $fields_to_display = self::get_fields_for_product_types( $cart_product_types );

        if ( empty( $fields_to_display ) ) {
            return;
        }

        // Group fields by section.
        $sections = self::group_fields_by_section( $fields_to_display, $cart_product_types );

        foreach ( $sections as $section_id => $section ) {
            echo '<div class="sd-checkout-section sd-checkout-section-' . esc_attr( $section_id ) . '">';
            echo '<h3>' . esc_html( $section['title'] ) . '</h3>';

            if ( ! empty( $section['description'] ) ) {
                echo '<p class="sd-section-description">' . esc_html( $section['description'] ) . '</p>';
            }

            foreach ( $section['fields'] as $field_key => $field ) {
                self::render_field( $field_key, $field );
            }

            echo '</div>';
        }
    }

    /**
     * Get product types present in cart.
     *
     * @since 1.0.0
     *
     * @return array Array of product types.
     */
    public static function get_cart_product_types(): array {
        if ( ! WC()->cart ) {
            return [];
        }

        $types = [];

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            
            if ( ! $product ) {
                continue;
            }

            $sku = $product->get_sku();
            $config = Product_Mapper::find_by_sku( $sku );

            if ( $config ) {
                $type = $config['product_type'] ?? 'donation';
                
                // Check for business membership specifically.
                if ( 'membership' === $type ) {
                    $sku_prefix = $config['sku_prefix'] ?? '';
                    if ( str_contains( $sku_prefix, 'business' ) ) {
                        $types['business_membership'] = true;
                    } else {
                        $types['membership'] = true;
                    }
                } else {
                    $types[ $type ] = true;
                }
            }
        }

        return array_keys( $types );
    }

    /**
     * Get fields applicable to the given product types.
     *
     * @since 1.0.0
     *
     * @param array $product_types Array of product types.
     * @return array Filtered field definitions.
     */
    private static function get_fields_for_product_types( array $product_types ): array {
        $fields = [];

        foreach ( self::$field_definitions as $key => $field ) {
            // Fields without product_types apply to all.
            if ( empty( $field['product_types'] ) ) {
                $fields[ $key ] = $field;
                continue;
            }

            // Check if field applies to any cart product type.
            foreach ( $field['product_types'] as $field_type ) {
                if ( in_array( $field_type, $product_types, true ) ) {
                    $fields[ $key ] = $field;
                    break;
                }
            }
        }

        // Sort by priority.
        uasort( $fields, fn( $a, $b ) => ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 ) );

        return $fields;
    }

    /**
     * Group fields by section for display.
     *
     * @since 1.0.0
     *
     * @param array $fields        The fields to group.
     * @param array $product_types The cart product types.
     * @return array Grouped fields.
     */
    private static function group_fields_by_section( array $fields, array $product_types ): array {
        $sections = [];

        // Memorial section.
        if ( in_array( 'memorial', $product_types, true ) ) {
            $sections['memorial'] = [
                'title'       => __( 'Memorial Tribute Information', 'starter-shelter' ),
                'description' => __( 'Please provide details about the person or pet you are honoring.', 'starter-shelter' ),
                'fields'      => [],
            ];
        }

        // Business membership section.
        if ( in_array( 'business_membership', $product_types, true ) ) {
            $sections['business'] = [
                'title'       => __( 'Business Information', 'starter-shelter' ),
                'description' => __( 'Your business will be recognized as a supporting partner.', 'starter-shelter' ),
                'fields'      => [],
            ];
        }

        // General donation options.
        $sections['donation_options'] = [
            'title'  => __( 'Donation Options', 'starter-shelter' ),
            'fields' => [],
        ];

        // Assign fields to sections.
        foreach ( $fields as $key => $field ) {
            $field_types = $field['product_types'] ?? [];

            if ( in_array( 'memorial', $field_types, true ) && isset( $sections['memorial'] ) ) {
                $sections['memorial']['fields'][ $key ] = $field;
            } elseif ( in_array( 'business_membership', $field_types, true ) && isset( $sections['business'] ) ) {
                $sections['business']['fields'][ $key ] = $field;
            } else {
                $sections['donation_options']['fields'][ $key ] = $field;
            }
        }

        // Remove empty sections.
        return array_filter( $sections, fn( $s ) => ! empty( $s['fields'] ) );
    }

    /**
     * Render a checkout field.
     *
     * @since 1.0.0
     *
     * @param string $key   The field key.
     * @param array  $field The field definition.
     */
    private static function render_field( string $key, array $field ): void {
        $field_name = 'sd_' . $key;
        $value = WC()->checkout->get_value( $field_name );

        // Build field arguments.
        $args = [
            'type'        => $field['type'] ?? 'text',
            'label'       => $field['label'] ?? '',
            'placeholder' => $field['placeholder'] ?? '',
            'required'    => $field['required'] ?? false,
            'class'       => $field['class'] ?? [ 'form-row-wide' ],
            'default'     => $field['default'] ?? '',
        ];

        // Add conditional class.
        if ( ! empty( $field['conditional'] ) ) {
            $args['class'][] = 'sd-conditional-field';
            $args['custom_attributes'] = [
                'data-conditional' => 'sd_' . $field['conditional'],
            ];
        }

        // Handle select options.
        if ( 'select' === $args['type'] ) {
            $args['options'] = self::get_field_options( $field );
        }

        // Handle description.
        if ( ! empty( $field['description'] ) ) {
            $args['description'] = $field['description'];
        }

        woocommerce_form_field( $field_name, $args, $value );
    }

    /**
     * Get options for a select field.
     *
     * @since 1.0.0
     *
     * @param array $field The field definition.
     * @return array Field options.
     */
    private static function get_field_options( array $field ): array {
        $options = $field['options'] ?? [];

        // Dynamic options.
        if ( 'campaigns' === $options ) {
            return self::get_campaign_options();
        }

        return is_array( $options ) ? $options : [];
    }

    /**
     * Get campaign options for select field.
     *
     * @since 1.0.0
     *
     * @return array Campaign options.
     */
    private static function get_campaign_options(): array {
        $options = [ '' => __( '— Select a campaign (optional) —', 'starter-shelter' ) ];

        $campaigns = get_terms( [
            'taxonomy'   => 'sd_campaign',
            'hide_empty' => false,
        ] );

        if ( ! is_wp_error( $campaigns ) ) {
            foreach ( $campaigns as $campaign ) {
                $options[ $campaign->term_id ] = $campaign->name;
            }
        }

        return $options;
    }

    /**
     * Save checkout fields to order meta.
     *
     * @since 1.0.0
     *
     * @param int $order_id The order ID.
     */
    public static function save_checkout_fields( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        foreach ( self::$field_definitions as $key => $field ) {
            $field_name = 'sd_' . $key;
            $meta_key = $field['meta_key'] ?? '_sd_' . $key;

            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( isset( $_POST[ $field_name ] ) ) {
                $value = self::sanitize_field_value( $_POST[ $field_name ], $field );
                $order->update_meta_data( $meta_key, $value );
            }
        }

        $order->save();
    }

    /**
     * Sanitize a field value based on its type.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value to sanitize.
     * @param array $field The field definition.
     * @return mixed Sanitized value.
     */
    private static function sanitize_field_value( $value, array $field ) {
        $type = $field['type'] ?? 'text';

        return match ( $type ) {
            'checkbox' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ),
            'email'    => sanitize_email( $value ),
            'textarea' => sanitize_textarea_field( $value ),
            'select'   => sanitize_text_field( $value ),
            default    => sanitize_text_field( $value ),
        };
    }

    /**
     * Validate required checkout fields.
     *
     * @since 1.0.0
     */
    public static function validate_checkout_fields(): void {
        $cart_product_types = self::get_cart_product_types();
        $fields = self::get_fields_for_product_types( $cart_product_types );

        foreach ( $fields as $key => $field ) {
            if ( empty( $field['required'] ) ) {
                continue;
            }

            $field_name = 'sd_' . $key;
            
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $value = $_POST[ $field_name ] ?? '';

            // Check conditional requirement.
            if ( ! empty( $field['conditional'] ) ) {
                $conditional_field = 'sd_' . $field['conditional'];
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $conditional_value = $_POST[ $conditional_field ] ?? '';
                
                if ( ! $conditional_value ) {
                    continue; // Conditional parent not checked, skip validation.
                }
            }

            if ( empty( $value ) ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s is a required field.', 'starter-shelter' ),
                        '<strong>' . esc_html( $field['label'] ) . '</strong>'
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Display custom fields in admin order view.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order The order object.
     */
    public static function display_admin_order_fields( \WC_Order $order ): void {
        $has_fields = false;
        $output = '<div class="sd-admin-order-fields"><h3>' . esc_html__( 'Shelter Donation Details', 'starter-shelter' ) . '</h3>';

        foreach ( self::$field_definitions as $key => $field ) {
            $meta_key = $field['meta_key'] ?? '_sd_' . $key;
            $value = $order->get_meta( $meta_key );

            if ( '' !== $value && null !== $value && false !== $value ) {
                $has_fields = true;
                $display_value = self::format_field_display_value( $value, $field );
                
                $output .= sprintf(
                    '<p><strong>%s:</strong> %s</p>',
                    esc_html( $field['label'] ),
                    esc_html( $display_value )
                );
            }
        }

        $output .= '</div>';

        if ( $has_fields ) {
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Display custom fields in order emails.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order         The order object.
     * @param bool      $sent_to_admin Whether email is sent to admin.
     */
    public static function display_email_order_fields( \WC_Order $order, bool $sent_to_admin ): void {
        $fields_output = [];

        foreach ( self::$field_definitions as $key => $field ) {
            $meta_key = $field['meta_key'] ?? '_sd_' . $key;
            $value = $order->get_meta( $meta_key );

            if ( '' !== $value && null !== $value && false !== $value ) {
                $display_value = self::format_field_display_value( $value, $field );
                $fields_output[] = sprintf( '%s: %s', $field['label'], $display_value );
            }
        }

        if ( ! empty( $fields_output ) ) {
            echo '<h2>' . esc_html__( 'Donation Details', 'starter-shelter' ) . '</h2>';
            echo '<p>' . implode( '<br>', array_map( 'esc_html', $fields_output ) ) . '</p>';
        }
    }

    /**
     * Format a field value for display.
     *
     * @since 1.0.0
     *
     * @param mixed $value The value.
     * @param array $field The field definition.
     * @return string Formatted value.
     */
    private static function format_field_display_value( $value, array $field ): string {
        $type = $field['type'] ?? 'text';

        if ( 'checkbox' === $type ) {
            return $value ? __( 'Yes', 'starter-shelter' ) : __( 'No', 'starter-shelter' );
        }

        if ( 'select' === $type && is_array( $field['options'] ?? null ) ) {
            return $field['options'][ $value ] ?? $value;
        }

        return (string) $value;
    }

    /**
     * Enqueue checkout scripts.
     *
     * @since 1.0.0
     */
    public static function enqueue_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }

        wp_add_inline_script( 'wc-checkout', self::get_conditional_fields_script() );
        wp_add_inline_style( 'woocommerce-general', self::get_checkout_styles() );
    }

    /**
     * Get inline script for conditional fields.
     *
     * @since 1.0.0
     *
     * @return string JavaScript code.
     */
    private static function get_conditional_fields_script(): string {
        return <<<JS
jQuery(function($) {
    function toggleConditionalFields() {
        $('.sd-conditional-field').each(function() {
            var conditional = $(this).data('conditional');
            if (conditional) {
                var \$parent = $('#' + conditional);
                if (\$parent.is(':checkbox')) {
                    $(this).closest('.form-row').toggle(\$parent.is(':checked'));
                } else {
                    $(this).closest('.form-row').toggle(\$parent.val() !== '');
                }
            }
        });
    }
    
    // Initial state.
    toggleConditionalFields();
    
    // On change.
    $(document.body).on('change', '[id^="sd_"]', toggleConditionalFields);
});
JS;
    }

    /**
     * Get inline styles for checkout fields.
     *
     * @since 1.0.0
     *
     * @return string CSS code.
     */
    private static function get_checkout_styles(): string {
        return <<<CSS
.sd-checkout-section {
    background: #f8f8f8;
    padding: 20px;
    margin: 20px 0;
    border-radius: 4px;
}
.sd-checkout-section h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}
.sd-section-description {
    color: #666;
    font-style: italic;
    margin-bottom: 15px;
}
.sd-conditional-field {
    transition: opacity 0.3s ease;
}
CSS;
    }
}
