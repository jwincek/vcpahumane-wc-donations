<?php
/**
 * Plugin Activation - Creates default WooCommerce products.
 *
 * @package Starter_Shelter
 * @subpackage Core
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Handles plugin activation tasks including product creation.
 *
 * @since 2.0.0
 */
class Activator {

    /**
     * Product definitions for shelter donations.
     *
     * @var array
     */
    private static array $products = [
        'shelter-donations' => [
            'name'        => 'Shelter Donations',
            'sku'         => 'shelter-donations',
            'description' => 'Support us with a donation to help care for animals in need.',
            'short_desc'  => 'Support us with a donation.',
            'category'    => 'Support Us',
            'option_key'  => 'sd_donation_product_id',
            'virtual'     => true,
            'attribute'   => [
                'name'    => 'Preferred Allocation',
                'slug'    => 'preferred-allocation',
                'options' => [
                    'General Fund'   => 10,
                    'Medical Care'   => 10,
                    'Food & Supplies' => 10,
                    'Facility'       => 10,
                    'Rescue Ops'     => 10,
                ],
            ],
        ],
        'shelter-memberships' => [
            'name'        => 'Shelter Memberships',
            'sku'         => 'shelter-memberships',
            'description' => 'Our shelter is sustained through your membership. Become a member today!',
            'short_desc'  => 'Become a member and support our pets in need!',
            'category'    => 'Support Us',
            'option_key'  => 'sd_membership_product_id',
            'virtual'     => true,
            'attribute'   => [
                'name'    => 'Membership Level',
                'slug'    => 'membership-level',
                'options' => [
                    'Single - $10'       => 10,
                    'Family - $25'       => 25,
                    'Contributing - $50' => 50,
                    'Supporting - $100'  => 100,
                    'Donor - $250'       => 250,
                    'Sustaining - $500'  => 500,
                    'Patron - $750'      => 750,
                    'Benefactor - $1000' => 1000,
                ],
            ],
        ],
        'shelter-memberships-business' => [
            'name'        => 'Shelter Business Memberships',
            'sku'         => 'shelter-memberships-business',
            'description' => 'Our shelter is sustained through your business membership. Support us today!',
            'short_desc'  => 'Become a business member and support our pets in need!',
            'category'    => 'Support Us',
            'option_key'  => 'sd_business_membership_product_id',
            'virtual'     => true,
            'attribute'   => [
                'name'    => 'Membership Level',
                'slug'    => 'membership-level',
                'options' => [
                    'Contributing - $50' => 50,
                    'Supporting - $100'  => 100,
                    'Donor - $250'       => 250,
                    'Sustaining - $500'  => 500,
                    'Patron - $750'      => 750,
                    'Benefactor - $1000' => 1000,
                ],
            ],
        ],
        'shelter-donations-in-memoriam' => [
            'name'        => 'In Memoriam Donations',
            'sku'         => 'shelter-donations-in-memoriam',
            'description' => 'Honor the memory of a person or pet with a meaningful donation.',
            'short_desc'  => 'Make a donation in memory of a person or pet.',
            'category'    => 'Support Us',
            'option_key'  => 'sd_memorial_product_id',
            'virtual'     => true,
            'attribute'   => [
                'name'    => 'In Memoriam Type',
                'slug'    => 'in-memoriam-type',
                'options' => [
                    'Person' => 10,
                    'Pet'    => 10,
                ],
            ],
        ],
    ];

    /**
     * Run activation tasks.
     *
     * @since 2.0.0
     */
    public static function activate(): void {
        // Schedule product creation for after WooCommerce loads.
        add_action( 'woocommerce_loaded', [ __CLASS__, 'maybe_create_products' ] );
        
        // If WooCommerce is already loaded, run immediately.
        if ( class_exists( 'WooCommerce' ) ) {
            self::maybe_create_products();
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Create products if they don't exist.
     *
     * @since 2.0.0
     */
    public static function maybe_create_products(): void {
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        // Check if we've already run setup.
        if ( get_option( 'sd_products_created' ) ) {
            return;
        }

        foreach ( self::$products as $key => $product_data ) {
            self::create_product_if_needed( $key, $product_data );
        }

        // Mark as complete.
        update_option( 'sd_products_created', true );
    }

    /**
     * Create a single product if it doesn't exist.
     *
     * @since 2.0.0
     *
     * @param string $key          Product key.
     * @param array  $product_data Product configuration.
     */
    private static function create_product_if_needed( string $key, array $product_data ): void {
        // Check if product already exists by option.
        $existing_id = get_option( $product_data['option_key'], 0 );
        if ( $existing_id && wc_get_product( $existing_id ) ) {
            return;
        }

        // Check if product exists by SKU.
        $existing_id = wc_get_product_id_by_sku( $product_data['sku'] );
        if ( $existing_id ) {
            update_option( $product_data['option_key'], $existing_id );
            return;
        }

        // Create the variable product.
        $product_id = self::create_variable_product( $product_data );
        
        if ( $product_id ) {
            update_option( $product_data['option_key'], $product_id );
        }
    }

    /**
     * Create a variable product with variations.
     *
     * @since 2.0.0
     *
     * @param array $data Product data.
     * @return int|false Product ID or false on failure.
     */
    private static function create_variable_product( array $data ) {
        // Create the parent variable product.
        $product = new \WC_Product_Variable();
        
        $product->set_name( $data['name'] );
        $product->set_sku( $data['sku'] );
        $product->set_description( $data['description'] );
        $product->set_short_description( $data['short_desc'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_sold_individually( true );
        $product->set_virtual( $data['virtual'] ?? true );
        $product->set_tax_status( 'none' );
        
        // Set up the attribute.
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name( $data['attribute']['name'] );
        $attribute->set_options( array_keys( $data['attribute']['options'] ) );
        $attribute->set_visible( true );
        $attribute->set_variation( true );
        
        $product->set_attributes( [ $attribute ] );
        
        // Assign category.
        $category_id = self::get_or_create_category( $data['category'] );
        if ( $category_id ) {
            $product->set_category_ids( [ $category_id ] );
        }
        
        $product_id = $product->save();
        
        if ( ! $product_id ) {
            return false;
        }

        // Create variations.
        foreach ( $data['attribute']['options'] as $option_name => $price ) {
            self::create_variation( $product_id, $data['attribute']['name'], $option_name, $price, $data['virtual'] ?? true );
        }

        // Sync the variable product.
        \WC_Product_Variable::sync( $product_id );

        return $product_id;
    }

    /**
     * Create a product variation.
     *
     * @since 2.0.0
     *
     * @param int    $parent_id      Parent product ID.
     * @param string $attribute_name Attribute name.
     * @param string $option_name    Option/term name.
     * @param float  $price          Variation price.
     * @param bool   $virtual        Whether virtual.
     */
    private static function create_variation( int $parent_id, string $attribute_name, string $option_name, float $price, bool $virtual = true ): void {
        $variation = new \WC_Product_Variation();
        
        $variation->set_parent_id( $parent_id );
        $variation->set_status( 'publish' );
        $variation->set_regular_price( (string) $price );
        $variation->set_virtual( $virtual );
        $variation->set_manage_stock( false );
        $variation->set_stock_status( 'instock' );
        
        // Set the attribute for this variation.
        $attribute_slug = sanitize_title( $attribute_name );
        $variation->set_attributes( [
            $attribute_slug => $option_name,
        ] );
        
        $variation->save();
    }

    /**
     * Get or create a product category.
     *
     * @since 2.0.0
     *
     * @param string $name Category name.
     * @return int|false Category ID or false.
     */
    private static function get_or_create_category( string $name ) {
        $term = get_term_by( 'name', $name, 'product_cat' );
        
        if ( $term ) {
            return $term->term_id;
        }
        
        $result = wp_insert_term( $name, 'product_cat' );
        
        if ( is_wp_error( $result ) ) {
            return false;
        }
        
        return $result['term_id'];
    }

    /**
     * Check if products need to be created (for admin notice).
     *
     * @since 2.0.0
     *
     * @return bool True if products are missing.
     */
    public static function products_need_setup(): bool {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        foreach ( self::$products as $product_data ) {
            $product_id = get_option( $product_data['option_key'], 0 );
            if ( ! $product_id || ! wc_get_product( $product_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get product setup status for admin.
     *
     * @since 2.0.0
     *
     * @return array Array of product statuses.
     */
    public static function get_product_status(): array {
        $status = [];

        foreach ( self::$products as $key => $product_data ) {
            $product_id = get_option( $product_data['option_key'], 0 );
            $product = $product_id ? wc_get_product( $product_id ) : null;
            
            $status[ $key ] = [
                'name'       => $product_data['name'],
                'option_key' => $product_data['option_key'],
                'product_id' => $product_id,
                'exists'     => (bool) $product,
                'edit_url'   => $product ? get_edit_post_link( $product_id ) : null,
            ];
        }

        return $status;
    }

    /**
     * Reset products (for re-creation).
     *
     * @since 2.0.0
     */
    public static function reset_product_flags(): void {
        delete_option( 'sd_products_created' );
        
        foreach ( self::$products as $product_data ) {
            delete_option( $product_data['option_key'] );
        }
    }
}
