<?php
/**
 * Cart Handler - AJAX add-to-cart for donation products.
 *
 * Handles variable products with custom amounts and metadata.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use WP_Error;

/**
 * Handles cart operations for shelter donation products.
 *
 * @since 2.0.0
 */
class Cart_Handler {

    /**
     * Initialize the cart handler.
     *
     * @since 2.0.0
     */
    public static function init(): void {
        // AJAX handlers.
        add_action( 'wp_ajax_sd_add_to_cart', [ self::class, 'ajax_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_sd_add_to_cart', [ self::class, 'ajax_add_to_cart' ] );

        // Modify cart item data.
        add_filter( 'woocommerce_add_cart_item_data', [ self::class, 'add_cart_item_data' ], 10, 3 );
        
        // Display custom data in cart.
        add_filter( 'woocommerce_get_item_data', [ self::class, 'display_cart_item_data' ], 10, 2 );
        
        // Save custom data to order.
        add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'save_cart_item_to_order' ], 10, 4 );

        // Custom price handling for donations.
        add_action( 'woocommerce_before_calculate_totals', [ self::class, 'set_custom_price' ], 20 );

        // Validate cart items.
        add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validate_add_to_cart' ], 10, 5 );
    }

    /**
     * AJAX handler for adding donation to cart.
     *
     * @since 2.0.0
     */
    public static function ajax_add_to_cart(): void {
        check_ajax_referer( 'sd_add_to_cart', 'nonce' );

        $product_type = sanitize_key( $_POST['product_type'] ?? 'donation' );
        $amount = floatval( $_POST['amount'] ?? 0 );

        if ( $amount < 1 ) {
            wp_send_json_error( [
                'message' => __( 'Please enter a valid amount.', 'starter-shelter' ),
            ] );
        }

        // Get the product ID based on type.
        $product_id = self::get_product_id_for_type( $product_type );

        if ( ! $product_id ) {
            wp_send_json_error( [
                'message' => __( 'Donation product not configured. Please contact the site administrator.', 'starter-shelter' ),
            ] );
        }

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [
                'message' => __( 'Product not found.', 'starter-shelter' ),
            ] );
        }

        // Build cart item data.
        $cart_item_data = self::build_cart_item_data( $_POST, $product_type );

        // Find variation if variable product.
        $variation_id = 0;
        $variation = [];

        if ( $product->is_type( 'variable' ) ) {
            $variation_data = self::find_variation( $product, $_POST, $product_type );
            
            if ( is_wp_error( $variation_data ) ) {
                wp_send_json_error( [
                    'message' => $variation_data->get_error_message(),
                ] );
            }

            $variation_id = $variation_data['variation_id'];
            $variation = $variation_data['variation'];
        }

        // Clear existing donations from cart if configured.
        if ( apply_filters( 'starter_shelter_clear_cart_before_donation', false ) ) {
            WC()->cart->empty_cart();
        }

        // Add to cart.
        $cart_item_key = WC()->cart->add_to_cart(
            $product_id,
            1,
            $variation_id,
            $variation,
            $cart_item_data
        );

        if ( ! $cart_item_key ) {
            wp_send_json_error( [
                'message' => __( 'Could not add to cart. Please try again.', 'starter-shelter' ),
            ] );
        }

        wp_send_json_success( [
            'message'      => __( 'Added to cart successfully.', 'starter-shelter' ),
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'cart_count'   => WC()->cart->get_cart_contents_count(),
            'cart_total'   => WC()->cart->get_cart_total(),
        ] );
    }

    /**
     * Get product ID for a donation type.
     *
     * @since 2.0.0
     *
     * @param string $type Product type (donation, membership, business_membership, memorial).
     * @return int Product ID or 0.
     */
    private static function get_product_id_for_type( string $type ): int {
        $option_keys = [
            'donation'            => 'sd_donation_product_id',
            'membership'          => 'sd_membership_product_id',
            'business_membership' => 'sd_business_membership_product_id',
            'memorial'            => 'sd_memorial_product_id',
        ];

        $option_key = $option_keys[ $type ] ?? $option_keys['donation'];
        
        return (int) get_option( $option_key, 0 );
    }

    /**
     * Build cart item data from POST data.
     *
     * @since 2.0.0
     *
     * @param array  $post_data   POST data.
     * @param string $product_type Product type.
     * @return array Cart item data.
     */
    private static function build_cart_item_data( array $post_data, string $product_type ): array {
        $data = [
            'sd_product_type' => $product_type,
            'sd_custom_price' => floatval( $post_data['amount'] ?? 0 ),
        ];

        // Common fields.
        if ( ! empty( $post_data['allocation'] ) ) {
            $data['sd_allocation'] = sanitize_key( $post_data['allocation'] );
        }

        if ( ! empty( $post_data['campaign_id'] ) ) {
            $data['sd_campaign_id'] = absint( $post_data['campaign_id'] );
        }

        if ( ! empty( $post_data['is_anonymous'] ) ) {
            $data['sd_is_anonymous'] = true;
        }

        // Dedication fields.
        if ( ! empty( $post_data['dedication_enabled'] ) ) {
            $data['sd_dedication_enabled'] = true;
            
            if ( ! empty( $post_data['dedication_type'] ) ) {
                $data['sd_dedication_type'] = sanitize_key( $post_data['dedication_type'] );
            }

            if ( ! empty( $post_data['honoree_name'] ) ) {
                $data['sd_honoree_name'] = sanitize_text_field( $post_data['honoree_name'] );
            }

            if ( ! empty( $post_data['honoree_type'] ) ) {
                $data['sd_honoree_type'] = sanitize_key( $post_data['honoree_type'] );
            }

            if ( ! empty( $post_data['tribute_message'] ) ) {
                $data['sd_tribute_message'] = sanitize_textarea_field( $post_data['tribute_message'] );
            }

            // Family notification fields.
            if ( ! empty( $post_data['notify_family'] ) ) {
                $data['sd_notify_family'] = true;
                
                if ( ! empty( $post_data['family_name'] ) ) {
                    $data['sd_family_name'] = sanitize_text_field( $post_data['family_name'] );
                }
                if ( ! empty( $post_data['family_email'] ) ) {
                    $data['sd_family_email'] = sanitize_email( $post_data['family_email'] );
                }
                if ( ! empty( $post_data['family_address'] ) ) {
                    $data['sd_family_address'] = sanitize_textarea_field( $post_data['family_address'] );
                }
                if ( ! empty( $post_data['send_card'] ) ) {
                    $data['sd_send_card'] = true;
                }
            }
        }

        // Membership-specific fields.
        if ( in_array( $product_type, [ 'membership', 'business_membership' ], true ) ) {
            if ( ! empty( $post_data['tier'] ) ) {
                $data['sd_membership_tier'] = sanitize_key( $post_data['tier'] );
            }
            if ( ! empty( $post_data['business_name'] ) ) {
                $data['sd_business_name'] = sanitize_text_field( $post_data['business_name'] );
            }
        }

        /**
         * Filters cart item data for shelter donations.
         *
         * @since 2.0.0
         *
         * @param array  $data         Cart item data.
         * @param array  $post_data    Original POST data.
         * @param string $product_type Product type.
         */
        return apply_filters( 'starter_shelter_cart_item_data', $data, $post_data, $product_type );
    }

    /**
     * Find the appropriate variation for a variable product.
     *
     * @since 2.0.0
     *
     * @param \WC_Product_Variable $product      The variable product.
     * @param array                $post_data    POST data.
     * @param string               $product_type Product type.
     * @return array|WP_Error Variation data or error.
     */
    private static function find_variation( \WC_Product_Variable $product, array $post_data, string $product_type ) {
        $attribute_key = self::get_attribute_key_for_type( $product_type );
        $attribute_value = self::get_attribute_value_from_post( $post_data, $product_type );

        if ( ! $attribute_value ) {
            // If no specific attribute needed, get the first available variation.
            $variations = $product->get_available_variations();
            
            if ( empty( $variations ) ) {
                return new WP_Error( 'no_variations', __( 'No variations available for this product.', 'starter-shelter' ) );
            }

            return [
                'variation_id' => $variations[0]['variation_id'],
                'variation'    => [],
            ];
        }

        // Find variation matching the attribute.
        $data_store = \WC_Data_Store::load( 'product' );
        
        // Try different attribute formats.
        $attribute_formats = [
            $attribute_key => $attribute_value,
            'attribute_' . $attribute_key => $attribute_value,
            'attribute_pa_' . sanitize_title( $attribute_key ) => $attribute_value,
            sanitize_title( $attribute_key ) => $attribute_value,
        ];

        foreach ( $attribute_formats as $attr_name => $attr_value ) {
            $variation_id = $data_store->find_matching_product_variation( $product, [ $attr_name => $attr_value ] );
            
            if ( $variation_id ) {
                return [
                    'variation_id' => $variation_id,
                    'variation'    => [ $attr_name => $attr_value ],
                ];
            }
        }

        // Try matching by variation attribute value directly.
        foreach ( $product->get_available_variations() as $variation ) {
            foreach ( $variation['attributes'] as $attr_name => $attr_val ) {
                // Normalize for comparison.
                $normalized_attr = strtolower( trim( $attr_val ) );
                $normalized_search = strtolower( trim( $attribute_value ) );
                
                if ( $normalized_attr === $normalized_search || 
                     sanitize_title( $attr_val ) === sanitize_title( $attribute_value ) ) {
                    return [
                        'variation_id' => $variation['variation_id'],
                        'variation'    => [ $attr_name => $attr_val ],
                    ];
                }
            }
        }

        // Fallback: use first variation.
        $variations = $product->get_available_variations();
        
        if ( ! empty( $variations ) ) {
            return [
                'variation_id' => $variations[0]['variation_id'],
                'variation'    => [],
            ];
        }

        return new WP_Error( 'variation_not_found', __( 'Could not find a matching product variation.', 'starter-shelter' ) );
    }

    /**
     * Get the attribute key for a product type.
     *
     * @since 2.0.0
     *
     * @param string $product_type Product type.
     * @return string Attribute key.
     */
    private static function get_attribute_key_for_type( string $product_type ): string {
        $keys = [
            'donation'            => 'preferred-allocation',
            'membership'          => 'membership-level',
            'business_membership' => 'membership-level',
            'memorial'            => 'in-memoriam-type',
        ];

        return $keys[ $product_type ] ?? 'preferred-allocation';
    }

    /**
     * Get attribute value from POST data based on product type.
     *
     * @since 2.0.0
     *
     * @param array  $post_data    POST data.
     * @param string $product_type Product type.
     * @return string Attribute value.
     */
    private static function get_attribute_value_from_post( array $post_data, string $product_type ): string {
        switch ( $product_type ) {
            case 'donation':
                return sanitize_text_field( $post_data['allocation'] ?? 'general-fund' );
            
            case 'membership':
            case 'business_membership':
                return sanitize_text_field( $post_data['tier'] ?? '' );
            
            case 'memorial':
                return sanitize_text_field( $post_data['honoree_type'] ?? 'person' );
            
            default:
                return '';
        }
    }

    /**
     * Add custom data to cart item.
     *
     * @since 2.0.0
     *
     * @param array $cart_item_data Cart item data.
     * @param int   $product_id     Product ID.
     * @param int   $variation_id   Variation ID.
     * @return array Modified cart item data.
     */
    public static function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
        // This is called for standard add-to-cart; our AJAX handler adds data directly.
        // Handle URL parameter add-to-cart for backwards compatibility.
        
        if ( isset( $_REQUEST['sd_allocation'] ) ) {
            $cart_item_data['sd_allocation'] = sanitize_key( $_REQUEST['sd_allocation'] );
        }
        
        if ( isset( $_REQUEST['sd_amount'] ) ) {
            $cart_item_data['sd_custom_price'] = floatval( $_REQUEST['sd_amount'] );
        }

        if ( isset( $_REQUEST['sd_campaign'] ) ) {
            $cart_item_data['sd_campaign_id'] = absint( $_REQUEST['sd_campaign'] );
        }

        if ( isset( $_REQUEST['sd_anonymous'] ) ) {
            $cart_item_data['sd_is_anonymous'] = true;
        }

        return $cart_item_data;
    }

    /**
     * Display custom cart item data.
     *
     * @since 2.0.0
     *
     * @param array $item_data Cart item display data.
     * @param array $cart_item Cart item.
     * @return array Modified display data.
     */
    public static function display_cart_item_data( array $item_data, array $cart_item ): array {
        // Allocation.
        if ( ! empty( $cart_item['sd_allocation'] ) ) {
            $allocations = \Starter_Shelter\Core\Config::get_item( 'settings', 'allocations', [] );
            $allocation_label = $allocations[ $cart_item['sd_allocation'] ] ?? ucwords( str_replace( '-', ' ', $cart_item['sd_allocation'] ) );
            
            $item_data[] = [
                'key'   => __( 'Allocation', 'starter-shelter' ),
                'value' => $allocation_label,
            ];
        }

        // Campaign.
        if ( ! empty( $cart_item['sd_campaign_id'] ) ) {
            $campaign = get_term( $cart_item['sd_campaign_id'], 'sd_campaign' );
            if ( $campaign && ! is_wp_error( $campaign ) ) {
                $item_data[] = [
                    'key'   => __( 'Campaign', 'starter-shelter' ),
                    'value' => $campaign->name,
                ];
            }
        }

        // Dedication.
        if ( ! empty( $cart_item['sd_dedication_enabled'] ) ) {
            $dedication_type = $cart_item['sd_dedication_type'] ?? 'honor';
            $type_labels = [
                'honor'  => __( 'In Honor Of', 'starter-shelter' ),
                'memory' => __( 'In Memory Of', 'starter-shelter' ),
            ];

            if ( ! empty( $cart_item['sd_honoree_name'] ) ) {
                $item_data[] = [
                    'key'   => $type_labels[ $dedication_type ] ?? __( 'Dedication', 'starter-shelter' ),
                    'value' => $cart_item['sd_honoree_name'],
                ];
            }

            if ( ! empty( $cart_item['sd_honoree_type'] ) ) {
                $honoree_types = [
                    'person' => __( 'Person', 'starter-shelter' ),
                    'pet'    => __( 'Pet', 'starter-shelter' ),
                ];
                $item_data[] = [
                    'key'   => __( 'Honoree Type', 'starter-shelter' ),
                    'value' => $honoree_types[ $cart_item['sd_honoree_type'] ] ?? $cart_item['sd_honoree_type'],
                ];
            }

            if ( ! empty( $cart_item['sd_tribute_message'] ) ) {
                $item_data[] = [
                    'key'   => __( 'Tribute Message', 'starter-shelter' ),
                    'value' => wp_trim_words( $cart_item['sd_tribute_message'], 20 ),
                ];
            }
        }

        // Anonymous.
        if ( ! empty( $cart_item['sd_is_anonymous'] ) ) {
            $item_data[] = [
                'key'   => __( 'Anonymous', 'starter-shelter' ),
                'value' => __( 'Yes', 'starter-shelter' ),
            ];
        }

        // Membership tier.
        if ( ! empty( $cart_item['sd_membership_tier'] ) ) {
            $item_data[] = [
                'key'   => __( 'Membership Level', 'starter-shelter' ),
                'value' => ucwords( str_replace( '-', ' ', $cart_item['sd_membership_tier'] ) ),
            ];
        }

        // Business name.
        if ( ! empty( $cart_item['sd_business_name'] ) ) {
            $item_data[] = [
                'key'   => __( 'Business Name', 'starter-shelter' ),
                'value' => $cart_item['sd_business_name'],
            ];
        }

        return $item_data;
    }

    /**
     * Save cart item data to order item.
     *
     * @since 2.0.0
     *
     * @param \WC_Order_Item_Product $item          Order item.
     * @param string                 $cart_item_key Cart item key.
     * @param array                  $values        Cart item values.
     * @param \WC_Order              $order         Order.
     */
    public static function save_cart_item_to_order( $item, $cart_item_key, $values, $order ): void {
        $meta_keys = [
            'sd_product_type',
            'sd_allocation',
            'sd_campaign_id',
            'sd_is_anonymous',
            'sd_dedication_enabled',
            'sd_dedication_type',
            'sd_honoree_name',
            'sd_honoree_type',
            'sd_tribute_message',
            'sd_notify_family',
            'sd_family_name',
            'sd_family_email',
            'sd_family_address',
            'sd_send_card',
            'sd_membership_tier',
            'sd_business_name',
            'sd_custom_price',
        ];

        foreach ( $meta_keys as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $item->add_meta_data( '_' . $key, $values[ $key ], true );
            }
        }

        // Also save to order meta for easy access.
        if ( ! empty( $values['sd_campaign_id'] ) ) {
            $order->update_meta_data( '_sd_campaign_id', $values['sd_campaign_id'] );
        }
        if ( ! empty( $values['sd_is_anonymous'] ) ) {
            $order->update_meta_data( '_sd_is_anonymous', true );
        }
        if ( ! empty( $values['sd_dedication_enabled'] ) ) {
            $order->update_meta_data( '_sd_dedication_type', $values['sd_dedication_type'] ?? 'honor' );
            $order->update_meta_data( '_sd_honoree_name', $values['sd_honoree_name'] ?? '' );
        }
    }

    /**
     * Set custom price for donation items.
     *
     * @since 2.0.0
     *
     * @param \WC_Cart $cart Cart object.
     */
    public static function set_custom_price( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['sd_custom_price'] ) && $cart_item['sd_custom_price'] > 0 ) {
                $cart_item['data']->set_price( $cart_item['sd_custom_price'] );
            }
        }
    }

    /**
     * Validate add to cart.
     *
     * @since 2.0.0
     *
     * @param bool $passed     Validation passed.
     * @param int  $product_id Product ID.
     * @param int  $quantity   Quantity.
     * @param int  $variation_id Variation ID.
     * @param array $variations Variations.
     * @return bool Validation result.
     */
    public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ): bool {
        // Check if this is a shelter product.
        $product = wc_get_product( $product_id );
        
        if ( ! $product ) {
            return $passed;
        }

        $sku = $product->get_sku();
        $config = Product_Mapper::find_by_sku( $sku );

        if ( ! $config ) {
            return $passed;
        }

        // Validate custom amount if provided.
        if ( isset( $_REQUEST['sd_amount'] ) ) {
            $amount = floatval( $_REQUEST['sd_amount'] );
            
            if ( $amount < 1 ) {
                wc_add_notice( __( 'Please enter a valid donation amount.', 'starter-shelter' ), 'error' );
                return false;
            }

            $max_amount = apply_filters( 'starter_shelter_max_donation_amount', 100000 );
            
            if ( $amount > $max_amount ) {
                wc_add_notice( 
                    sprintf( 
                        /* translators: %s: maximum amount */
                        __( 'The maximum donation amount is %s.', 'starter-shelter' ), 
                        wc_price( $max_amount ) 
                    ), 
                    'error' 
                );
                return false;
            }
        }

        return $passed;
    }
}
