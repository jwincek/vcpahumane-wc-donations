<?php
/**
 * Legacy Order Sync - Syncs past WooCommerce orders to the donation system.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Helpers;
use Starter_Shelter\WooCommerce\Product_Mapper;
use Starter_Shelter\WooCommerce\Order_Handler;
use WP_Error;

/**
 * Handles syncing of legacy WooCommerce orders to the shelter donation system.
 *
 * This tool allows administrators to:
 * - Scan for orders containing legacy or current shelter products
 * - Preview which orders would be synced
 * - Batch process orders to create donations, memberships, and memorials
 * - Track sync progress and handle errors gracefully
 *
 * @since 1.0.0
 */
class Legacy_Order_Sync {

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'starter-shelter-legacy-sync';

    /**
     * Nonce action.
     *
     * @var string
     */
    private const NONCE_ACTION = 'sd_legacy_sync';

    /**
     * Meta key for tracking synced orders.
     *
     * @var string
     */
    private const SYNCED_META_KEY = '_sd_legacy_synced';

    /**
     * Batch size for processing.
     *
     * @var int
     */
    private const BATCH_SIZE = 25;

    /**
     * Initialize legacy order sync.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        
        // AJAX handlers.
        add_action( 'wp_ajax_sd_scan_legacy_orders', [ self::class, 'ajax_scan_orders' ] );
        add_action( 'wp_ajax_sd_preview_legacy_order', [ self::class, 'ajax_preview_order' ] );
        add_action( 'wp_ajax_sd_sync_legacy_orders', [ self::class, 'ajax_sync_orders' ] );
        add_action( 'wp_ajax_sd_sync_all_legacy_orders', [ self::class, 'ajax_sync_all_orders' ] );
        add_action( 'wp_ajax_sd_get_sync_stats', [ self::class, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_sd_reset_sync_status', [ self::class, 'ajax_reset_sync' ] );
        add_action( 'wp_ajax_sd_debug_order', [ self::class, 'ajax_debug_order' ] );
    }

    /**
     * Add sync page to admin menu.
     *
     * @since 1.0.0
     */
    public static function add_menu_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Legacy Order Sync', 'starter-shelter' ),
            __( 'Legacy Sync', 'starter-shelter' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_assets( string $hook ): void {
        $expected_hook = Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG;
        
        if ( strpos( $hook, self::PAGE_SLUG ) === false && $hook !== $expected_hook ) {
            return;
        }

        // Enqueue WordPress components.
        wp_enqueue_script( 'wp-element' );
        wp_enqueue_script( 'wp-components' );
        wp_enqueue_script( 'wp-i18n' );
        wp_enqueue_script( 'wp-api-fetch' );
        wp_enqueue_style( 'wp-components' );

        // Enqueue our React app.
        wp_enqueue_script(
            'sd-legacy-sync',
            STARTER_SHELTER_URL . 'assets/js/admin-legacy-sync.js',
            [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
            STARTER_SHELTER_VERSION,
            true
        );

        // Get initial stats and config.
        $stats = self::get_sync_stats();
        $product_config = self::get_product_mappings();

        wp_localize_script( 'sd-legacy-sync', 'sdLegacySync', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
            'batchSize'      => self::BATCH_SIZE,
            'stats'          => $stats,
            'productMappings' => $product_config,
            'strings'        => [
                'scanning'      => __( 'Scanning orders...', 'starter-shelter' ),
                'syncing'       => __( 'Syncing orders...', 'starter-shelter' ),
                'complete'      => __( 'Sync complete!', 'starter-shelter' ),
                'error'         => __( 'An error occurred.', 'starter-shelter' ),
                'confirmSync'   => __( 'Are you sure you want to sync these orders? This will create donation records.', 'starter-shelter' ),
                'confirmReset'  => __( 'Are you sure you want to reset sync status? This will allow orders to be synced again.', 'starter-shelter' ),
            ],
        ] );

        // Add inline styles.
        wp_add_inline_style( 'wp-components', self::get_inline_styles() );
    }

    /**
     * Get product mappings for display.
     *
     * @since 1.0.0
     *
     * @return array Product mapping configuration.
     */
    private static function get_product_mappings(): array {
        $products = Config::get_item( 'products', 'products', [] );
        $mappings = [];

        foreach ( $products as $sku_prefix => $config ) {
            $mappings[ $sku_prefix ] = [
                'label'       => $config['description'] ?? ucfirst( str_replace( '-', ' ', $sku_prefix ) ),
                'type'        => $config['product_type'] ?? 'unknown',
                'ability'     => $config['ability'] ?? '',
                'isLegacy'    => $config['legacy'] ?? false,
            ];
        }

        return $mappings;
    }

    /**
     * Get sync statistics.
     *
     * @since 1.0.0
     *
     * @return array Sync statistics.
     */
    public static function get_sync_stats(): array {
        global $wpdb;

        // Count all completed orders.
        $total_orders = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order' 
             AND p.post_status IN ('wc-completed', 'wc-processing')"
        );

        // For HPOS compatibility, also check the orders table if it exists.
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) 
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$orders_table'" ) === $orders_table ) {
                $total_orders = (int) $wpdb->get_var(
                    "SELECT COUNT(DISTINCT id) 
                     FROM {$orders_table}
                     WHERE type = 'shop_order' 
                     AND status IN ('wc-completed', 'wc-processing')"
                );
            }
        }

        // Count orders already synced by this system.
        $synced_orders = self::count_synced_orders();

        // Count orders processed by the standard order handler.
        $processed_orders = self::count_processed_orders();

        // Get order counts by product type.
        $by_type = self::get_order_counts_by_product_type();

        return [
            'total_orders'      => $total_orders,
            'synced_orders'     => $synced_orders,
            'processed_orders'  => $processed_orders,
            'unsynced_orders'   => max( 0, $total_orders - $synced_orders - $processed_orders ),
            'by_type'           => $by_type,
            'last_scan'         => get_option( 'sd_legacy_sync_last_scan', '' ),
        ];
    }

    /**
     * Count orders synced via legacy sync.
     *
     * @since 1.0.0
     *
     * @return int Number of synced orders.
     */
    private static function count_synced_orders(): int {
        global $wpdb;

        // Check both postmeta and HPOS meta tables.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                self::SYNCED_META_KEY
            )
        );

        // HPOS check.
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) 
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $meta_table = $wpdb->prefix . 'wc_orders_meta';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_table'" ) === $meta_table ) {
                $hpos_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT order_id) FROM {$meta_table} WHERE meta_key = %s",
                        self::SYNCED_META_KEY
                    )
                );
                $count = max( $count, $hpos_count );
            }
        }

        return $count;
    }

    /**
     * Count orders processed by standard order handler.
     *
     * @since 1.0.0
     *
     * @return int Number of processed orders.
     */
    private static function count_processed_orders(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_sd_processed'"
        );
    }

    /**
     * Get order counts by product type.
     *
     * @since 1.0.0
     *
     * @return array Counts keyed by product type.
     */
    private static function get_order_counts_by_product_type(): array {
        $products = Config::get_item( 'products', 'products', [] );
        $sku_prefixes = array_keys( $products );
        
        $counts = [
            'donation'   => 0,
            'membership' => 0,
            'memorial'   => 0,
            'unknown'    => 0,
        ];

        if ( empty( $sku_prefixes ) ) {
            return $counts;
        }

        // This is a simplified count - for accuracy we'd need to scan orders.
        // The full scan provides detailed counts.
        return $counts;
    }

    /**
     * Render the sync page.
     *
     * @since 1.0.0
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'starter-shelter' ) );
        }
        ?>
        <div class="wrap sd-legacy-sync">
            <h1><?php esc_html_e( 'Legacy Order Sync', 'starter-shelter' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Sync past WooCommerce orders to create donation, membership, and memorial records.', 'starter-shelter' ); ?>
            </p>
            
            <!-- React app mounts here -->
            <div id="sd-legacy-sync-app"></div>
            
            <noscript>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'JavaScript is required for this feature.', 'starter-shelter' ); ?></p>
                </div>
            </noscript>
        </div>
        <?php
    }

    /**
     * AJAX handler: Scan for syncable orders.
     *
     * @since 1.0.0
     */
    public static function ajax_scan_orders(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $filters = [
            'status'       => sanitize_text_field( $_POST['status'] ?? 'all' ),
            'date_from'    => sanitize_text_field( $_POST['date_from'] ?? '' ),
            'date_to'      => sanitize_text_field( $_POST['date_to'] ?? '' ),
            'product_type' => sanitize_text_field( $_POST['product_type'] ?? 'all' ),
            'include_synced' => ! empty( $_POST['include_synced'] ),
        ];

        $page = absint( $_POST['page'] ?? 1 );
        $per_page = absint( $_POST['per_page'] ?? 50 );

        $result = self::scan_orders( $filters, $page, $per_page );

        // Update last scan time.
        update_option( 'sd_legacy_sync_last_scan', current_time( 'mysql' ) );

        wp_send_json_success( $result );
    }

    /**
     * Scan orders for syncable items.
     *
     * @since 1.0.0
     *
     * @param array $filters Filter parameters.
     * @param int   $page    Page number.
     * @param int   $per_page Items per page.
     * @return array Scan results.
     */
    private static function scan_orders( array $filters, int $page, int $per_page ): array {
        $args = [
            'type'     => 'shop_order',
            'status'   => [ 'wc-completed', 'wc-processing' ],
            'limit'    => $per_page,
            'offset'   => ( $page - 1 ) * $per_page,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'ids',
        ];

        // Apply status filter.
        if ( 'completed' === $filters['status'] ) {
            $args['status'] = [ 'wc-completed' ];
        } elseif ( 'processing' === $filters['status'] ) {
            $args['status'] = [ 'wc-processing' ];
        }

        // Apply date filters.
        if ( ! empty( $filters['date_from'] ) ) {
            $args['date_created'] = '>=' . strtotime( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $args['date_created'] = ( isset( $args['date_created'] ) ? $args['date_created'] . '...' : '' ) 
                                   . strtotime( $filters['date_to'] . ' 23:59:59' );
        }

        // Get orders.
        $order_ids = wc_get_orders( $args );

        // Get total count.
        $args['limit'] = -1;
        $args['offset'] = 0;
        $args['return'] = 'ids';
        $all_order_ids = wc_get_orders( $args );
        $total = count( $all_order_ids );

        $orders = [];
        $summary = [
            'total'      => 0,
            'synced'     => 0,
            'processed'  => 0,
            'unsynced'   => 0,
            'by_type'    => [
                'donation'   => 0,
                'membership' => 0,
                'memorial'   => 0,
            ],
        ];

        foreach ( $order_ids as $order_id ) {
            $order_data = self::analyze_order( $order_id, $filters );
            
            if ( null === $order_data ) {
                continue;
            }

            // Skip synced orders unless requested.
            if ( ! $filters['include_synced'] && $order_data['sync_status'] === 'synced' ) {
                continue;
            }

            // Filter by product type if specified.
            if ( 'all' !== $filters['product_type'] ) {
                $has_type = false;
                foreach ( $order_data['items'] as $item ) {
                    if ( $item['product_type'] === $filters['product_type'] ) {
                        $has_type = true;
                        break;
                    }
                }
                if ( ! $has_type ) {
                    continue;
                }
            }

            $orders[] = $order_data;

            // Update summary.
            $summary['total']++;
            if ( $order_data['sync_status'] === 'synced' ) {
                $summary['synced']++;
            } elseif ( $order_data['sync_status'] === 'processed' ) {
                $summary['processed']++;
            } else {
                $summary['unsynced']++;
            }

            foreach ( $order_data['items'] as $item ) {
                $type = $item['product_type'] ?? 'unknown';
                if ( isset( $summary['by_type'][ $type ] ) ) {
                    $summary['by_type'][ $type ]++;
                }
            }
        }

        return [
            'orders'     => $orders,
            'summary'    => $summary,
            'pagination' => [
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => $total,
                'total_pages' => ceil( $total / $per_page ),
            ],
        ];
    }

    /**
     * Analyze a single order.
     *
     * @since 1.0.0
     *
     * @param int   $order_id The order ID.
     * @param array $filters  Filter parameters.
     * @return array|null Order analysis or null if not relevant.
     */
    private static function analyze_order( int $order_id, array $filters = [] ): ?array {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return null;
        }

        $items = [];
        $has_shelter_products = false;

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            // Skip non-product items.
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            $item_data = self::extract_item_data( $item, $product );
            
            if ( ! $item_data ) {
                continue;
            }

            // Try to find config by various methods.
            $config = self::find_config_for_item( $item, $product, $item_data );

            if ( $config ) {
                $has_shelter_products = true;
                
                $items[] = [
                    'item_id'      => $item_id,
                    'product_id'   => $item_data['product_id'],
                    'variation_id' => $item_data['variation_id'],
                    'name'         => $item_data['name'],
                    'sku'          => $item_data['sku'],
                    'amount'       => (float) $item->get_total(),
                    'product_type' => $config['product_type'] ?? 'unknown',
                    'ability'      => $config['ability'] ?? '',
                    'is_legacy'    => $config['legacy'] ?? true,
                    'sku_prefix'   => $config['sku_prefix'] ?? '',
                    'item_meta'    => $item_data['meta'],
                ];
            }
        }

        if ( ! $has_shelter_products ) {
            return null;
        }

        // Determine sync status.
        $sync_status = 'unsynced';
        if ( $order->get_meta( self::SYNCED_META_KEY ) ) {
            $sync_status = 'synced';
        } elseif ( $order->get_meta( '_sd_processed' ) ) {
            $sync_status = 'processed';
        }

        return [
            'order_id'      => $order_id,
            'order_number'  => $order->get_order_number(),
            'date'          => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
            'date_formatted' => $order->get_date_created() ? $order->get_date_created()->format( get_option( 'date_format' ) ) : '',
            'status'        => $order->get_status(),
            'total'         => (float) $order->get_total(),
            'total_formatted' => wp_strip_all_tags( wc_price( $order->get_total() ) ),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'items'         => $items,
            'sync_status'   => $sync_status,
            'synced_at'     => $order->get_meta( self::SYNCED_META_KEY ) ?: null,
            'edit_url'      => $order->get_edit_order_url(),
        ];
    }

    /**
     * Extract item data from order item, even if product doesn't exist.
     *
     * WooCommerce stores item data directly on the order item, so we can
     * retrieve product info even for deleted/orphaned products.
     *
     * @since 1.0.0
     *
     * @param \WC_Order_Item_Product $item    The order item.
     * @param \WC_Product|false      $product The product object (may be false).
     * @return array|null Item data or null.
     */
    private static function extract_item_data( $item, $product ): ?array {
        // Ensure this is a product item.
        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return null;
        }

        // Get basic item data - WooCommerce stores this on the item itself.
        // These methods work even when the product no longer exists.
        $data = [
            'name'         => $item->get_name(),
            'product_id'   => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'quantity'     => $item->get_quantity(),
            'sku'          => '',
            'meta'         => [],
            'attributes'   => [],
        ];

        // If product exists, use it for SKU.
        if ( $product && $product instanceof \WC_Product ) {
            $data['sku'] = $product->get_sku();
            
            // For variations, also try to get parent SKU.
            if ( empty( $data['sku'] ) && $product->is_type( 'variation' ) ) {
                $parent = wc_get_product( $product->get_parent_id() );
                if ( $parent ) {
                    $data['sku'] = $parent->get_sku();
                    $data['parent_sku'] = $parent->get_sku();
                    $data['parent_name'] = $parent->get_name();
                }
            }
        } else {
            // Product doesn't exist - try multiple fallbacks.
            
            // 1. Check if WooCommerce stored the SKU as item meta.
            $stored_sku = $item->get_meta( '_sku', true );
            if ( $stored_sku ) {
                $data['sku'] = $stored_sku;
            }
            
            // 2. For variations, try to load the parent product.
            if ( $data['variation_id'] && $data['product_id'] ) {
                $parent = wc_get_product( $data['product_id'] );
                if ( $parent && $parent instanceof \WC_Product ) {
                    $data['parent_sku'] = $parent->get_sku();
                    $data['parent_name'] = $parent->get_name();
                    
                    // Use parent SKU if variation SKU not found.
                    if ( empty( $data['sku'] ) ) {
                        $data['sku'] = $parent->get_sku();
                    }
                }
            }
            
            // 3. Try to get product even if variation_id is set
            // (sometimes the parent product exists but not the variation).
            if ( empty( $data['sku'] ) && $data['product_id'] ) {
                $maybe_parent = wc_get_product( $data['product_id'] );
                if ( $maybe_parent && $maybe_parent instanceof \WC_Product ) {
                    $data['sku'] = $maybe_parent->get_sku();
                    $data['parent_sku'] = $maybe_parent->get_sku();
                    $data['parent_name'] = $maybe_parent->get_name();
                }
            }
        }

        // Extract all item meta - this contains variation attributes and custom fields.
        $item_meta = $item->get_meta_data();
        foreach ( $item_meta as $meta ) {
            $key = $meta->key;
            $value = $meta->value;
            
            // Skip internal WooCommerce meta (except our custom _sd_ meta).
            if ( str_starts_with( $key, '_' ) && ! str_starts_with( $key, '_sd_' ) ) {
                continue;
            }
            
            $data['meta'][ $key ] = $value;
        }

        // Get formatted item meta (variation attributes) - this is what WooCommerce displays.
        $formatted_meta = $item->get_formatted_meta_data( '_', true );
        foreach ( $formatted_meta as $meta ) {
            $data['meta'][ $meta->key ] = $meta->value;
            
            // Also store in a normalized format for easier matching.
            $normalized_key = strtolower( str_replace( [ ' ', '-' ], '_', $meta->key ) );
            $data['attributes'][ $normalized_key ] = $meta->value;
        }

        // Legacy WooCommerce stores variation attributes with specific keys.
        // Try common attribute patterns.
        $attribute_keys = [
            'pa_membership-level',
            'membership-level', 
            'Membership Level',
            'pa_preferred-allocation',
            'preferred-allocation',
            'Preferred Allocation',
            'pa_in-memoriam-type',
            'in-memoriam-type',
            'In Memoriam Type',
        ];

        foreach ( $attribute_keys as $key ) {
            $value = $item->get_meta( $key, true );
            if ( $value ) {
                $data['meta'][ $key ] = $value;
                $normalized_key = strtolower( str_replace( [ ' ', '-', 'pa_' ], [ '_', '_', '' ], $key ) );
                $data['attributes'][ $normalized_key ] = $value;
            }
        }

        // Return null only if we have no name to work with.
        if ( empty( $data['name'] ) ) {
            return null;
        }

        return $data;
    }

    /**
     * Find configuration for an order item using multiple strategies.
     *
     * @since 1.0.0
     *
     * @param \WC_Order_Item_Product $item     The order item.
     * @param \WC_Product|false      $product  The product object (may be false).
     * @param array                  $item_data Extracted item data.
     * @return array|null Product config or null.
     */
    private static function find_config_for_item( $item, $product, array $item_data ): ?array {
        $config = null;

        // Strategy 1: Try by SKU if product exists.
        if ( $product && ! empty( $item_data['sku'] ) ) {
            $config = Product_Mapper::find_by_sku( $item_data['sku'] );
        }

        // Strategy 2: Try by product ID from sync config.
        if ( ! $config ) {
            $config = self::find_config_by_product_id( $item_data['product_id'], $item_data['variation_id'] );
        }

        // Strategy 3: Try by item name pattern matching.
        if ( ! $config ) {
            $config = self::find_config_by_name( $item_data['name'] );
        }

        // Strategy 4: For variations, try the parent product.
        if ( ! $config && $item_data['variation_id'] && $item_data['product_id'] ) {
            // Try parent product ID.
            $config = self::find_config_by_product_id( $item_data['product_id'], 0 );
            
            // Try loading parent product if it exists.
            if ( ! $config ) {
                $parent = wc_get_product( $item_data['product_id'] );
                if ( $parent ) {
                    $parent_sku = $parent->get_sku();
                    if ( $parent_sku ) {
                        $config = Product_Mapper::find_by_sku( $parent_sku );
                    }
                    if ( ! $config ) {
                        $config = self::find_config_by_name( $parent->get_name() );
                    }
                }
            }
        }

        // Strategy 5: Try to infer from item meta (variation attributes).
        if ( ! $config && ! empty( $item_data['meta'] ) ) {
            $config = self::find_config_by_item_meta( $item_data['meta'], $item_data['name'] );
        }

        return $config;
    }

    /**
     * Find config by product ID using the sync configuration.
     *
     * @since 1.0.0
     *
     * @param int $product_id   The product ID.
     * @param int $variation_id The variation ID (0 for parent).
     * @return array|null Product config or null.
     */
    private static function find_config_by_product_id( int $product_id, int $variation_id ): ?array {
        $sync_config = Config::get_path( 'sync', 'product_mappings.by_product_id', [] );
        $products = Config::get_item( 'products', 'products', [] );

        // Check variation ID first, then product ID.
        $ids_to_check = array_filter( [ $variation_id, $product_id ] );
        
        foreach ( $ids_to_check as $id ) {
            $id_string = (string) $id;
            if ( isset( $sync_config[ $id_string ] ) ) {
                $mapping = $sync_config[ $id_string ];
                
                // If this has a parent reference, get the parent config.
                if ( isset( $mapping['parent'] ) ) {
                    $parent_mapping = $sync_config[ $mapping['parent'] ] ?? [];
                    $sku_prefix = $parent_mapping['maps_to'] ?? '';
                } else {
                    $sku_prefix = $mapping['maps_to'] ?? '';
                }

                if ( $sku_prefix && isset( $products[ $sku_prefix ] ) ) {
                    return array_merge(
                        $products[ $sku_prefix ],
                        [ 
                            'sku_prefix' => $sku_prefix,
                            'legacy' => true,
                            'sync_mapping' => $mapping,
                        ]
                    );
                }
            }
        }

        return null;
    }

    /**
     * Find config by analyzing item meta (variation attributes).
     *
     * @since 1.0.0
     *
     * @param array  $meta Item meta data.
     * @param string $name Item name.
     * @return array|null Product config or null.
     */
    private static function find_config_by_item_meta( array $meta, string $name ): ?array {
        $products = Config::get_item( 'products', 'products', [] );
        $name_lower = strtolower( $name );

        // Check for membership level attribute.
        $membership_level = $meta['pa_membership-level'] 
            ?? $meta['membership-level'] 
            ?? $meta['Membership Level'] 
            ?? null;

        if ( $membership_level ) {
            // Determine if business or individual.
            if ( strpos( $name_lower, 'business' ) !== false ) {
                if ( isset( $products['shelter-memberships-business'] ) ) {
                    return array_merge(
                        $products['shelter-memberships-business'],
                        [ 'sku_prefix' => 'shelter-memberships-business', 'legacy' => true ]
                    );
                }
            } elseif ( strpos( $name_lower, 'shelter' ) !== false ) {
                if ( isset( $products['shelter-memberships'] ) ) {
                    return array_merge(
                        $products['shelter-memberships'],
                        [ 'sku_prefix' => 'shelter-memberships', 'legacy' => true ]
                    );
                }
            } else {
                // Legacy "Memberships" product.
                if ( isset( $products['memberships'] ) ) {
                    return array_merge(
                        $products['memberships'],
                        [ 'sku_prefix' => 'memberships', 'legacy' => true ]
                    );
                }
            }
        }

        // Check for memorial type attribute.
        $memorial_type = $meta['pa_in-memoriam-type'] 
            ?? $meta['in-memoriam-type'] 
            ?? $meta['In Memoriam Type'] 
            ?? null;

        if ( $memorial_type || strpos( $name_lower, 'memoriam' ) !== false || strpos( $name_lower, 'memorial' ) !== false ) {
            if ( isset( $products['shelter-donations-in-memoriam'] ) ) {
                return array_merge(
                    $products['shelter-donations-in-memoriam'],
                    [ 'sku_prefix' => 'shelter-donations-in-memoriam', 'legacy' => true ]
                );
            }
        }

        // Check for allocation attribute (donations).
        $allocation = $meta['pa_preferred-allocation'] 
            ?? $meta['preferred-allocation'] 
            ?? $meta['Preferred Allocation'] 
            ?? null;

        if ( $allocation || strpos( $name_lower, 'shelter donations' ) !== false ) {
            if ( isset( $products['shelter-donations'] ) ) {
                return array_merge(
                    $products['shelter-donations'],
                    [ 'sku_prefix' => 'shelter-donations', 'legacy' => true ]
                );
            }
        }

        return null;
    }

    /**
     * Find product config by name (for products without SKU).
     *
     * @since 1.0.0
     *
     * @param string $name Product name.
     * @return array|null Product config or null.
     */
    private static function find_config_by_name( string $name ): ?array {
        $name_lower = strtolower( $name );
        $products = Config::get_item( 'products', 'products', [] );

        // Map common product name patterns to SKU prefixes.
        // Order matters - more specific patterns should come first.
        $name_patterns = [
            // Business memberships (check before general memberships).
            'shelter business memberships' => 'shelter-memberships-business',
            'business memberships'         => 'shelter-memberships-business',
            'business membership'          => 'shelter-memberships-business',
            
            // Individual memberships.
            'shelter memberships'          => 'shelter-memberships',
            'shelter membership'           => 'shelter-memberships',
            
            // Legacy memberships (no "shelter" prefix) - these are the old products.
            'memberships -'                => 'memberships',
            'membership -'                 => 'memberships',
            
            // Donations.
            'shelter donations'            => 'shelter-donations',
            'shelter donation'             => 'shelter-donations',
            
            // In Memoriam.
            'in memoriam donations'        => 'shelter-donations-in-memoriam',
            'in memoriam donation'         => 'shelter-donations-in-memoriam',
            'in memoriam'                  => 'shelter-donations-in-memoriam',
            'memorial donation'            => 'shelter-donations-in-memoriam',
            'memorial'                     => 'shelter-donations-in-memoriam',
        ];

        foreach ( $name_patterns as $pattern => $sku_prefix ) {
            if ( strpos( $name_lower, $pattern ) !== false && isset( $products[ $sku_prefix ] ) ) {
                return array_merge( 
                    $products[ $sku_prefix ], 
                    [ 
                        'sku_prefix' => $sku_prefix,
                        'legacy' => true,
                        'matched_by' => 'name_pattern',
                        'matched_pattern' => $pattern,
                    ] 
                );
            }
        }

        return null;
    }

    /**
     * AJAX handler: Preview single order sync.
     *
     * @since 1.0.0
     */
    public static function ajax_preview_order(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        
        if ( ! $order_id ) {
            wp_send_json_error( __( 'Invalid order ID.', 'starter-shelter' ) );
        }

        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'starter-shelter' ) );
        }

        $preview = self::build_sync_preview( $order );
        
        wp_send_json_success( $preview );
    }

    /**
     * Build sync preview for an order.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order The order object.
     * @return array Preview data.
     */
    private static function build_sync_preview( \WC_Order $order ): array {
        $preview = [
            'order_id'   => $order->get_id(),
            'items'      => [],
            'donor'      => [
                'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'existing' => null,
            ],
        ];

        // Check for existing donor.
        $existing_donors = get_posts( [
            'post_type'   => 'sd_donor',
            'meta_key'    => '_sd_email',
            'meta_value'  => $order->get_billing_email(),
            'numberposts' => 1,
        ] );

        if ( ! empty( $existing_donors ) ) {
            $preview['donor']['existing'] = [
                'id'    => $existing_donors[0]->ID,
                'name'  => $existing_donors[0]->post_title,
            ];
        }

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            
            if ( ! $product ) {
                continue;
            }

            $sku = $product->get_sku();
            $config = Product_Mapper::find_by_sku( $sku );

            if ( ! $config ) {
                $config = self::find_config_by_name( $product->get_name() );
            }

            if ( ! $config ) {
                continue;
            }

            // Build input that would be used.
            $input = Product_Mapper::build_input( $order, $item, $config );

            $preview['items'][] = [
                'item_id'      => $item_id,
                'name'         => $item->get_name(),
                'product_type' => $config['product_type'] ?? 'unknown',
                'ability'      => $config['ability'] ?? '',
                'input'        => $input,
                'will_create'  => self::describe_creation( $config['product_type'] ?? '', $input ),
            ];
        }

        return $preview;
    }

    /**
     * Describe what will be created.
     *
     * @since 1.0.0
     *
     * @param string $type  Product type.
     * @param array  $input Ability input.
     * @return string Description.
     */
    private static function describe_creation( string $type, array $input ): string {
        switch ( $type ) {
            case 'donation':
                $allocation = $input['allocation'] ?? 'General Fund';
                return sprintf(
                    /* translators: 1: amount, 2: allocation */
                    __( 'Donation of %1$s to %2$s', 'starter-shelter' ),
                    wc_price( $input['amount'] ?? 0 ),
                    ucwords( str_replace( '-', ' ', $allocation ) )
                );

            case 'membership':
                $type_label = ( $input['membership_type'] ?? 'individual' ) === 'business' 
                    ? __( 'Business', 'starter-shelter' ) 
                    : __( 'Individual', 'starter-shelter' );
                $tier = ucwords( str_replace( '-', ' ', $input['tier'] ?? 'unknown' ) );
                return sprintf(
                    /* translators: 1: membership type, 2: tier */
                    __( '%1$s Membership: %2$s', 'starter-shelter' ),
                    $type_label,
                    $tier
                );

            case 'memorial':
                $memorial_type = ucfirst( $input['memorial_type'] ?? 'person' );
                $honoree = $input['honoree_name'] ?? __( 'Unknown', 'starter-shelter' );
                return sprintf(
                    /* translators: 1: memorial type, 2: honoree name */
                    __( '%1$s Memorial for %2$s', 'starter-shelter' ),
                    $memorial_type,
                    $honoree
                );

            default:
                return __( 'Unknown record type', 'starter-shelter' );
        }
    }

    /**
     * AJAX handler: Sync selected orders.
     *
     * @since 1.0.0
     */
    public static function ajax_sync_orders(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];
        $skip_errors = ! empty( $_POST['skip_errors'] );
        $dry_run = ! empty( $_POST['dry_run'] );
        $force_resync = ! empty( $_POST['force_resync'] );

        if ( empty( $order_ids ) ) {
            wp_send_json_error( __( 'No orders selected.', 'starter-shelter' ) );
        }

        $results = [
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'created'   => [
                'donations'   => 0,
                'memberships' => 0,
                'memorials'   => 0,
                'donors'      => 0,
            ],
            'details'   => [],
        ];

        foreach ( $order_ids as $order_id ) {
            $result = self::sync_single_order( $order_id, $dry_run, $force_resync );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
                $results['details'][] = [
                    'order_id' => $order_id,
                    'status'   => 'error',
                    'message'  => $result->get_error_message(),
                ];

                if ( ! $skip_errors ) {
                    break;
                }
            } elseif ( $result['skipped'] ?? false ) {
                $results['skipped']++;
                $results['details'][] = [
                    'order_id' => $order_id,
                    'status'   => 'skipped',
                    'message'  => $result['reason'] ?? '',
                ];
            } else {
                $results['processed']++;
                $results['created']['donations'] += $result['created']['donations'] ?? 0;
                $results['created']['memberships'] += $result['created']['memberships'] ?? 0;
                $results['created']['memorials'] += $result['created']['memorials'] ?? 0;
                $results['created']['donors'] += $result['created']['donors'] ?? 0;
                $results['details'][] = [
                    'order_id' => $order_id,
                    'status'   => 'success',
                    'created'  => $result['created'] ?? [],
                    'errors'   => $result['errors'] ?? [],
                ];
            }
        }

        wp_send_json_success( $results );
    }

    /**
     * AJAX handler: Sync all matching orders (with pagination).
     *
     * @since 1.0.0
     */
    public static function ajax_sync_all_orders(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $filters = [
            'status'         => sanitize_text_field( $_POST['status'] ?? 'all' ),
            'date_from'      => sanitize_text_field( $_POST['date_from'] ?? '' ),
            'date_to'        => sanitize_text_field( $_POST['date_to'] ?? '' ),
            'product_type'   => sanitize_text_field( $_POST['product_type'] ?? 'all' ),
            'include_synced' => ! empty( $_POST['include_synced'] ),
        ];

        $force_resync = ! empty( $_POST['force_resync'] );
        $batch_size = absint( $_POST['batch_size'] ?? 50 );
        $offset = absint( $_POST['offset'] ?? 0 );

        // Get all matching order IDs (without per-page limit).
        $order_ids = self::get_all_syncable_order_ids( $filters, $force_resync );
        $total_orders = count( $order_ids );

        if ( $total_orders === 0 ) {
            wp_send_json_success( [
                'complete'  => true,
                'processed' => 0,
                'total'     => 0,
                'message'   => __( 'No orders to sync.', 'starter-shelter' ),
            ] );
        }

        // Get the batch to process.
        $batch_ids = array_slice( $order_ids, $offset, $batch_size );
        
        if ( empty( $batch_ids ) ) {
            wp_send_json_success( [
                'complete'  => true,
                'processed' => $offset,
                'total'     => $total_orders,
                'message'   => __( 'Sync complete.', 'starter-shelter' ),
            ] );
        }

        $results = [
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'created'   => [
                'donations'   => 0,
                'memberships' => 0,
                'memorials'   => 0,
                'donors'      => 0,
                'updated'     => 0,
            ],
        ];

        foreach ( $batch_ids as $order_id ) {
            $result = self::sync_single_order( $order_id, false, $force_resync );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
            } elseif ( $result['skipped'] ?? false ) {
                $results['skipped']++;
            } else {
                $results['processed']++;
                $results['created']['donations'] += $result['created']['donations'] ?? 0;
                $results['created']['memberships'] += $result['created']['memberships'] ?? 0;
                $results['created']['memorials'] += $result['created']['memorials'] ?? 0;
                $results['created']['donors'] += $result['created']['donors'] ?? 0;
                $results['created']['updated'] += $result['created']['updated'] ?? 0;
            }
        }

        $new_offset = $offset + count( $batch_ids );
        $complete = $new_offset >= $total_orders;

        wp_send_json_success( [
            'complete'    => $complete,
            'processed'   => $results['processed'],
            'skipped'     => $results['skipped'],
            'errors'      => $results['errors'],
            'created'     => $results['created'],
            'batch_size'  => count( $batch_ids ),
            'offset'      => $new_offset,
            'total'       => $total_orders,
            'progress'    => round( ( $new_offset / $total_orders ) * 100 ),
        ] );
    }

    /**
     * Get all syncable order IDs matching filters.
     *
     * @since 1.0.0
     *
     * @param array $filters      Filter parameters.
     * @param bool  $force_resync Whether to include already synced orders.
     * @return array Array of order IDs.
     */
    private static function get_all_syncable_order_ids( array $filters, bool $force_resync = false ): array {
        $args = [
            'type'     => 'shop_order',
            'status'   => [ 'wc-completed', 'wc-processing' ],
            'limit'    => -1,
            'orderby'  => 'date',
            'order'    => 'ASC', // Process oldest first.
            'return'   => 'ids',
        ];

        // Apply status filter.
        if ( 'completed' === $filters['status'] ) {
            $args['status'] = [ 'wc-completed' ];
        } elseif ( 'processing' === $filters['status'] ) {
            $args['status'] = [ 'wc-processing' ];
        }

        // Apply date filters.
        if ( ! empty( $filters['date_from'] ) ) {
            $args['date_created'] = '>=' . strtotime( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $args['date_created'] = ( isset( $args['date_created'] ) ? $args['date_created'] . '...' : '' ) 
                                   . strtotime( $filters['date_to'] . ' 23:59:59' );
        }

        // Exclude already synced orders unless force_resync or include_synced.
        if ( ! $force_resync && empty( $filters['include_synced'] ) ) {
            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'key'     => self::SYNCED_META_KEY,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_sd_processed',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        $order_ids = wc_get_orders( $args );

        // Filter to only orders with shelter products.
        $syncable_ids = [];
        foreach ( $order_ids as $order_id ) {
            $order_data = self::analyze_order( $order_id, $filters );
            if ( $order_data && ! empty( $order_data['items'] ) ) {
                // Apply product type filter.
                if ( ! empty( $filters['product_type'] ) && 'all' !== $filters['product_type'] ) {
                    $has_type = false;
                    foreach ( $order_data['items'] as $item ) {
                        if ( $item['product_type'] === $filters['product_type'] ) {
                            $has_type = true;
                            break;
                        }
                    }
                    if ( ! $has_type ) {
                        continue;
                    }
                }
                $syncable_ids[] = $order_id;
            }
        }

        return $syncable_ids;
    }

    /**
     * Sync a single order.
     *
     * @since 1.0.0
     *
     * @param int  $order_id     Order ID.
     * @param bool $dry_run      Whether to perform a dry run.
     * @param bool $force_resync Whether to force resync even if already synced.
     * @return array|WP_Error Sync results or error.
     */
    private static function sync_single_order( int $order_id, bool $dry_run = false, bool $force_resync = false ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'order_not_found', __( 'Order not found.', 'starter-shelter' ) );
        }

        // Check if already synced (unless force resync).
        if ( ! $force_resync && $order->get_meta( self::SYNCED_META_KEY ) ) {
            return [
                'skipped' => true,
                'reason'  => __( 'Already synced.', 'starter-shelter' ),
            ];
        }

        // Check if processed by standard handler (unless force resync).
        if ( ! $force_resync && $order->get_meta( '_sd_processed' ) ) {
            return [
                'skipped' => true,
                'reason'  => __( 'Already processed by order handler.', 'starter-shelter' ),
            ];
        }

        $created = [
            'donations'   => 0,
            'memberships' => 0,
            'memorials'   => 0,
            'donors'      => 0,
        ];

        $item_results = [];
        $errors = [];

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            // Skip non-product items.
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            
            // Use the same extraction logic as analyze_order.
            $item_data = self::extract_item_data( $item, $product );
            
            if ( ! $item_data ) {
                continue;
            }

            // Find config using multiple strategies (same as analyze_order).
            $config = self::find_config_for_item( $item, $product, $item_data );

            if ( ! $config ) {
                continue;
            }

            $ability_name = $config['ability'] ?? '';
            
            if ( ! $ability_name ) {
                $errors[] = sprintf( 
                    __( 'No ability configured for item "%s"', 'starter-shelter' ), 
                    $item_data['name'] 
                );
                continue;
            }
            
            if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
                $errors[] = sprintf( 
                    __( 'Ability "%s" not found for item "%s"', 'starter-shelter' ), 
                    $ability_name,
                    $item_data['name'] 
                );
                continue;
            }

            if ( $dry_run ) {
                // Just count what would be created.
                $type = $config['product_type'] ?? 'unknown';
                if ( isset( $created[ $type . 's' ] ) ) {
                    $created[ $type . 's' ]++;
                }
                continue;
            }

            // Build input for the ability.
            // For legacy products without a product object, we need to build input manually.
            if ( $product ) {
                $input = Product_Mapper::build_input( $order, $item, $config );
            } else {
                $input = self::build_legacy_input( $order, $item, $item_data, $config );
            }

            // Check for existing record to update instead of create.
            $product_type = $config['product_type'] ?? '';
            $existing_id = self::find_existing_record( $order, $item_id, $product_type, $input );
            
            if ( $existing_id ) {
                // Update existing record instead of creating new one.
                $result = self::update_existing_record( $existing_id, $product_type, $input );
                $action_type = 'updated';
            } else {
                // Execute the create ability.
                $ability = wp_get_ability( $ability_name );
                $result = $ability->execute( $input );
                $action_type = 'created';
            }

            if ( is_wp_error( $result ) ) {
                $item_results[ $item_id ] = [
                    'error' => $result->get_error_message(),
                ];
                $errors[] = sprintf(
                    __( 'Error processing "%s": %s', 'starter-shelter' ),
                    $item_data['name'],
                    $result->get_error_message()
                );
            } else {
                $item_results[ $item_id ] = array_merge( 
                    is_array( $result ) ? $result : [ 'id' => $result ],
                    [ 'action' => $action_type ]
                );
                
                // Count based on action type.
                $type = $config['product_type'] ?? 'unknown';
                $count_key = $action_type === 'updated' ? 'updated' : $type . 's';
                if ( ! isset( $created[ $count_key ] ) ) {
                    $created[ $count_key ] = 0;
                }
                $created[ $count_key ]++;
                
                // Also count in type-specific key for created.
                if ( $action_type === 'created' && isset( $created[ $type . 's' ] ) ) {
                    $created[ $type . 's' ]++;
                }
                
                // Check if donor was created.
                if ( ! empty( $result['donor_created'] ) ) {
                    $created['donors']++;
                }
            }
        }

        if ( ! $dry_run ) {
            // Mark as synced.
            $order->update_meta_data( self::SYNCED_META_KEY, current_time( 'mysql' ) );
            $order->update_meta_data( '_sd_legacy_sync_results', $item_results );
            $order->save();

            // Add order note.
            $updated_count = $created['updated'] ?? 0;
            $note = sprintf(
                /* translators: 1: donations count, 2: memberships count, 3: memorials count, 4: updated count */
                __( 'Shelter Donations legacy sync completed: %1$d donation(s), %2$d membership(s), %3$d memorial(s), %4$d updated', 'starter-shelter' ),
                $created['donations'],
                $created['memberships'],
                $created['memorials'],
                $updated_count
            );
            
            if ( ! empty( $errors ) ) {
                $note .= "\n" . __( 'Errors:', 'starter-shelter' ) . "\n- " . implode( "\n- ", $errors );
            }
            
            $order->add_order_note( $note );

            /**
             * Fires after an order has been synced via legacy sync.
             *
             * @since 1.0.0
             *
             * @param int   $order_id     The order ID.
             * @param array $created      Counts of created records.
             * @param array $item_results Results for each item.
             */
            do_action( 'starter_shelter_legacy_order_synced', $order_id, $created, $item_results );
        }

        return [
            'created'      => $created,
            'item_results' => $item_results,
            'errors'       => $errors,
        ];
    }

    /**
     * Build ability input for legacy products without a product object.
     *
     * @since 1.0.0
     *
     * @param \WC_Order              $order     The order.
     * @param \WC_Order_Item_Product $item      The order item.
     * @param array                  $item_data Extracted item data.
     * @param array                  $config    Product config.
     * @return array Ability input.
     */
    private static function build_legacy_input( \WC_Order $order, \WC_Order_Item_Product $item, array $item_data, array $config ): array {
        // Get order date for use as display/donation date.
        $order_date = $order->get_date_created();
        $date_string = $order_date ? $order_date->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

        // Base input from order.
        $input = [
            'order_id'    => $order->get_id(),
            'amount'      => (float) $item->get_total(),
            'donor_email' => $order->get_billing_email(),
            'donor_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'date'        => $date_string,
        ];

        $product_type = $config['product_type'] ?? '';

        // Extract data based on product type.
        switch ( $product_type ) {
            case 'membership':
                // Get tier from item meta or name.
                $tier = self::extract_tier_from_item( $item_data );
                if ( $tier ) {
                    $input['tier'] = $tier;
                }
                
                // Determine membership type from config or name.
                $input['membership_type'] = $config['sync_mapping']['membership_type'] 
                    ?? ( strpos( strtolower( $item_data['name'] ), 'business' ) !== false ? 'business' : 'individual' );
                
                // For business memberships, get company name.
                if ( $input['membership_type'] === 'business' ) {
                    $input['business_name'] = $order->get_billing_company() ?: $input['donor_name'];
                }
                break;

            case 'memorial':
                // Get memorial type from item meta or name.
                $memorial_type = self::extract_memorial_type_from_item( $item_data );
                $input['memorial_type'] = $memorial_type ?: 'person';
                
                // Honoree name - check item meta first (legacy field), then order meta.
                $honoree_name = $item->get_meta( 'In Memory Of', true )
                    ?: ( $item->get_meta( 'in-memory-of', true )
                    ?: ( $item->get_meta( 'Honoree Name', true )
                    ?: ( $item_data['meta']['In Memory Of'] ?? '' ) ) );
                
                if ( empty( $honoree_name ) ) {
                    $honoree_name = $order->get_meta( '_sd_honoree_name' )
                        ?: __( 'In Loving Memory', 'starter-shelter' );
                }
                $input['honoree_name'] = $honoree_name;
                
                // Tribute message - check item meta first.
                $tribute = $item->get_meta( 'Tribute Message', true )
                    ?: ( $item->get_meta( 'tribute-message', true )
                    ?: ( $item_data['meta']['Tribute Message'] ?? '' ) );
                
                if ( empty( $tribute ) ) {
                    $tribute = $order->get_meta( '_sd_tribute_message' );
                }
                if ( $tribute ) {
                    $input['tribute_message'] = $tribute;
                }
                
                // Pet species for pet memorials.
                if ( $memorial_type === 'pet' ) {
                    $pet_species = $item->get_meta( 'Pet Species', true )
                        ?: ( $item->get_meta( 'pet-species', true )
                        ?: ( $item_data['meta']['Pet Species'] ?? '' ) );
                    
                    if ( empty( $pet_species ) ) {
                        $pet_species = $order->get_meta( '_sd_pet_species' ) ?: '';
                    }
                    $input['pet_species'] = $pet_species;
                }
                break;

            case 'donation':
                // Get allocation from item meta or name.
                $allocation = self::extract_allocation_from_item( $item_data );
                $input['allocation'] = $allocation ?: 'general-fund';
                
                // Dedication.
                $dedication = $order->get_meta( '_sd_dedication' );
                if ( $dedication ) {
                    $input['dedication'] = $dedication;
                }
                break;
        }

        // Check for anonymous flag.
        $is_anonymous = $order->get_meta( '_sd_is_anonymous' );
        if ( $is_anonymous ) {
            $input['is_anonymous'] = (bool) $is_anonymous;
        }

        /**
         * Filters the legacy input before ability execution.
         *
         * @since 1.0.0
         *
         * @param array                  $input     The ability input.
         * @param \WC_Order              $order     The order.
         * @param \WC_Order_Item_Product $item      The order item.
         * @param array                  $item_data Extracted item data.
         * @param array                  $config    Product config.
         */
        return apply_filters( 'starter_shelter_legacy_sync_input', $input, $order, $item, $item_data, $config );
    }

    /**
     * Find an existing record that matches the order/item combination.
     *
     * @since 1.0.0
     *
     * @param \WC_Order $order        The WooCommerce order.
     * @param int       $item_id      The order item ID.
     * @param string    $product_type The product type (donation, membership, memorial).
     * @param array     $input        The input data being synced.
     * @return int|null Existing post ID or null if not found.
     */
    private static function find_existing_record( \WC_Order $order, int $item_id, string $product_type, array $input ): ?int {
        $post_type = match ( $product_type ) {
            'donation'   => 'sd_donation',
            'membership' => 'sd_membership',
            'memorial'   => 'sd_memorial',
            default      => null,
        };

        if ( ! $post_type ) {
            return null;
        }

        $order_id = $order->get_id();

        // Strategy 1: Look for record linked to this specific order.
        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_wc_order_id',
                    'value' => $order_id,
                    'type'  => 'NUMERIC',
                ],
            ],
            'fields'         => 'ids',
        ];

        // For memorials, also match by honoree name to avoid duplicates.
        if ( $product_type === 'memorial' && ! empty( $input['honoree_name'] ) ) {
            $args['meta_query'][] = [
                'key'   => '_sd_honoree_name',
                'value' => $input['honoree_name'],
            ];
        }

        // For memberships, also match by donor to avoid duplicates.
        if ( $product_type === 'membership' && ! empty( $input['donor_email'] ) ) {
            // First find the donor by email.
            $donor_id = self::find_donor_by_email( $input['donor_email'] );
            if ( $donor_id ) {
                $args['meta_query'][] = [
                    'key'   => '_sd_donor_id',
                    'value' => $donor_id,
                    'type'  => 'NUMERIC',
                ];
            }
        }

        $posts = get_posts( $args );
        
        if ( ! empty( $posts ) ) {
            return $posts[0];
        }

        // Strategy 2: For memorials, check if there's a memorial with same honoree from same donor.
        if ( $product_type === 'memorial' && ! empty( $input['honoree_name'] ) && ! empty( $input['donor_email'] ) ) {
            $donor_id = self::find_donor_by_email( $input['donor_email'] );
            if ( $donor_id ) {
                $args = [
                    'post_type'      => $post_type,
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'meta_query'     => [
                        [
                            'key'   => '_sd_donor_id',
                            'value' => $donor_id,
                            'type'  => 'NUMERIC',
                        ],
                        [
                            'key'   => '_sd_honoree_name',
                            'value' => $input['honoree_name'],
                        ],
                    ],
                    'fields'         => 'ids',
                ];
                
                $posts = get_posts( $args );
                
                if ( ! empty( $posts ) ) {
                    return $posts[0];
                }
            }
        }

        return null;
    }

    /**
     * Find a donor by email address.
     *
     * @since 1.0.0
     *
     * @param string $email The donor email.
     * @return int|null Donor post ID or null.
     */
    private static function find_donor_by_email( string $email ): ?int {
        if ( empty( $email ) ) {
            return null;
        }

        $posts = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_email',
                    'value' => $email,
                ],
            ],
            'fields'         => 'ids',
        ] );

        return ! empty( $posts ) ? $posts[0] : null;
    }

    /**
     * Update an existing record with new data.
     *
     * @since 1.0.0
     *
     * @param int    $post_id      The existing post ID.
     * @param string $product_type The product type.
     * @param array  $input        The input data.
     * @return array|WP_Error Update result or error.
     */
    private static function update_existing_record( int $post_id, string $product_type, array $input ) {
        $prefix = '_sd_';
        $updated_fields = [];

        // Map input keys to meta keys based on product type.
        $field_map = match ( $product_type ) {
            'donation' => [
                'amount'      => 'amount',
                'allocation'  => 'allocation',
                'dedication'  => 'dedication',
                'order_id'    => 'wc_order_id',
                'date'        => 'date',
            ],
            'membership' => [
                'amount'          => 'amount',
                'tier'            => 'tier',
                'membership_type' => 'membership_type',
                'business_name'   => 'business_name',
                'order_id'        => 'wc_order_id',
                'date'            => 'start_date',
            ],
            'memorial' => [
                'amount'          => 'amount',
                'honoree_name'    => 'honoree_name',
                'memorial_type'   => 'memorial_type',
                'tribute_message' => 'tribute_message',
                'pet_species'     => 'pet_species',
                'order_id'        => 'wc_order_id',
                'date'            => 'date',
            ],
            default => [],
        };

        foreach ( $field_map as $input_key => $meta_key ) {
            if ( isset( $input[ $input_key ] ) && $input[ $input_key ] !== '' ) {
                $current_value = get_post_meta( $post_id, $prefix . $meta_key, true );
                $new_value = $input[ $input_key ];
                
                // Only update if value is different or empty.
                if ( $current_value !== $new_value && ( empty( $current_value ) || $new_value ) ) {
                    update_post_meta( $post_id, $prefix . $meta_key, $new_value );
                    $updated_fields[] = $meta_key;
                }
            }
        }

        // Update post title for memorials if honoree name changed.
        if ( $product_type === 'memorial' && ! empty( $input['honoree_name'] ) ) {
            $post = get_post( $post_id );
            if ( $post && $post->post_title !== $input['honoree_name'] ) {
                wp_update_post( [
                    'ID'         => $post_id,
                    'post_title' => $input['honoree_name'],
                ] );
                $updated_fields[] = 'title';
            }
        }

        /**
         * Fires after a legacy sync record has been updated.
         *
         * @since 1.0.0
         *
         * @param int    $post_id        The post ID.
         * @param string $product_type   The product type.
         * @param array  $input          The input data.
         * @param array  $updated_fields Fields that were updated.
         */
        do_action( 'starter_shelter_legacy_record_updated', $post_id, $product_type, $input, $updated_fields );

        return [
            'id'             => $post_id,
            'updated'        => true,
            'updated_fields' => $updated_fields,
        ];
    }

    /**
     * Extract membership tier from item data.
     *
     * @since 1.0.0
     *
     * @param array $item_data Extracted item data.
     * @return string|null Tier slug or null.
     */
    private static function extract_tier_from_item( array $item_data ): ?string {
        // Check sync mapping first.
        if ( ! empty( $item_data['sync_mapping']['tier'] ) ) {
            return $item_data['sync_mapping']['tier'];
        }

        // Check attributes.
        $tier_keys = [ 'membership_level', 'membershiplevel', 'tier' ];
        foreach ( $tier_keys as $key ) {
            if ( ! empty( $item_data['attributes'][ $key ] ) ) {
                return Helpers\normalize_tier( $item_data['attributes'][ $key ] );
            }
        }

        // Check meta.
        $meta_keys = [ 'pa_membership-level', 'membership-level', 'Membership Level' ];
        foreach ( $meta_keys as $key ) {
            if ( ! empty( $item_data['meta'][ $key ] ) ) {
                return Helpers\normalize_tier( $item_data['meta'][ $key ] );
            }
        }

        // Try to extract from item name.
        $name_lower = strtolower( $item_data['name'] ?? '' );
        $tier_patterns = [
            'single'       => [ 'single membership', 'single - ' ],
            'family'       => [ 'family membership', 'family - ' ],
            'contributing' => [ 'contributing membership', 'contributing - ' ],
            'supporting'   => [ 'supporting membership', 'supporting - ' ],
            'donor'        => [ 'donor membership', 'donor - ' ],
            'sustaining'   => [ 'sustaining membership', 'sustaining - ' ],
            'patron'       => [ 'patron membership', 'patron - ' ],
            'benefactor'   => [ 'benefactor membership', 'benefactor - ' ],
        ];

        foreach ( $tier_patterns as $tier => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( strpos( $name_lower, $pattern ) !== false ) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Extract memorial type from item data.
     *
     * @since 1.0.0
     *
     * @param array $item_data Extracted item data.
     * @return string|null Memorial type or null.
     */
    private static function extract_memorial_type_from_item( array $item_data ): ?string {
        // Check sync mapping first.
        if ( ! empty( $item_data['sync_mapping']['memorial_type'] ) ) {
            return $item_data['sync_mapping']['memorial_type'];
        }

        // Check attributes.
        $type_keys = [ 'in_memoriam_type', 'inmemoriamtype', 'memorial_type' ];
        foreach ( $type_keys as $key ) {
            if ( ! empty( $item_data['attributes'][ $key ] ) ) {
                return strtolower( $item_data['attributes'][ $key ] );
            }
        }

        // Check meta.
        $meta_keys = [ 'pa_in-memoriam-type', 'in-memoriam-type', 'In Memoriam Type' ];
        foreach ( $meta_keys as $key ) {
            if ( ! empty( $item_data['meta'][ $key ] ) ) {
                return strtolower( $item_data['meta'][ $key ] );
            }
        }

        // Try to extract from item name.
        $name_lower = strtolower( $item_data['name'] ?? '' );
        if ( strpos( $name_lower, '- pet' ) !== false || strpos( $name_lower, 'pet memorial' ) !== false ) {
            return 'pet';
        }
        if ( strpos( $name_lower, '- person' ) !== false || strpos( $name_lower, 'person memorial' ) !== false ) {
            return 'person';
        }

        return null;
    }

    /**
     * Extract allocation from item data.
     *
     * @since 1.0.0
     *
     * @param array $item_data Extracted item data.
     * @return string|null Allocation slug or null.
     */
    private static function extract_allocation_from_item( array $item_data ): ?string {
        // Check sync mapping first.
        if ( ! empty( $item_data['sync_mapping']['allocation'] ) ) {
            return $item_data['sync_mapping']['allocation'];
        }

        // Check attributes.
        $alloc_keys = [ 'preferred_allocation', 'preferredallocation', 'allocation' ];
        foreach ( $alloc_keys as $key ) {
            if ( ! empty( $item_data['attributes'][ $key ] ) ) {
                return self::normalize_allocation_value( $item_data['attributes'][ $key ] );
            }
        }

        // Check meta.
        $meta_keys = [ 'pa_preferred-allocation', 'preferred-allocation', 'Preferred Allocation' ];
        foreach ( $meta_keys as $key ) {
            if ( ! empty( $item_data['meta'][ $key ] ) ) {
                return self::normalize_allocation_value( $item_data['meta'][ $key ] );
            }
        }

        // Try to extract from item name.
        $name_lower = strtolower( $item_data['name'] ?? '' );
        if ( strpos( $name_lower, 'spay' ) !== false || strpos( $name_lower, 'neuter' ) !== false ) {
            return 'spay-neuter-clinic';
        }
        if ( strpos( $name_lower, 'general' ) !== false ) {
            return 'general-fund';
        }

        return null;
    }

    /**
     * Normalize allocation value.
     *
     * @since 1.0.0
     *
     * @param string $value Raw allocation value.
     * @return string Normalized allocation slug.
     */
    private static function normalize_allocation_value( string $value ): string {
        $value_lower = strtolower( trim( $value ) );
        
        $mappings = [
            'general fund'        => 'general-fund',
            'spay / neuter clinic' => 'spay-neuter-clinic',
            'spay/neuter clinic'  => 'spay-neuter-clinic',
            'spay neuter clinic'  => 'spay-neuter-clinic',
        ];

        return $mappings[ $value_lower ] ?? sanitize_title( $value );
    }

    /**
     * AJAX handler: Get sync statistics.
     *
     * @since 1.0.0
     */
    public static function ajax_get_stats(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        wp_send_json_success( self::get_sync_stats() );
    }

    /**
     * AJAX handler: Reset sync status for orders.
     *
     * @since 1.0.0
     */
    public static function ajax_reset_sync(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) $_POST['order_ids'] ) : [];
        $reset_all = ! empty( $_POST['reset_all'] );

        if ( $reset_all ) {
            global $wpdb;
            
            // Delete all legacy sync meta.
            $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => self::SYNCED_META_KEY ] );
            $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_sd_legacy_sync_results' ] );

            // HPOS support.
            if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) 
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                $meta_table = $wpdb->prefix . 'wc_orders_meta';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_table'" ) === $meta_table ) {
                    $wpdb->delete( $meta_table, [ 'meta_key' => self::SYNCED_META_KEY ] );
                    $wpdb->delete( $meta_table, [ 'meta_key' => '_sd_legacy_sync_results' ] );
                }
            }

            wp_send_json_success( [
                'message' => __( 'All sync status has been reset.', 'starter-shelter' ),
            ] );
        } elseif ( ! empty( $order_ids ) ) {
            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order->delete_meta_data( self::SYNCED_META_KEY );
                    $order->delete_meta_data( '_sd_legacy_sync_results' );
                    $order->save();
                }
            }

            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %d: number of orders */
                    __( 'Sync status reset for %d order(s).', 'starter-shelter' ),
                    count( $order_ids )
                ),
            ] );
        } else {
            wp_send_json_error( __( 'No orders specified.', 'starter-shelter' ) );
        }
    }

    /**
     * Get inline styles for the admin page.
     *
     * @since 1.0.0
     *
     * @return string CSS styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-legacy-sync .components-card {
                margin-bottom: 20px;
            }
            .sd-legacy-sync .sd-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }
            .sd-legacy-sync .sd-stat-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 16px;
                text-align: center;
            }
            .sd-legacy-sync .sd-stat-card .sd-stat-value {
                font-size: 32px;
                font-weight: 600;
                line-height: 1.2;
                color: #1d2327;
            }
            .sd-legacy-sync .sd-stat-card .sd-stat-label {
                font-size: 13px;
                color: #646970;
                margin-top: 4px;
            }
            .sd-legacy-sync .sd-stat-card.sd-stat-unsynced .sd-stat-value {
                color: #dba617;
            }
            .sd-legacy-sync .sd-stat-card.sd-stat-synced .sd-stat-value {
                color: #00a32a;
            }
            .sd-legacy-sync .sd-orders-table {
                width: 100%;
                border-collapse: collapse;
            }
            .sd-legacy-sync .sd-orders-table th,
            .sd-legacy-sync .sd-orders-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #c3c4c7;
            }
            .sd-legacy-sync .sd-orders-table th {
                background: #f6f7f7;
                font-weight: 600;
            }
            .sd-legacy-sync .sd-orders-table tr:hover {
                background: #f6f7f7;
            }
            .sd-legacy-sync .sd-sync-status {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .sd-legacy-sync .sd-sync-status.unsynced {
                background: #fcf0e3;
                color: #9a6700;
            }
            .sd-legacy-sync .sd-sync-status.synced {
                background: #e5f5e8;
                color: #1a5e1f;
            }
            .sd-legacy-sync .sd-sync-status.processed {
                background: #e7f5fb;
                color: #0a4b78;
            }
            .sd-legacy-sync .sd-product-type-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                text-transform: uppercase;
            }
            .sd-legacy-sync .sd-product-type-badge.donation {
                background: #d4edda;
                color: #155724;
            }
            .sd-legacy-sync .sd-product-type-badge.membership {
                background: #cce5ff;
                color: #004085;
            }
            .sd-legacy-sync .sd-product-type-badge.memorial {
                background: #f8d7da;
                color: #721c24;
            }
            .sd-legacy-sync .sd-progress-bar {
                height: 20px;
                background: #f0f0f0;
                border-radius: 10px;
                overflow: hidden;
                margin: 16px 0;
            }
            .sd-legacy-sync .sd-progress-bar-fill {
                height: 100%;
                background: #2271b1;
                transition: width 0.3s ease;
            }
            .sd-legacy-sync .sd-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                margin-bottom: 20px;
                padding: 16px;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .sd-legacy-sync .sd-filter-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .sd-legacy-sync .sd-filter-group label {
                font-size: 12px;
                font-weight: 500;
                color: #646970;
            }
        ';
    }

    /**
     * AJAX handler: Debug a specific order.
     *
     * Returns detailed information about order items and matching attempts.
     *
     * @since 1.0.0
     */
    public static function ajax_debug_order(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        
        if ( ! $order_id ) {
            wp_send_json_error( __( 'Invalid order ID.', 'starter-shelter' ) );
        }

        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'starter-shelter' ) );
        }

        $debug_info = [
            'order_id'     => $order_id,
            'order_number' => $order->get_order_number(),
            'status'       => $order->get_status(),
            'items'        => [],
        ];

        foreach ( $order->get_items() as $item_id => $item ) {
            $item_debug = [
                'item_id'    => $item_id,
                'item_class' => get_class( $item ),
            ];

            // Check if this is a product item.
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                $item_debug['error'] = 'Not a WC_Order_Item_Product';
                $debug_info['items'][] = $item_debug;
                continue;
            }

            // Basic item data.
            $item_debug['name'] = $item->get_name();
            $item_debug['product_id'] = $item->get_product_id();
            $item_debug['variation_id'] = $item->get_variation_id();
            $item_debug['quantity'] = $item->get_quantity();
            $item_debug['total'] = $item->get_total();

            // Try to get product.
            $product = $item->get_product();
            $item_debug['product_exists'] = (bool) $product;

            if ( $product ) {
                $item_debug['product_type'] = $product->get_type();
                $item_debug['product_sku'] = $product->get_sku();
                $item_debug['product_name'] = $product->get_name();
                
                if ( $product->is_type( 'variation' ) ) {
                    $item_debug['is_variation'] = true;
                    $item_debug['variation_attributes'] = $product->get_variation_attributes();
                    
                    $parent = wc_get_product( $product->get_parent_id() );
                    if ( $parent ) {
                        $item_debug['parent_id'] = $parent->get_id();
                        $item_debug['parent_sku'] = $parent->get_sku();
                        $item_debug['parent_name'] = $parent->get_name();
                    }
                }
            } else {
                // Product doesn't exist - try to get parent.
                if ( $item_debug['variation_id'] && $item_debug['product_id'] ) {
                    $parent = wc_get_product( $item_debug['product_id'] );
                    if ( $parent ) {
                        $item_debug['parent_exists'] = true;
                        $item_debug['parent_sku'] = $parent->get_sku();
                        $item_debug['parent_name'] = $parent->get_name();
                    } else {
                        $item_debug['parent_exists'] = false;
                    }
                }
            }

            // Get all item meta.
            $item_debug['meta_data'] = [];
            foreach ( $item->get_meta_data() as $meta ) {
                $item_debug['meta_data'][ $meta->key ] = $meta->value;
            }

            // Get formatted meta.
            $item_debug['formatted_meta'] = [];
            foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
                $item_debug['formatted_meta'][ $meta->key ] = $meta->value;
            }

            // Try to extract item data.
            $item_data = self::extract_item_data( $item, $product );
            $item_debug['extracted_data'] = $item_data;

            // Try each config finding strategy.
            $item_debug['config_attempts'] = [];

            // Strategy 1: By SKU.
            if ( $product && ! empty( $item_data['sku'] ) ) {
                $config = Product_Mapper::find_by_sku( $item_data['sku'] );
                $item_debug['config_attempts']['by_sku'] = [
                    'sku' => $item_data['sku'],
                    'found' => (bool) $config,
                    'config' => $config ? [ 'ability' => $config['ability'] ?? '', 'type' => $config['product_type'] ?? '' ] : null,
                ];
            }

            // Strategy 2: By product ID.
            $config = self::find_config_by_product_id( $item_data['product_id'] ?? 0, $item_data['variation_id'] ?? 0 );
            $item_debug['config_attempts']['by_product_id'] = [
                'product_id' => $item_data['product_id'] ?? 0,
                'variation_id' => $item_data['variation_id'] ?? 0,
                'found' => (bool) $config,
                'config' => $config ? [ 'ability' => $config['ability'] ?? '', 'type' => $config['product_type'] ?? '' ] : null,
            ];

            // Strategy 3: By name.
            $config = self::find_config_by_name( $item_data['name'] ?? '' );
            $item_debug['config_attempts']['by_name'] = [
                'name' => $item_data['name'] ?? '',
                'found' => (bool) $config,
                'config' => $config ? [ 
                    'ability' => $config['ability'] ?? '', 
                    'type' => $config['product_type'] ?? '',
                    'matched_pattern' => $config['matched_pattern'] ?? '',
                ] : null,
            ];

            // Strategy 4: By item meta.
            if ( ! empty( $item_data['meta'] ) ) {
                $config = self::find_config_by_item_meta( $item_data['meta'], $item_data['name'] ?? '' );
                $item_debug['config_attempts']['by_item_meta'] = [
                    'found' => (bool) $config,
                    'config' => $config ? [ 'ability' => $config['ability'] ?? '', 'type' => $config['product_type'] ?? '' ] : null,
                ];
            }

            // Final config.
            $final_config = self::find_config_for_item( $item, $product, $item_data );
            $item_debug['final_config'] = $final_config ? [
                'ability' => $final_config['ability'] ?? '',
                'type' => $final_config['product_type'] ?? '',
                'sku_prefix' => $final_config['sku_prefix'] ?? '',
                'matched_by' => $final_config['matched_by'] ?? 'unknown',
            ] : null;

            $debug_info['items'][] = $item_debug;
        }

        wp_send_json_success( $debug_info );
    }
}
