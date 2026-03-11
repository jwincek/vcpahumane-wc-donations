<?php
/**
 * Order Handler - Processes WooCommerce orders and routes to abilities.
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use WP_Error;

/**
 * Handles WooCommerce order processing and routes items to appropriate abilities.
 *
 * @since 1.0.0
 */
class Order_Handler {

    /**
     * Meta key for tracking processed orders.
     *
     * @since 1.0.0
     * @var string
     */
    private const PROCESSED_META_KEY = '_sd_processed';

    /**
     * Meta key for storing processing results.
     *
     * @since 1.0.0
     * @var string
     */
    private const RESULTS_META_KEY = '_sd_processing_results';

    /**
     * Initialize the order handler.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        // Process on order completion.
        add_action( 'woocommerce_order_status_completed', [ self::class, 'process_order' ], 20 );
        
        // Also process on processing status for immediate fulfillment.
        add_action( 'woocommerce_order_status_processing', [ self::class, 'process_order' ], 20 );

        // Handle subscription renewals if WooCommerce Subscriptions is active.
        add_action( 'woocommerce_subscription_renewal_payment_complete', [ self::class, 'process_renewal' ], 20 );

        // Admin notice for processing errors.
        add_action( 'add_meta_boxes', [ self::class, 'add_processing_meta_box' ] );

        // AJAX handler for manual reprocessing.
        add_action( 'wp_ajax_sd_reprocess_order', [ self::class, 'ajax_reprocess_order' ] );
    }

    /**
     * Process a WooCommerce order.
     *
     * @since 1.0.0
     *
     * @param int $order_id The order ID.
     */
    public static function process_order( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Check if already processed.
        if ( $order->get_meta( self::PROCESSED_META_KEY ) ) {
            return;
        }

        // Check if order contains shelter products.
        if ( ! Product_Mapper::order_has_shelter_products( $order ) ) {
            return;
        }

        $results = [];
        $has_errors = false;

        foreach ( $order->get_items() as $item_id => $item ) {
            $result = self::process_item( $order, $item );
            
            if ( null !== $result ) {
                $results[ $item_id ] = $result;
                
                if ( is_wp_error( $result ) ) {
                    $has_errors = true;
                }
            }
        }

        // Store results.
        $order->update_meta_data( self::RESULTS_META_KEY, $results );

        // Mark as processed (even with errors, to prevent duplicate processing).
        $order->update_meta_data( self::PROCESSED_META_KEY, current_time( 'mysql' ) );
        
        $order->save();

        // Add order note with summary.
        self::add_processing_note( $order, $results, $has_errors );

        /**
         * Fires after an order has been processed by the shelter donations plugin.
         *
         * @since 1.0.0
         *
         * @param int   $order_id   The order ID.
         * @param array $results    Processing results for each item.
         * @param bool  $has_errors Whether any errors occurred.
         */
        do_action( 'starter_shelter_order_processed', $order_id, $results, $has_errors );
    }

    /**
     * Process a single order item.
     *
     * @since 1.0.0
     *
     * @param \WC_Order      $order The WooCommerce order.
     * @param \WC_Order_Item $item  The order item.
     * @return array|WP_Error|null Processing result, error, or null if not a shelter product.
     */
    private static function process_item( \WC_Order $order, \WC_Order_Item $item ) {
        $product = $item->get_product();
        
        if ( ! $product ) {
            return null;
        }

        $sku = $product->get_sku();
        $config = Product_Mapper::find_by_sku( $sku );

        if ( ! $config ) {
            return null;
        }

        // Get the ability.
        $ability_name = $config['ability'] ?? '';
        
        if ( ! $ability_name || ! wp_has_ability( $ability_name ) ) {
            return new WP_Error(
                'ability_not_found',
                sprintf(
                    /* translators: %s: ability name */
                    __( 'Ability "%s" not found.', 'starter-shelter' ),
                    $ability_name
                )
            );
        }

        // Build input from order/item.
        $input = Product_Mapper::build_input( $order, $item, $config );

        /**
         * Filters the ability input before execution.
         *
         * @since 1.0.0
         *
         * @param array          $input        The ability input.
         * @param string         $ability_name The ability name.
         * @param \WC_Order      $order        The order.
         * @param \WC_Order_Item $item         The order item.
         */
        $input = apply_filters( 'starter_shelter_order_item_input', $input, $ability_name, $order, $item );

        // Execute the ability.
        $ability = wp_get_ability( $ability_name );
        $result = $ability->execute( $input );

        // Store result metadata on the item.
        if ( ! is_wp_error( $result ) ) {
            $item->update_meta_data( '_sd_ability_result', $result );
            $item->save();
        }

        return $result;
    }

    /**
     * Process a subscription renewal.
     *
     * @since 1.0.0
     *
     * @param \WC_Subscription $subscription The subscription object.
     */
    public static function process_renewal( $subscription ): void {
        // Get the renewal order.
        $renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );
        
        if ( empty( $renewal_orders ) ) {
            return;
        }

        // Get the most recent renewal order.
        $renewal_order = reset( $renewal_orders );
        
        if ( ! $renewal_order ) {
            return;
        }

        // Check each item for membership renewals.
        foreach ( $renewal_order->get_items() as $item ) {
            $product = $item->get_product();
            
            if ( ! $product ) {
                continue;
            }

            $config = Product_Mapper::find_by_sku( $product->get_sku() );
            
            if ( ! $config || 'membership' !== ( $config['product_type'] ?? '' ) ) {
                continue;
            }

            // Find the original membership.
            $original_order = $subscription->get_parent();
            $membership_id = self::find_membership_for_order( $original_order->get_id() );

            if ( $membership_id ) {
                // Use the renew ability.
                $ability = wp_get_ability( 'shelter-memberships/renew' );
                
                if ( $ability ) {
                    $input = [
                        'membership_id' => $membership_id,
                        'order_id'      => $renewal_order->get_id(),
                        'amount'        => (float) $item->get_total(),
                    ];

                    $result = $ability->execute( $input );

                    if ( is_wp_error( $result ) ) {
                        $renewal_order->add_order_note(
                            sprintf(
                                /* translators: %s: error message */
                                __( 'Membership renewal failed: %s', 'starter-shelter' ),
                                $result->get_error_message()
                            )
                        );
                    } else {
                        $renewal_order->add_order_note(
                            __( 'Membership renewed successfully.', 'starter-shelter' )
                        );
                    }
                }
            }
        }
    }

    /**
     * Find membership ID associated with an order.
     *
     * @since 1.0.0
     *
     * @param int $order_id The order ID.
     * @return int|null The membership ID or null.
     */
    private static function find_membership_for_order( int $order_id ): ?int {
        $memberships = get_posts( [
            'post_type'      => 'sd_membership',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_wc_order_id',
                    'value' => $order_id,
                ],
            ],
            'fields'         => 'ids',
        ] );

        return ! empty( $memberships ) ? $memberships[0] : null;
    }

    /**
     * Add processing note to order.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order      The order.
     * @param array     $results    Processing results.
     * @param bool      $has_errors Whether errors occurred.
     */
    private static function add_processing_note( \WC_Order $order, array $results, bool $has_errors ): void {
        $note_parts = [];

        foreach ( $results as $item_id => $result ) {
            $item = $order->get_item( $item_id );
            $product_name = $item ? $item->get_name() : "Item #$item_id";

            if ( is_wp_error( $result ) ) {
                $note_parts[] = sprintf(
                    '❌ %s: %s',
                    $product_name,
                    $result->get_error_message()
                );
            } else {
                $note_parts[] = sprintf(
                    '✓ %s: %s',
                    $product_name,
                    self::format_result_summary( $result )
                );
            }
        }

        if ( empty( $note_parts ) ) {
            return;
        }

        $note = __( 'Shelter Donations Processing:', 'starter-shelter' ) . "\n" . implode( "\n", $note_parts );

        if ( $has_errors ) {
            $note .= "\n\n" . __( '⚠️ Some items had errors. Please review and reprocess if needed.', 'starter-shelter' );
        }

        $order->add_order_note( $note );
    }

    /**
     * Format a result summary for the order note.
     *
     * @since 1.0.0
     *
     * @param array $result The ability result.
     * @return string Formatted summary.
     */
    private static function format_result_summary( array $result ): string {
        if ( isset( $result['donation_id'] ) ) {
            return sprintf(
                /* translators: %d: donation ID */
                __( 'Donation #%d created', 'starter-shelter' ),
                $result['donation_id']
            );
        }

        if ( isset( $result['membership_id'] ) ) {
            $tier_label = $result['tier_label'] ?? $result['tier'] ?? '';
            return sprintf(
                /* translators: 1: membership ID, 2: tier label */
                __( 'Membership #%1$d created (%2$s)', 'starter-shelter' ),
                $result['membership_id'],
                $tier_label
            );
        }

        if ( isset( $result['memorial_id'] ) ) {
            $honoree = $result['honoree_name'] ?? '';
            return sprintf(
                /* translators: 1: memorial ID, 2: honoree name */
                __( 'Memorial #%1$d created for %2$s', 'starter-shelter' ),
                $result['memorial_id'],
                $honoree
            );
        }

        return $result['status'] ?? __( 'Processed', 'starter-shelter' );
    }

    /**
     * Add meta box for processing status on order edit screen.
     *
     * @since 1.0.0
     */
    public static function add_processing_meta_box(): void {
        // Get screen ID for HPOS compatibility.
        $screen_id = self::get_order_edit_screen_id();
        
        if ( $screen_id ) {
            add_meta_box(
                'sd_processing_status',
                __( 'Shelter Donations', 'starter-shelter' ),
                [ self::class, 'render_processing_meta_box' ],
                $screen_id,
                'side',
                'default'
            );
        }

        // Legacy support for traditional post-based orders.
        add_meta_box(
            'sd_processing_status',
            __( 'Shelter Donations', 'starter-shelter' ),
            [ self::class, 'render_processing_meta_box' ],
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Get the order edit screen ID, handling HPOS compatibility.
     *
     * @since 1.0.0
     *
     * @return string|null Screen ID or null.
     */
    private static function get_order_edit_screen_id(): ?string {
        // Check if HPOS is enabled.
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                // HPOS is enabled - use the woocommerce_page_wc-orders screen.
                return 'woocommerce_page_wc-orders';
            }
        }

        // Fallback: try to get current screen if we're on the order edit page.
        $screen = get_current_screen();
        
        if ( $screen && 'shop_order' === $screen->post_type ) {
            return $screen->id;
        }

        return null;
    }

    /**
     * Render the processing status meta box.
     *
     * @since 1.0.0
     *
     * @param \WP_Post|\WC_Order $post_or_order The post or order object.
     */
    public static function render_processing_meta_box( $post_or_order ): void {
        $order = $post_or_order instanceof \WC_Order 
            ? $post_or_order 
            : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            return;
        }

        // Check if order has shelter products.
        if ( ! Product_Mapper::order_has_shelter_products( $order ) ) {
            echo '<p>' . esc_html__( 'No shelter products in this order.', 'starter-shelter' ) . '</p>';
            return;
        }

        $processed = $order->get_meta( self::PROCESSED_META_KEY );
        $results = $order->get_meta( self::RESULTS_META_KEY );

        if ( $processed ) {
            echo '<p><strong>' . esc_html__( 'Status:', 'starter-shelter' ) . '</strong> ';
            echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ';
            printf(
                /* translators: %s: processing date */
                esc_html__( 'Processed on %s', 'starter-shelter' ),
                esc_html( $processed )
            );
            echo '</p>';

            // Show results.
            if ( is_array( $results ) && ! empty( $results ) ) {
                echo '<ul style="margin: 10px 0;">';
                foreach ( $results as $item_id => $result ) {
                    $item = $order->get_item( $item_id );
                    $name = $item ? $item->get_name() : "Item #$item_id";
                    
                    if ( is_wp_error( $result ) ) {
                        echo '<li style="color: red;">❌ ' . esc_html( $name ) . ': ' . esc_html( $result->get_error_message() ) . '</li>';
                    } else {
                        echo '<li style="color: green;">✓ ' . esc_html( $name ) . '</li>';
                    }
                }
                echo '</ul>';
            }

            // Reprocess button.
            echo '<button type="button" class="button" id="sd-reprocess-order" data-order-id="' . esc_attr( $order->get_id() ) . '">';
            echo esc_html__( 'Reprocess Order', 'starter-shelter' );
            echo '</button>';
            
            wp_nonce_field( 'sd_reprocess_order', 'sd_reprocess_nonce' );

            // Inline script for reprocess button.
            ?>
            <script>
            jQuery(document).ready(function($) {
                $('#sd-reprocess-order').on('click', function() {
                    if (!confirm('<?php echo esc_js( __( 'Are you sure you want to reprocess this order? This may create duplicate records.', 'starter-shelter' ) ); ?>')) {
                        return;
                    }
                    
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Processing...', 'starter-shelter' ) ); ?>');
                    
                    $.post(ajaxurl, {
                        action: 'sd_reprocess_order',
                        order_id: $btn.data('order-id'),
                        nonce: $('#sd_reprocess_nonce').val()
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || '<?php echo esc_js( __( 'An error occurred.', 'starter-shelter' ) ); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reprocess Order', 'starter-shelter' ) ); ?>');
                        }
                    });
                });
            });
            </script>
            <?php
        } else {
            echo '<p><strong>' . esc_html__( 'Status:', 'starter-shelter' ) . '</strong> ';
            echo '<span class="dashicons dashicons-clock" style="color: orange;"></span> ';
            echo esc_html__( 'Pending processing', 'starter-shelter' );
            echo '</p>';
            echo '<p class="description">' . esc_html__( 'Order will be processed when status changes to Processing or Completed.', 'starter-shelter' ) . '</p>';
        }
    }

    /**
     * AJAX handler for manual order reprocessing.
     *
     * @since 1.0.0
     */
    public static function ajax_reprocess_order(): void {
        check_ajax_referer( 'sd_reprocess_order', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'starter-shelter' ) );
        }

        // Clear processed flag to allow reprocessing.
        $order->delete_meta_data( self::PROCESSED_META_KEY );
        $order->delete_meta_data( self::RESULTS_META_KEY );
        $order->save();

        // Reprocess.
        self::process_order( $order_id );

        wp_send_json_success();
    }

    /**
     * Check if an order has been processed.
     *
     * @since 1.0.0
     *
     * @param int $order_id The order ID.
     * @return bool True if processed.
     */
    public static function is_order_processed( int $order_id ): bool {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return false;
        }

        return (bool) $order->get_meta( self::PROCESSED_META_KEY );
    }

    /**
     * Get processing results for an order.
     *
     * @since 1.0.0
     *
     * @param int $order_id The order ID.
     * @return array|null Results array or null.
     */
    public static function get_processing_results( int $order_id ): ?array {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return null;
        }

        $results = $order->get_meta( self::RESULTS_META_KEY );
        
        return is_array( $results ) ? $results : null;
    }
}
