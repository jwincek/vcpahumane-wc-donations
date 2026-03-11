<?php
/**
 * Plugin Name: Starter Shelter Donations
 * Plugin URI: https://github.com/starter-shelter
 * Description: Animal shelter donations, memberships, and memorials management using WordPress 6.9+ Abilities API.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Starter Shelter
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: starter-shelter
 * Domain Path: /languages
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'STARTER_SHELTER_VERSION', '1.0.0' );
define( 'STARTER_SHELTER_FILE', __FILE__ );
define( 'STARTER_SHELTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STARTER_SHELTER_URL', plugin_dir_url( __FILE__ ) );
define( 'STARTER_SHELTER_CONFIG_PATH', STARTER_SHELTER_PATH . 'config/' );

// Autoloader for plugin classes.
spl_autoload_register( function ( string $class ): void {
    $prefix = 'Starter_Shelter\\';
    $base_dir = STARTER_SHELTER_PATH . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $parts = explode( '\\', $relative_class );
    $class_name = array_pop( $parts );
    
    // Convert namespace parts to directory path.
    $path = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) );
    
    // Convert class name to file name.
    $file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
    
    $file = $base_dir . ( $path ? $path . '/' : '' ) . $file_name;
    
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
function starter_shelter_init(): void {
    // Check WordPress version.
    if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
        add_action( 'admin_notices', function(): void {
            printf(
                '<div class="error"><p>%s</p></div>',
                esc_html__( 'Starter Shelter Donations requires WordPress 6.9 or higher.', 'starter-shelter' )
            );
        } );
        return;
    }

    // Initialize config loader.
    Starter_Shelter\Core\Config::init( STARTER_SHELTER_CONFIG_PATH );

    // Initialize CPT and taxonomy registration.
    Starter_Shelter\Core\CPT_Registry::init();

    // Initialize entity hydrator.
    Starter_Shelter\Core\Entity_Hydrator::init();

    // Initialize helpers.
    require_once STARTER_SHELTER_PATH . 'includes/core/helpers.php';
    require_once STARTER_SHELTER_PATH . 'includes/core/internal-processing.php';
}
add_action( 'plugins_loaded', 'starter_shelter_init', 10 );

/**
 * Register ability categories.
 *
 * @since 1.0.0
 */
function starter_shelter_register_ability_categories(): void {
    wp_register_ability_category( 'shelter-donations', [
        'label'       => __( 'Shelter Donations', 'starter-shelter' ),
        'description' => __( 'Abilities for managing animal shelter donations.', 'starter-shelter' ),
    ] );

    wp_register_ability_category( 'shelter-memberships', [
        'label'       => __( 'Shelter Memberships', 'starter-shelter' ),
        'description' => __( 'Abilities for managing shelter memberships.', 'starter-shelter' ),
    ] );

    wp_register_ability_category( 'shelter-memorials', [
        'label'       => __( 'Shelter Memorials', 'starter-shelter' ),
        'description' => __( 'Abilities for managing memorial donations.', 'starter-shelter' ),
    ] );

    wp_register_ability_category( 'shelter-donors', [
        'label'       => __( 'Shelter Donors', 'starter-shelter' ),
        'description' => __( 'Abilities for managing donor profiles.', 'starter-shelter' ),
    ] );

    wp_register_ability_category( 'shelter-reports', [
        'label'       => __( 'Shelter Reports', 'starter-shelter' ),
        'description' => __( 'Abilities for generating shelter reports.', 'starter-shelter' ),
    ] );
}
add_action( 'wp_abilities_api_categories_init', 'starter_shelter_register_ability_categories' );

/**
 * Register abilities.
 *
 * @since 1.0.0
 */
function starter_shelter_register_abilities(): void {
    Starter_Shelter\Abilities\Provider::register_abilities();
}
add_action( 'wp_abilities_api_init', 'starter_shelter_register_abilities' );

/**
 * Initialize WooCommerce integration.
 *
 * @since 1.0.0
 */
function starter_shelter_woocommerce_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Initialize product mapper (loads product config).
    Starter_Shelter\WooCommerce\Product_Mapper::init();

    // Initialize order handler (processes completed orders).
    Starter_Shelter\WooCommerce\Order_Handler::init();

    // Initialize checkout fields (dynamic fields based on cart).
    Starter_Shelter\WooCommerce\Checkout_Fields::init();

    // Initialize cart handler (AJAX add-to-cart for donation forms).
    Starter_Shelter\WooCommerce\Cart_Handler::init();

    // Initialize My Account (donor dashboard endpoints).
    Starter_Shelter\WooCommerce\My_Account::init();
}
add_action( 'plugins_loaded', 'starter_shelter_woocommerce_init', 20 );

/**
 * Initialize email factory.
 *
 * @since 1.0.0
 */
function starter_shelter_emails_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    
    // Initialize email factory (registers all emails from config).
    // Note: class-config-email.php is loaded by the factory when WC_Email is available.
    Starter_Shelter\Emails\Email_Factory::init();
}
add_action( 'plugins_loaded', 'starter_shelter_emails_init', 20 );

/**
 * Register block bindings sources and interactivity stores.
 *
 * @since 1.0.0
 */
function starter_shelter_register_block_bindings(): void {
    require_once STARTER_SHELTER_PATH . 'includes/blocks/register-bindings.php';
    require_once STARTER_SHELTER_PATH . 'includes/blocks/register-stores.php';
    require_once STARTER_SHELTER_PATH . 'includes/templates/register-templates.php';
}
add_action( 'init', 'starter_shelter_register_block_bindings', 20 );

/**
 * Register block editor assets.
 *
 * @since 2.0.0
 */
function starter_shelter_register_block_editor(): void {
    require_once STARTER_SHELTER_PATH . 'includes/blocks/register-editor.php';
}
add_action( 'init', 'starter_shelter_register_block_editor', 25 );

/**
 * Initialize admin functionality.
 *
 * @since 1.0.0
 */
function starter_shelter_admin_init(): void {
    if ( ! is_admin() ) {
        return;
    }

    // Initialize main admin menu (must be first).
    Starter_Shelter\Admin\Menu::init();

    // Initialize settings page.
    Starter_Shelter\Admin\Settings::init();

    // Initialize reports page.
    Starter_Shelter\Admin\Reports::init();

    // Initialize dashboard widget.
    Starter_Shelter\Admin\Dashboard_Widget::init();

    // Initialize custom list columns for CPTs.
    Starter_Shelter\Admin\List_Columns::init();

    // Initialize logo moderation page.
    Starter_Shelter\Admin\Logo_Moderation::init();

    // Initialize auto-generated meta boxes.
    Starter_Shelter\Admin\Meta_Boxes::init();

    // Initialize data integrity tools.
    Starter_Shelter\Admin\Data_Integrity::init();

    // Initialize quick actions and bulk operations.
    Starter_Shelter\Admin\Quick_Actions::init();

    // Initialize import/export tools.
    Starter_Shelter\Admin\Import_Export_Page::init();

    // Initialize legacy order sync.
    Starter_Shelter\Admin\Legacy_Sync_Page::init();

    // Initialize activity log.
    Starter_Shelter\Admin\Activity_Log::init();
}
add_action( 'plugins_loaded', 'starter_shelter_admin_init', 25 );

/**
 * Initialize cron jobs.
 *
 * @since 1.0.0
 */
function starter_shelter_cron_init(): void {
    // Initialize renewal reminder cron.
    Starter_Shelter\Cron\Renewal_Reminder::init();
}
add_action( 'plugins_loaded', 'starter_shelter_cron_init', 25 );

/**
 * Register custom blocks.
 *
 * @since 1.0.0
 */
function starter_shelter_register_blocks(): void {
    // Register block category.
    add_filter( 'block_categories_all', function( array $categories ): array {
        return array_merge(
            [
                [
                    'slug'  => 'starter-shelter',
                    'title' => __( 'Shelter Donations', 'starter-shelter' ),
                    'icon'  => 'heart',
                ],
            ],
            $categories
        );
    } );

    // Register blocks from blocks directory.
    $blocks_dir = STARTER_SHELTER_PATH . 'blocks/';
    
    if ( is_dir( $blocks_dir ) ) {
        $blocks = glob( $blocks_dir . '*/block.json' );
        
        foreach ( $blocks as $block_json ) {
            register_block_type( dirname( $block_json ) );
        }
    }
}
add_action( 'init', 'starter_shelter_register_blocks' );

/**
 * Register REST API endpoints.
 *
 * @since 1.0.0
 */
function starter_shelter_register_rest_api(): void {
    require_once STARTER_SHELTER_PATH . 'includes/rest/class-rest-controller.php';
}
add_action( 'plugins_loaded', 'starter_shelter_register_rest_api', 15 );

/**
 * Plugin activation.
 *
 * @since 1.0.0
 */
function starter_shelter_activate(): void {
    // Run activator tasks (creates products, etc.).
    Starter_Shelter\Core\Activator::activate();
}
register_activation_hook( __FILE__, 'starter_shelter_activate' );

/**
 * Create products after WooCommerce loads (if not already done).
 *
 * @since 2.0.0
 */
function starter_shelter_maybe_setup_products(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    
    Starter_Shelter\Core\Activator::maybe_create_products();
}
add_action( 'admin_init', 'starter_shelter_maybe_setup_products' );

/**
 * Show admin notice if products need setup.
 *
 * @since 2.0.0
 */
function starter_shelter_product_setup_notice(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    if ( ! Starter_Shelter\Core\Activator::products_need_setup() ) {
        return;
    }

    $setup_url = admin_url( 'admin.php?page=starter-shelter-settings&tab=products' );
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php esc_html_e( 'Starter Shelter Donations:', 'starter-shelter' ); ?></strong>
            <?php esc_html_e( 'Some donation products need to be created.', 'starter-shelter' ); ?>
            <a href="<?php echo esc_url( $setup_url ); ?>"><?php esc_html_e( 'Set up products', 'starter-shelter' ); ?></a>
        </p>
    </div>
    <?php
}
add_action( 'admin_notices', 'starter_shelter_product_setup_notice' );

/**
 * Plugin deactivation.
 *
 * @since 1.0.0
 */
function starter_shelter_deactivate(): void {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'starter_shelter_deactivate' );
