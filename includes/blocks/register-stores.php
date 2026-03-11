<?php
/**
 * Interactivity API - Modular store registration for WordPress 6.9.
 *
 * Each block declares its own viewScriptModule in block.json, which imports
 * only the store module it needs. This file:
 *
 * 1. Registers shared configuration via wp_interactivity_config()
 * 2. Seeds initial server-side state for each store namespace
 * 3. Registers the utility script module that all stores depend on
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Blocks;

use Starter_Shelter\Core\Config;

/* =========================================================================
   1. SHARED CONFIGURATION
   ========================================================================= */

/**
 * Register Interactivity API configuration.
 *
 * Shared across every store namespace via getConfig( 'starter-shelter' ).
 *
 * @since 2.0.0
 */
function register_interactivity_config(): void {
    if ( ! should_enqueue_interactivity() ) {
        return;
    }

    $currency_symbol   = function_exists( 'get_woocommerce_currency_symbol' )
        ? get_woocommerce_currency_symbol()
        : '$';
    $currency_pos      = get_option( 'woocommerce_currency_pos', 'left' );
    $currency_decimals = (int) get_option( 'woocommerce_price_num_decimals', 2 );

    wp_interactivity_config( 'starter-shelter', [
        // API.
        'restUrl'   => rest_url( 'starter-shelter/v1/' ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        'cartNonce' => wp_create_nonce( 'sd_add_to_cart' ),

        // User context.
        'donorId'  => get_current_user_donor_id(),
        'isAdmin'  => current_user_can( 'manage_options' ),
        'userId'   => get_current_user_id(),

        // Currency.
        'currency' => [
            'symbol'   => $currency_symbol,
            'position' => in_array( $currency_pos, [ 'left', 'left_space' ], true ) ? 'before' : 'after',
            'decimals' => $currency_decimals,
        ],

        // WooCommerce URLs.
        'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/',
        'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart/',

        // Behaviour.
        'autoRedirectToCheckout' => (bool) apply_filters( 'starter_shelter_auto_redirect_checkout', false ),

        // Product IDs.
        'products' => [
            'donation'           => (int) get_option( 'sd_donation_product_id', 0 ),
            'memorial'           => (int) get_option( 'sd_memorial_product_id', 0 ),
            'membership'         => (int) get_option( 'sd_membership_product_id', 0 ),
            'businessMembership' => (int) get_option( 'sd_business_membership_product_id', 0 ),
        ],

        // i18n strings shared by all stores.
        'i18n' => [
            'loading'          => __( 'Loading...', 'starter-shelter' ),
            'noResults'        => __( 'No results found', 'starter-shelter' ),
            'showingOne'       => __( 'Showing 1 item', 'starter-shelter' ),
            'showingMany'      => __( 'Showing %d items', 'starter-shelter' ),
            'paginationInfo'   => __( 'Showing %1$d of %2$d items', 'starter-shelter' ),
            'error'            => __( 'Something went wrong. Please try again.', 'starter-shelter' ),
            'addedToCart'      => __( 'Added to cart successfully!', 'starter-shelter' ),
            'errorMinAmount'   => __( 'Please enter a valid donation amount.', 'starter-shelter' ),
            'errorMaxAmount'   => __( 'Amount exceeds the maximum allowed.', 'starter-shelter' ),
            'errorHonoreeName' => __( 'Please enter the honoree name.', 'starter-shelter' ),
            'errorFamilyName'  => __( 'Please enter the family contact name.', 'starter-shelter' ),
            'errorInvalidEmail'=> __( 'Please enter a valid email address.', 'starter-shelter' ),
            'errorSelectTier'  => __( 'Please select a membership level.', 'starter-shelter' ),
            'errorBusinessName'=> __( 'Please enter your business name.', 'starter-shelter' ),
            'errorGeneric'     => __( 'Could not add to cart. Please try again.', 'starter-shelter' ),
            'errorNetwork'     => __( 'Network error. Please check your connection.', 'starter-shelter' ),
            'inHonorOf'        => __( 'In Honor Of', 'starter-shelter' ),
            'inMemoryOf'       => __( 'In Memory Of', 'starter-shelter' ),
            'personName'       => __( "Person's Name", 'starter-shelter' ),
            'petName'          => __( "Pet's Name", 'starter-shelter' ),
            'pet'              => __( 'Pet', 'starter-shelter' ),
            'typeHuman'        => __( 'Person', 'starter-shelter' ),
            'typePet'          => __( 'Pet', 'starter-shelter' ),
            'processing'       => __( 'Processing...', 'starter-shelter' ),
            'individualMembership' => __( 'Individual Membership', 'starter-shelter' ),
            'businessMembership'   => __( 'Business Membership', 'starter-shelter' ),
            'donor'            => __( 'Donor', 'starter-shelter' ),
            'donorLevelNew'    => __( 'New Donor', 'starter-shelter' ),
            'donorLevelBronze' => __( 'Bronze', 'starter-shelter' ),
            'donorLevelSilver' => __( 'Silver', 'starter-shelter' ),
            'donorLevelGold'   => __( 'Gold', 'starter-shelter' ),
            'donorLevelPlatinum' => __( 'Platinum', 'starter-shelter' ),
        ],

        // Feature flags.
        'features' => [
            'enableRouterNavigation' => true,
            'enableAutoRefresh'      => false,
            'debugMode'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
        ],
    ] );
}

/* =========================================================================
   2. INITIAL SERVER-SIDE STATE
   ========================================================================= */

/**
 * Seed initial state for every store namespace.
 *
 * Block-specific render.php files may merge additional instance state
 * into these namespaces (e.g. per-form state keyed by formId).
 *
 * @since 2.0.0
 */
function register_interactivity_stores(): void {
    // --- Shared root namespace -----------------------------------------------
    wp_interactivity_state( 'starter-shelter', [
        'isInitialized' => true,
    ] );

    // --- Donation form -------------------------------------------------------
    wp_interactivity_state( 'starter-shelter/donation-form', [
        'forms' => [],
    ] );

    // --- Memorial form -------------------------------------------------------
    wp_interactivity_state( 'starter-shelter/memorial-form', [
        'forms' => [],
    ] );

    // --- Membership form -----------------------------------------------------
    wp_interactivity_state( 'starter-shelter/membership-form', [
        'forms' => [],
    ] );

    // --- Memorial wall archive -----------------------------------------------
    // All instance state lives in data-wp-context set by render.php.
    // We seed an empty namespace so wp_interactivity_state() is initialized.
    wp_interactivity_state( 'starter-shelter/memorials', [] );

    // --- Donation listings ---------------------------------------------------
    wp_interactivity_state( 'starter-shelter/donations', [
        'isLoading'  => false,
        'donations'  => [],
        'total'      => 0,
        'totalPages' => 0,
        'page'       => 1,
        'error'      => null,
        'filters'    => [
            'allocation' => '',
            'campaign'   => '',
            'dateFrom'   => '',
            'dateTo'     => '',
        ],
    ] );

    // --- Campaign progress ---------------------------------------------------
    wp_interactivity_state( 'starter-shelter/campaign', [
        'campaigns' => [],
        'isLoading' => false,
    ] );

    // --- Donor dashboard -----------------------------------------------------
    wp_interactivity_state( 'starter-shelter/donor', [
        'isLoading'   => true,
        'isLoggedIn'  => is_user_logged_in(),
        'donor'       => null,
        'stats'       => null,
        'recentGifts' => [],
        'membership'  => null,
        'error'       => null,
    ] );
}

/* =========================================================================
   3. SCRIPT MODULE REGISTRATION
   ========================================================================= */

/**
 * Register the shared utilities module that all stores depend on.
 *
 * Individual stores are registered automatically via each block's
 * block.json viewScriptModule — WordPress resolves the relative path
 * and registers the module for us. We only need to register the
 * shared dependency that isn't declared by any block.json.
 *
 * @since 2.0.0
 */
function register_script_modules(): void {
    // Shared utility module — imported by every store via relative path,
    // but we register it explicitly so WordPress can resolve it.
    wp_register_script_module(
        'starter-shelter/utils',
        STARTER_SHELTER_URL . 'assets/js/stores/utils.js',
        [ '@wordpress/interactivity' ],
        STARTER_SHELTER_VERSION
    );

    // Memorials store — registered for dependency tracking and import map generation.
    // WordPress 6.9 performance optimizations applied:
    // - fetchpriority: 'low' — deprioritizes loading vs LCP image
    // - in_footer: true — prints after critical rendering path
    // - @wordpress/interactivity-router is 'dynamic' — avoids modulepreload since
    //   it's only imported when user triggers navigation, not on initial load.
    //
    // The actual import happens via relative URL from blocks/memorial-wall/view.js
    // because bare specifiers require import map entries which aren't available
    // in the block editor's iframe context.
    //
    // NOTE: The router must be enqueued where the block is rendered (render.php)
    // to ensure it appears in the import map for dynamic imports to work.
    wp_register_script_module(
        'starter-shelter/memorials',
        STARTER_SHELTER_URL . 'assets/js/stores/memorials.js',
        [
            '@wordpress/interactivity',
            [
                'id'     => '@wordpress/interactivity-router',
                'import' => 'dynamic',
            ],
        ],
        STARTER_SHELTER_VERSION,
        [
            'fetchpriority' => 'low',
            'in_footer'     => true,
        ]
    );

    // Legacy shim — only loaded when a page needs every store at once.
    wp_register_script_module(
        'starter-shelter/stores',
        STARTER_SHELTER_URL . 'assets/js/stores.js',
        [ '@wordpress/interactivity' ],
        STARTER_SHELTER_VERSION
    );
}

/* =========================================================================
   4. HELPER FUNCTIONS
   ========================================================================= */

// get_current_user_donor_id() lives in register-bindings.php (single source
// of truth with transient caching). Both files share the same namespace so
// the function is available here without a separate import.

/**
 * Check if interactivity scripts should be enqueued.
 *
 * @since 2.0.0
 *
 * @return bool True if should enqueue.
 */
function should_enqueue_interactivity(): bool {
    // Always enqueue on donation-related pages.
    if ( is_singular( [ 'sd_donation', 'sd_memorial', 'sd_membership', 'sd_donor' ] ) ) {
        return true;
    }

    // Enqueue on archive pages.
    if ( is_post_type_archive( [ 'sd_memorial' ] ) ) {
        return true;
    }

    // Enqueue on My Account pages.
    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        return true;
    }

    // Check for any shelter block in content.
    global $post;
    if ( $post && has_block( 'starter-shelter/', $post ) ) {
        return true;
    }

    return false;
}

/* =========================================================================
   5. HOOKS
   ========================================================================= */

// Seed server state.
add_action( 'wp_interactivity_init', __NAMESPACE__ . '\\register_interactivity_stores' );

// Shared configuration (runs after state so state is available).
add_action( 'wp_interactivity_init', __NAMESPACE__ . '\\register_interactivity_config', 15 );

// Script modules.
add_action( 'init', __NAMESPACE__ . '\\register_script_modules' );
