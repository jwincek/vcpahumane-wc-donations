<?php
/**
 * Admin Meta Boxes - Auto-generated from entity schema.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Handles auto-generated meta boxes for shelter CPTs.
 *
 * @since 1.0.0
 */
class Meta_Boxes {

    /**
     * Meta box configurations by post type.
     *
     * @var array
     */
    private static array $meta_boxes = [];

    /**
     * Initialize meta boxes.
     */
    public static function init(): void {
        self::$meta_boxes = self::get_meta_box_config();

        foreach ( array_keys( self::$meta_boxes ) as $post_type ) {
            add_action( "add_meta_boxes_{$post_type}", [ self::class, 'register_meta_boxes' ] );
            add_action( "save_post_{$post_type}", [ self::class, 'save_meta_boxes' ], 10, 2 );
        }

        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    /**
     * Get meta box configuration for all post types.
     *
     * @return array Meta box configurations.
     */
    private static function get_meta_box_config(): array {
        return [
            'sd_donation' => [
                'boxes' => [
                    'donation_details' => [
                        'title'    => __( 'Donation Details', 'starter-shelter' ),
                        'context'  => 'normal',
                        'priority' => 'high',
                        'fields'   => [
                            'amount'        => [ 'label' => __( 'Amount', 'starter-shelter' ), 'type' => 'currency', 'required' => true ],
                            'donor_id'      => [ 'label' => __( 'Donor', 'starter-shelter' ), 'type' => 'post_select', 'post_type' => 'sd_donor' ],
                            'donation_date' => [ 'label' => __( 'Donation Date', 'starter-shelter' ), 'type' => 'datetime', 'default' => 'now' ],
                            'allocation'    => [ 'label' => __( 'Allocation', 'starter-shelter' ), 'type' => 'select', 'options' => 'allocations' ],
                            'is_anonymous'  => [ 'label' => __( 'Anonymous Donation', 'starter-shelter' ), 'type' => 'checkbox' ],
                            'dedication'    => [ 'label' => __( 'Dedication Message', 'starter-shelter' ), 'type' => 'textarea', 'rows' => 3 ],
                        ],
                    ],
                    'order_info' => [
                        'title'    => __( 'Order Information', 'starter-shelter' ),
                        'context'  => 'side',
                        'fields'   => [
                            'wc_order_id' => [ 'label' => __( 'WooCommerce Order', 'starter-shelter' ), 'type' => 'order_link', 'readonly' => true ],
                        ],
                    ],
                ],
            ],

            'sd_membership' => [
                'boxes' => [
                    'membership_details' => [
                        'title'    => __( 'Membership Details', 'starter-shelter' ),
                        'context'  => 'normal',
                        'priority' => 'high',
                        'fields'   => [
                            'donor_id'        => [ 'label' => __( 'Member', 'starter-shelter' ), 'type' => 'post_select', 'post_type' => 'sd_donor' ],
                            'membership_type' => [ 'label' => __( 'Type', 'starter-shelter' ), 'type' => 'select', 'options' => [ 'individual' => 'Individual', 'business' => 'Business' ] ],
                            'tier'            => [ 'label' => __( 'Tier', 'starter-shelter' ), 'type' => 'tier_select' ],
                            'amount'          => [ 'label' => __( 'Amount Paid', 'starter-shelter' ), 'type' => 'currency' ],
                            'start_date'      => [ 'label' => __( 'Start Date', 'starter-shelter' ), 'type' => 'date' ],
                            'end_date'        => [ 'label' => __( 'End Date', 'starter-shelter' ), 'type' => 'date' ],
                        ],
                    ],
                    'business_info' => [
                        'title'     => __( 'Business Information', 'starter-shelter' ),
                        'context'   => 'normal',
                        'show_when' => [ 'membership_type' => 'business' ],
                        'fields'    => [
                            'business_name'        => [ 'label' => __( 'Business Name', 'starter-shelter' ), 'type' => 'text' ],
                            'business_website'     => [ 'label' => __( 'Website', 'starter-shelter' ), 'type' => 'url' ],
                            'business_description' => [ 'label' => __( 'Description', 'starter-shelter' ), 'type' => 'textarea', 'rows' => 3 ],
                            'logo_attachment_id'   => [ 'label' => __( 'Business Logo', 'starter-shelter' ), 'type' => 'image' ],
                            'logo_status'          => [ 'label' => __( 'Logo Status', 'starter-shelter' ), 'type' => 'status_badge', 'readonly' => true ],
                        ],
                    ],
                ],
            ],

            'sd_memorial' => [
                'boxes' => [
                    'memorial_details' => [
                        'title'    => __( 'Memorial Details', 'starter-shelter' ),
                        'context'  => 'normal',
                        'priority' => 'high',
                        'fields'   => [
                            'honoree_name'    => [ 'label' => __( 'Honoree Name', 'starter-shelter' ), 'type' => 'text', 'required' => true ],
                            'memorial_type'   => [ 'label' => __( 'Type', 'starter-shelter' ), 'type' => 'select', 'options' => [ 'human' => 'Person', 'pet' => 'Pet', 'honor' => 'In Honor Of' ] ],
                            'pet_species'     => [ 'label' => __( 'Pet Species', 'starter-shelter' ), 'type' => 'select', 'options' => [ 'dog' => 'Dog', 'cat' => 'Cat', 'bird' => 'Bird', 'horse' => 'Horse', 'other' => 'Other' ], 'show_when' => [ 'memorial_type' => 'pet' ] ],
                            'tribute_message' => [ 'label' => __( 'Tribute Message', 'starter-shelter' ), 'type' => 'textarea', 'rows' => 6 ],
                        ],
                    ],
                    'donation_info' => [
                        'title'   => __( 'Donation Info', 'starter-shelter' ),
                        'context' => 'side',
                        'fields'  => [
                            'donor_id'           => [ 'label' => __( 'Donated By', 'starter-shelter' ), 'type' => 'post_select', 'post_type' => 'sd_donor' ],
                            'donor_display_name' => [ 'label' => __( 'Display Name', 'starter-shelter' ), 'type' => 'text', 'description' => __( 'Name shown on the memorial wall. Leave empty to pull from donor record.', 'starter-shelter' ) ],
                            'amount'             => [ 'label' => __( 'Amount', 'starter-shelter' ), 'type' => 'currency' ],
                            'donation_date'      => [ 'label' => __( 'Date', 'starter-shelter' ), 'type' => 'date' ],
                            'is_anonymous'       => [ 'label' => __( 'Anonymous', 'starter-shelter' ), 'type' => 'checkbox' ],
                        ],
                    ],
                    'family_notification' => [
                        'title'   => __( 'Family Notification', 'starter-shelter' ),
                        'context' => 'normal',
                        'fields'  => [
                            'notify_family_enabled' => [ 'label' => __( 'Notify Family', 'starter-shelter' ), 'type' => 'checkbox' ],
                            'notify_family_name'    => [ 'label' => __( 'Family Name', 'starter-shelter' ), 'type' => 'text', 'show_when' => [ 'notify_family_enabled' => true ] ],
                            'notify_family_email'   => [ 'label' => __( 'Family Email', 'starter-shelter' ), 'type' => 'email', 'show_when' => [ 'notify_family_enabled' => true ] ],
                            'family_notified_date'  => [ 'label' => __( 'Notification Sent', 'starter-shelter' ), 'type' => 'datetime_display', 'readonly' => true ],
                        ],
                    ],
                ],
            ],

            'sd_donor' => [
                'boxes' => [
                    'contact_info' => [
                        'title'    => __( 'Contact Information', 'starter-shelter' ),
                        'context'  => 'normal',
                        'priority' => 'high',
                        'fields'   => [
                            'first_name' => [ 'label' => __( 'First Name', 'starter-shelter' ), 'type' => 'text', 'required' => true ],
                            'last_name'  => [ 'label' => __( 'Last Name', 'starter-shelter' ), 'type' => 'text', 'required' => true ],
                            'email'      => [ 'label' => __( 'Email', 'starter-shelter' ), 'type' => 'email', 'required' => true ],
                            'phone'      => [ 'label' => __( 'Phone', 'starter-shelter' ), 'type' => 'tel' ],
                        ],
                    ],
                    'address' => [
                        'title'   => __( 'Address', 'starter-shelter' ),
                        'context' => 'normal',
                        'fields'  => [
                            'address_line_1' => [ 'label' => __( 'Address Line 1', 'starter-shelter' ), 'type' => 'text' ],
                            'address_line_2' => [ 'label' => __( 'Address Line 2', 'starter-shelter' ), 'type' => 'text' ],
                            'city'           => [ 'label' => __( 'City', 'starter-shelter' ), 'type' => 'text' ],
                            'state'          => [ 'label' => __( 'State', 'starter-shelter' ), 'type' => 'text' ],
                            'postal_code'    => [ 'label' => __( 'Postal Code', 'starter-shelter' ), 'type' => 'text' ],
                        ],
                    ],
                    'donor_stats' => [
                        'title'   => __( 'Donor Statistics', 'starter-shelter' ),
                        'context' => 'side',
                        'fields'  => [
                            'lifetime_giving'     => [ 'label' => __( 'Lifetime Giving', 'starter-shelter' ), 'type' => 'currency_display', 'readonly' => true ],
                            'donation_count'      => [ 'label' => __( 'Total Donations', 'starter-shelter' ), 'type' => 'number_display', 'readonly' => true ],
                            'donor_level'         => [ 'label' => __( 'Donor Level', 'starter-shelter' ), 'type' => 'level_badge', 'readonly' => true ],
                            'first_donation_date' => [ 'label' => __( 'First Donation', 'starter-shelter' ), 'type' => 'date_display', 'readonly' => true ],
                        ],
                    ],
                    'user_account' => [
                        'title'    => __( 'User Account', 'starter-shelter' ),
                        'context'  => 'side',
                        'priority' => 'low',
                        'fields'   => [
                            'user_id' => [ 'label' => __( 'Linked User', 'starter-shelter' ), 'type' => 'user_select' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Register meta boxes for a post type.
     */
    public static function register_meta_boxes( \WP_Post $post ): void {
        $post_type = $post->post_type;
        if ( ! isset( self::$meta_boxes[ $post_type ] ) ) {
            return;
        }

        foreach ( self::$meta_boxes[ $post_type ]['boxes'] as $box_id => $box ) {
            add_meta_box(
                'sd_' . $box_id,
                $box['title'],
                [ self::class, 'render_meta_box' ],
                $post_type,
                $box['context'] ?? 'normal',
                $box['priority'] ?? 'default',
                [ 'box_id' => $box_id, 'fields' => $box['fields'], 'show_when' => $box['show_when'] ?? null ]
            );
        }
    }

    /**
     * Render a meta box.
     */
    public static function render_meta_box( \WP_Post $post, array $meta_box ): void {
        $args = $meta_box['args'];
        $entity = Entity_Hydrator::get( $post->post_type, $post->ID );

        wp_nonce_field( 'sd_meta_box_' . $args['box_id'], 'sd_meta_box_' . $args['box_id'] . '_nonce' );

        $wrapper_attrs = $args['show_when'] ? ' data-show-when="' . esc_attr( wp_json_encode( $args['show_when'] ) ) . '"' : '';

        echo '<div class="sd-meta-box"' . $wrapper_attrs . '><table class="form-table sd-meta-fields">';
        foreach ( $args['fields'] as $field_id => $field ) {
            self::render_field( $field_id, $field, $entity, $post );
        }
        echo '</table></div>';
    }

    /**
     * Render a single field.
     */
    private static function render_field( string $field_id, array $field, array $entity, \WP_Post $post ): void {
        $type = $field['type'];
        $label = $field['label'] ?? '';
        $required = $field['required'] ?? false;
        $readonly = $field['readonly'] ?? false;
        $show_when = $field['show_when'] ?? null;

        $value = $entity[ $field_id ] ?? get_post_meta( $post->ID, '_sd_' . $field_id, true );

        $row_attrs = $show_when ? ' data-show-when="' . esc_attr( wp_json_encode( $show_when ) ) . '" style="display:none;"' : '';

        echo '<tr' . $row_attrs . '><th scope="row"><label for="sd_' . esc_attr( $field_id ) . '">' . esc_html( $label );
        if ( $required ) echo ' <span class="required">*</span>';
        echo '</label></th><td>';

        switch ( $type ) {
            case 'text':
            case 'email':
            case 'tel':
            case 'url':
                $input_type = $type === 'url' ? 'url' : $type;
                printf( '<input type="%s" id="sd_%s" name="sd_%s" value="%s" class="regular-text" %s />', esc_attr( $input_type ), esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ), $readonly ? 'readonly' : '' );
                break;

            case 'currency':
                echo '<div class="sd-currency-input"><span class="sd-currency-symbol">$</span>';
                printf( '<input type="number" id="sd_%s" name="sd_%s" value="%s" class="regular-text" min="0" step="0.01" /></div>', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ) );
                break;

            case 'textarea':
                printf( '<textarea id="sd_%s" name="sd_%s" rows="%d" class="large-text">%s</textarea>', esc_attr( $field_id ), esc_attr( $field_id ), (int) ( $field['rows'] ?? 5 ), esc_textarea( $value ) );
                break;

            case 'select':
                $options = is_string( $field['options'] ) ? Config::get_item( 'settings', $field['options'], [] ) : $field['options'];
                echo '<select id="sd_' . esc_attr( $field_id ) . '" name="sd_' . esc_attr( $field_id ) . '">';
                echo '<option value="">' . esc_html__( '— Select —', 'starter-shelter' ) . '</option>';
                foreach ( $options as $opt_val => $opt_label ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_val ), selected( $value, $opt_val, false ), esc_html( $opt_label ) );
                }
                echo '</select>';
                break;

            case 'tier_select':
                $type_val = $entity['membership_type'] ?? 'individual';
                $tiers_config = Config::get( 'tiers' );
                $all_tiers = $tiers_config['tiers'] ?? [];
                echo '<select id="sd_' . esc_attr( $field_id ) . '" name="sd_' . esc_attr( $field_id ) . '" class="sd-tier-select" data-tier-select>';
                echo '<option value="">' . esc_html__( '— Select Tier —', 'starter-shelter' ) . '</option>';
                foreach ( $all_tiers as $tier_type => $tiers ) {
                    foreach ( $tiers as $slug => $data ) {
                        $hidden = $tier_type !== $type_val ? ' style="display:none;"' : '';
                        $price = $data['amount'] ?? $data['price'] ?? 0;
                        printf( '<option value="%s" data-type="%s" %s%s>%s (%s)</option>', esc_attr( $slug ), esc_attr( $tier_type ), selected( $value, $slug, false ), $hidden, esc_html( $data['label'] ?? ucfirst( $slug ) ), esc_html( Helpers\format_currency( $price ) ) );
                    }
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf( '<input type="checkbox" id="sd_%s" name="sd_%s" value="1" %s />', esc_attr( $field_id ), esc_attr( $field_id ), checked( $value, true, false ) );
                break;

            case 'date':
                printf( '<input type="date" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ? wp_date( 'Y-m-d', strtotime( $value ) ) : '' ) );
                break;

            case 'datetime':
                if ( ( $field['default'] ?? '' ) === 'now' && ! $value ) $value = current_time( 'mysql' );
                printf( '<input type="datetime-local" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ? wp_date( 'Y-m-d\TH:i', strtotime( $value ) ) : '' ) );
                break;

            case 'image':
                $img_url = $value ? wp_get_attachment_image_url( $value, 'thumbnail' ) : '';
                echo '<div class="sd-image-upload"><div class="sd-image-preview">';
                if ( $img_url ) echo '<img src="' . esc_url( $img_url ) . '" />';
                echo '</div>';
                printf( '<input type="hidden" id="sd_%s" name="sd_%s" value="%s" />', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $value ) );
                echo '<button type="button" class="button sd-upload-image">' . esc_html__( 'Select Image', 'starter-shelter' ) . '</button>';
                echo '<button type="button" class="button sd-remove-image"' . ( ! $value ? ' style="display:none;"' : '' ) . '>' . esc_html__( 'Remove', 'starter-shelter' ) . '</button></div>';
                break;

            case 'post_select':
                $selected_title = $value ? get_the_title( $value ) : '';
                printf( '<select id="sd_%s" name="sd_%s" class="sd-post-select" data-post-type="%s">', esc_attr( $field_id ), esc_attr( $field_id ), esc_attr( $field['post_type'] ?? 'post' ) );
                if ( $value && $selected_title ) printf( '<option value="%s" selected>%s</option>', esc_attr( $value ), esc_html( $selected_title ) );
                echo '</select>';
                break;

            case 'user_select':
                wp_dropdown_users( [ 'name' => 'sd_' . $field_id, 'id' => 'sd_' . $field_id, 'selected' => $value, 'show_option_none' => __( '— No User —', 'starter-shelter' ), 'option_none_value' => 0 ] );
                break;

            case 'order_link':
                if ( $value ) printf( '<a href="%s" class="button">%s #%d</a>', esc_url( admin_url( 'post.php?post=' . $value . '&action=edit' ) ), esc_html__( 'View Order', 'starter-shelter' ), (int) $value );
                else echo '<span class="description">' . esc_html__( 'Not linked to an order', 'starter-shelter' ) . '</span>';
                break;

            case 'currency_display':
                echo '<strong class="sd-currency-display">' . esc_html( Helpers\format_currency( $value ?: 0 ) ) . '</strong>';
                break;

            case 'number_display':
                echo '<strong>' . esc_html( number_format( (int) $value ) ) . '</strong>';
                break;

            case 'date_display':
            case 'datetime_display':
                echo $value ? esc_html( Helpers\format_date( $value ) ) : '—';
                break;

            case 'level_badge':
                $levels = [ 'new' => 'New', 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold', 'platinum' => 'Platinum' ];
                $class = $value ? 'sd-level--' . $value : '';
                echo '<span class="sd-level-badge ' . esc_attr( $class ) . '">' . esc_html( $levels[ $value ] ?? 'New' ) . '</span>';
                break;

            case 'status_badge':
                $statuses = [ 'pending' => [ 'Pending Review', 'sd-badge--warning' ], 'approved' => [ 'Approved', 'sd-badge--success' ], 'rejected' => [ 'Rejected', 'sd-badge--error' ] ];
                $logo_id = $entity['logo_attachment_id'] ?? 0;
                if ( ! $logo_id ) { echo '<span class="sd-badge sd-badge--muted">No Logo</span>'; break; }
                $status = $value ?: 'pending';
                $info = $statuses[ $status ] ?? $statuses['pending'];
                echo '<span class="sd-badge ' . esc_attr( $info[1] ) . '">' . esc_html( $info[0] ) . '</span>';
                break;
        }

        if ( ! empty( $field['description'] ) ) echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
        echo '</td></tr>';
    }

    /**
     * Save meta box data.
     */
    public static function save_meta_boxes( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $post_type = $post->post_type;
        if ( ! isset( self::$meta_boxes[ $post_type ] ) ) return;

        foreach ( self::$meta_boxes[ $post_type ]['boxes'] as $box_id => $box ) {
            $nonce_key = 'sd_meta_box_' . $box_id . '_nonce';
            if ( ! isset( $_POST[ $nonce_key ] ) || ! wp_verify_nonce( $_POST[ $nonce_key ], 'sd_meta_box_' . $box_id ) ) continue;

            foreach ( $box['fields'] as $field_id => $field ) {
                if ( ! empty( $field['readonly'] ) ) continue;

                $key = 'sd_' . $field_id;
                $meta_key = '_sd_' . $field_id;

                if ( isset( $_POST[ $key ] ) ) {
                    $value = self::sanitize_field( $_POST[ $key ], $field['type'] );
                    update_post_meta( $post_id, $meta_key, $value );
                } elseif ( 'checkbox' === $field['type'] ) {
                    update_post_meta( $post_id, $meta_key, 0 );
                }
            }
        }
    }

    /**
     * Sanitize a field value based on type.
     */
    private static function sanitize_field( $value, string $type ) {
        return match ( $type ) {
            'email'                          => sanitize_email( $value ),
            'url'                            => esc_url_raw( $value ),
            'number', 'currency'             => floatval( $value ),
            'checkbox'                       => ! empty( $value ) ? 1 : 0,
            'textarea'                       => sanitize_textarea_field( $value ),
            'post_select', 'user_select', 'image' => absint( $value ),
            default                          => sanitize_text_field( $value ),
        };
    }

    /**
     * Enqueue admin assets for meta boxes.
     */
    public static function enqueue_assets( string $hook ): void {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) return;

        $screen = get_current_screen();
        if ( ! $screen || ! isset( self::$meta_boxes[ $screen->post_type ] ) ) return;

        wp_enqueue_media();
        wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', [ 'jquery' ], '4.1.0', true );
        wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0' );

        wp_enqueue_script( 'sd-meta-boxes', STARTER_SHELTER_URL . 'assets/js/admin-meta-boxes.js', [ 'jquery', 'select2' ], STARTER_SHELTER_VERSION, true );
        wp_localize_script( 'sd-meta-boxes', 'sdMetaBoxes', [ 'restUrl' => rest_url( 'wp/v2/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'selectImage' => __( 'Select Image', 'starter-shelter' ), 'useImage' => __( 'Use this image', 'starter-shelter' ) ] );

        wp_add_inline_style( 'wp-admin', '
            /* Meta box table layout */
            .sd-meta-box .form-table th { width: 150px; }
            .sd-meta-box .required { color: #dc3545; }
            
            /* Sidebar meta boxes - prevent overflow */
            #side-sortables .sd-meta-box .form-table,
            #side-sortables .sd-meta-box .form-table tbody,
            #side-sortables .sd-meta-box .form-table tr,
            #side-sortables .sd-meta-box .form-table th,
            #side-sortables .sd-meta-box .form-table td {
                display: block;
                width: 100%;
            }
            #side-sortables .sd-meta-box .form-table th {
                padding-bottom: 5px;
                font-weight: 600;
            }
            #side-sortables .sd-meta-box .form-table td {
                padding-bottom: 15px;
            }
            
            /* Force Select2 to respect container width in sidebars */
            #side-sortables .sd-meta-box .select2-container {
                width: 100% !important;
                max-width: 100% !important;
            }
            #side-sortables .sd-meta-box .sd-post-select,
            #side-sortables .sd-meta-box select {
                width: 100% !important;
                max-width: 100% !important;
            }
            #side-sortables .sd-meta-box input[type="text"],
            #side-sortables .sd-meta-box input[type="number"],
            #side-sortables .sd-meta-box input[type="date"],
            #side-sortables .sd-meta-box input[type="email"] {
                width: 100%;
                max-width: 100%;
            }
            #side-sortables .sd-meta-box .sd-currency-input {
                max-width: 100%;
            }
            #side-sortables .sd-meta-box .sd-currency-input input {
                flex: 1;
                min-width: 0;
            }
            
            /* Currency input */
            .sd-currency-input { display: flex; align-items: center; max-width: 200px; }
            .sd-currency-symbol { padding: 0 8px; background: #f0f0f1; border: 1px solid #8c8f94; border-right: 0; line-height: 28px; border-radius: 4px 0 0 4px; }
            .sd-currency-input input { border-radius: 0 4px 4px 0; max-width: 150px; }
            .sd-currency-display { color: #059669; font-size: 18px; }
            
            /* Image upload */
            .sd-image-upload { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .sd-image-preview { width: 100px; height: 100px; border: 1px dashed #ccc; border-radius: 4px; overflow: hidden; }
            .sd-image-preview img { width: 100%; height: 100%; object-fit: cover; }
            
            /* Post select - main area */
            .sd-post-select { min-width: 300px; }
            
            /* Tier select */
            .sd-tier-select { min-width: 250px; }
            
            /* Badges */
            .sd-level-badge, .sd-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; }
            .sd-level--bronze { background: #fef3c7; color: #92400e; }
            .sd-level--silver { background: #e5e7eb; color: #374151; }
            .sd-level--gold { background: #fef08a; color: #854d0e; }
            .sd-level--platinum { background: #e0e7ff; color: #3730a3; }
            .sd-badge--success { background: #d1fae5; color: #065f46; }
            .sd-badge--warning { background: #fef3c7; color: #92400e; }
            .sd-badge--error { background: #fee2e2; color: #991b1b; }
            .sd-badge--muted { background: #f3f4f6; color: #6b7280; }
        ' );
    }
}
