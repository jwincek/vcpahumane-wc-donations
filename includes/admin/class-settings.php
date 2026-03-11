<?php
/**
 * Admin Settings - Config-driven settings page with tabs.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Core\Activator;

/**
 * Handles plugin settings using the WordPress Settings API.
 *
 * @since 1.0.0
 */
class Settings {

    private const OPTION_GROUP = 'starter_shelter_settings';
    private const OPTION_NAME = 'starter_shelter_options';
    private const PAGE_SLUG = 'starter-shelter-settings';

    private static array $tabs = [
        'general'  => 'General',
        'products' => 'Products',
        'emails'   => 'Emails',
        'pages'    => 'Pages',
    ];

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_init', [ self::class, 'handle_product_actions' ] );
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            Menu::MENU_SLUG,
            __( 'Shelter Donations Settings', 'starter-shelter' ),
            __( 'Settings', 'starter-shelter' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_settings_page' ]
        );
    }

    public static function handle_product_actions(): void {
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_GET['action'] ) && 'create_products' === $_GET['action'] ) {
            check_admin_referer( 'sd_create_products' );
            
            Activator::reset_product_flags();
            Activator::maybe_create_products();
            
            wp_redirect( add_query_arg( [
                'page'    => self::PAGE_SLUG,
                'tab'     => 'products',
                'message' => 'products_created',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['sd_save_product_mappings'] ) ) {
            check_admin_referer( 'sd_product_mappings' );
            
            $product_options = [
                'sd_donation_product_id',
                'sd_membership_product_id',
                'sd_business_membership_product_id',
                'sd_memorial_product_id',
            ];

            foreach ( $product_options as $option ) {
                if ( isset( $_POST[ $option ] ) ) {
                    update_option( $option, absint( $_POST[ $option ] ) );
                }
            }
            
            wp_redirect( add_query_arg( [
                'page'    => self::PAGE_SLUG,
                'tab'     => 'products',
                'message' => 'products_saved',
            ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    private static function get_current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        return array_key_exists( $tab, self::$tabs ) ? $tab : 'general';
    }

    public static function register_settings(): void {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [ self::class, 'sanitize_settings' ],
            'default'           => self::get_defaults(),
        ] );

        self::register_sections();
        self::register_fields();
    }

    private static function register_sections(): void {
        add_settings_section( 'sd_general', __( 'General Settings', 'starter-shelter' ), function() {
            echo '<p>' . esc_html__( 'Configure general plugin settings.', 'starter-shelter' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_organization', __( 'Organization Information', 'starter-shelter' ), function() {
            echo '<p>' . esc_html__( 'Your organization details for receipts and communications.', 'starter-shelter' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_features', __( 'Features', 'starter-shelter' ), function() {
            echo '<p>' . esc_html__( 'Enable or disable plugin features.', 'starter-shelter' ) . '</p>';
        }, self::PAGE_SLUG . '_general' );

        add_settings_section( 'sd_emails', __( 'Email Settings', 'starter-shelter' ), function() {
            echo '<p>' . esc_html__( 'Configure email notifications.', 'starter-shelter' ) . '</p>';
        }, self::PAGE_SLUG . '_emails' );

        add_settings_section( 'sd_pages', __( 'Page Settings', 'starter-shelter' ), function() {
            echo '<p>' . esc_html__( 'Select pages for plugin functionality.', 'starter-shelter' ) . '</p>';
        }, self::PAGE_SLUG . '_pages' );
    }

    private static function register_fields(): void {
        // General tab fields
        self::add_field( 'fiscal_year_start_month', __( 'Fiscal Year Start Month', 'starter-shelter' ), 'sd_general', 'select', [
            'options' => array_combine( range( 1, 12 ), [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ] ),
            'default' => 7,
        ], 'general' );

        self::add_field( 'renewal_reminder_days', __( 'Membership Renewal Reminder (days)', 'starter-shelter' ), 'sd_general', 'number', [
            'default' => 30, 'min' => 7, 'max' => 90,
        ], 'general' );

        self::add_field( 'org_name', __( 'Organization Name', 'starter-shelter' ), 'sd_organization', 'text', [
            'default' => get_bloginfo( 'name' ),
        ], 'general' );

        self::add_field( 'org_ein', __( 'EIN (Tax ID)', 'starter-shelter' ), 'sd_organization', 'text', [
            'placeholder' => 'XX-XXXXXXX',
        ], 'general' );

        self::add_field( 'org_address', __( 'Mailing Address', 'starter-shelter' ), 'sd_organization', 'textarea', [
            'rows' => 3,
        ], 'general' );

        self::add_field( 'org_phone', __( 'Phone Number', 'starter-shelter' ), 'sd_organization', 'text', [], 'general' );

        // Feature toggles
        foreach ( [
            'feature_anonymous_donations'  => 'Allow Anonymous Donations',
            'feature_dedications'          => 'Enable Donation Dedications',
            'feature_family_notifications' => 'Enable Memorial Family Notifications',
            'feature_renewal_reminders'    => 'Send Membership Renewal Reminders',
            'feature_annual_statements'    => 'Send Annual Giving Statements',
        ] as $id => $label ) {
            self::add_field( $id, __( $label, 'starter-shelter' ), 'sd_features', 'checkbox', [ 'default' => true ], 'general' );
        }

        // Email tab fields
        self::add_field( 'email_from_name', __( 'Email From Name', 'starter-shelter' ), 'sd_emails', 'text', [
            'default' => get_bloginfo( 'name' ),
        ], 'emails' );

        self::add_field( 'email_from_address', __( 'Email From Address', 'starter-shelter' ), 'sd_emails', 'email', [
            'default' => get_option( 'admin_email' ),
        ], 'emails' );

        self::add_field( 'logo_moderation_email', __( 'Logo Moderation Notifications', 'starter-shelter' ), 'sd_emails', 'email', [
            'default' => get_option( 'admin_email' ),
            'description' => __( 'Email address for business logo moderation notifications.', 'starter-shelter' ),
        ], 'emails' );

        // Pages tab fields
        foreach ( [
            'memorial_wall_page' => 'Memorial Wall Page',
            'donor_wall_page'    => 'Donor Wall Page',
            'donation_page'      => 'Donation Page',
            'membership_page'    => 'Membership Page',
        ] as $id => $label ) {
            self::add_field( $id, __( $label, 'starter-shelter' ), 'sd_pages', 'page', [ 'default' => 0 ], 'pages' );
        }
    }

    private static function add_field( string $id, string $title, string $section, string $type, array $args = [], string $tab = 'general' ): void {
        add_settings_field( $id, $title, [ self::class, 'render_field' ], self::PAGE_SLUG . '_' . $tab, $section,
            array_merge( $args, [ 'id' => $id, 'type' => $type ] )
        );
    }

    public static function render_field( array $args ): void {
        $options = get_option( self::OPTION_NAME, [] );
        $id      = $args['id'];
        $type    = $args['type'];
        $value   = $options[ $id ] ?? ( $args['default'] ?? '' );
        $name    = self::OPTION_NAME . '[' . $id . ']';

        switch ( $type ) {
            case 'text':
            case 'email':
            case 'url':
                printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
                    esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $args['placeholder'] ?? '' ) );
                break;

            case 'number':
                printf( '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" max="%s" />',
                    esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $args['min'] ?? '' ), esc_attr( $args['max'] ?? '' ) );
                break;

            case 'textarea':
                printf( '<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>',
                    esc_attr( $id ), esc_attr( $name ), (int) ( $args['rows'] ?? 5 ), esc_textarea( $value ) );
                break;

            case 'select':
                echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
                foreach ( $args['options'] ?? [] as $opt_val => $opt_label ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $opt_val ), selected( $value, $opt_val, false ), esc_html( $opt_label ) );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf( '<input type="checkbox" id="%s" name="%s" value="1" %s />', esc_attr( $id ), esc_attr( $name ), checked( $value, true, false ) );
                break;

            case 'page':
                wp_dropdown_pages( [ 'name' => $name, 'id' => $id, 'selected' => $value, 'show_option_none' => '— Select —', 'option_none_value' => 0 ] );
                break;
        }

        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
    }

    public static function sanitize_settings( array $input ): array {
        $sanitized = [];

        foreach ( [ 'org_name', 'org_ein', 'org_phone', 'email_from_name' ] as $f ) {
            $sanitized[ $f ] = sanitize_text_field( $input[ $f ] ?? '' );
        }
        foreach ( [ 'email_from_address', 'logo_moderation_email' ] as $f ) {
            $sanitized[ $f ] = sanitize_email( $input[ $f ] ?? '' );
        }
        $sanitized['org_address'] = sanitize_textarea_field( $input['org_address'] ?? '' );
        $sanitized['fiscal_year_start_month'] = absint( $input['fiscal_year_start_month'] ?? 7 );
        $sanitized['renewal_reminder_days'] = min( 90, max( 7, absint( $input['renewal_reminder_days'] ?? 30 ) ) );

        foreach ( [ 'feature_anonymous_donations', 'feature_dedications', 'feature_family_notifications', 'feature_renewal_reminders', 'feature_annual_statements' ] as $f ) {
            $sanitized[ $f ] = ! empty( $input[ $f ] );
        }
        foreach ( [ 'memorial_wall_page', 'donor_wall_page', 'donation_page', 'membership_page' ] as $f ) {
            $sanitized[ $f ] = absint( $input[ $f ] ?? 0 );
        }

        return $sanitized;
    }

    public static function get_defaults(): array {
        return [
            'fiscal_year_start_month'       => 7,
            'renewal_reminder_days'         => 30,
            'org_name'                      => get_bloginfo( 'name' ),
            'org_ein'                       => '',
            'org_address'                   => '',
            'org_phone'                     => '',
            'email_from_name'               => get_bloginfo( 'name' ),
            'email_from_address'            => get_option( 'admin_email' ),
            'logo_moderation_email'         => get_option( 'admin_email' ),
            'feature_anonymous_donations'   => true,
            'feature_dedications'           => true,
            'feature_family_notifications'  => true,
            'feature_renewal_reminders'     => true,
            'feature_annual_statements'     => true,
            'memorial_wall_page'            => 0,
            'donor_wall_page'               => 0,
            'donation_page'                 => 0,
            'membership_page'               => 0,
        ];
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_tab = self::get_current_tab();
        $message     = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( 'products_created' === $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Products have been created successfully.', 'starter-shelter' ); ?></p></div>
            <?php elseif ( 'products_saved' === $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Product settings have been saved.', 'starter-shelter' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( self::$tabs as $tab_slug => $tab_label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( __( $tab_label, 'starter-shelter' ) ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sd-settings-content" style="margin-top: 20px;">
                <?php
                if ( 'products' === $current_tab ) {
                    self::render_products_tab();
                } else {
                    ?>
                    <form action="options.php" method="post">
                        <?php
                        settings_fields( self::OPTION_GROUP );
                        do_settings_sections( self::PAGE_SLUG . '_' . $current_tab );
                        submit_button( __( 'Save Settings', 'starter-shelter' ) );
                        ?>
                    </form>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function render_products_tab(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is required to manage donation products.', 'starter-shelter' ) . '</p></div>';
            return;
        }

        $product_status = Activator::get_product_status();
        $all_products   = self::get_all_wc_products();
        ?>
        <h2><?php esc_html_e( 'WooCommerce Product Configuration', 'starter-shelter' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Configure which WooCommerce products are used for donations, memberships, and memorials.', 'starter-shelter' ); ?></p>

        <div style="margin: 20px 0; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'products', 'action' => 'create_products' ], admin_url( 'admin.php' ) ), 'sd_create_products' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Auto-Create Missing Products', 'starter-shelter' ); ?>
            </a>
            <p class="description" style="margin-top: 10px;"><?php esc_html_e( 'Creates variable WooCommerce products with standard variations for any missing products.', 'starter-shelter' ); ?></p>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'sd_product_mappings' ); ?>
            
            <table class="widefat striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Product Type', 'starter-shelter' ); ?></th>
                        <th style="width: 15%;"><?php esc_html_e( 'Status', 'starter-shelter' ); ?></th>
                        <th style="width: 40%;"><?php esc_html_e( 'WooCommerce Product', 'starter-shelter' ); ?></th>
                        <th style="width: 20%;"><?php esc_html_e( 'Actions', 'starter-shelter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $product_status as $key => $status ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $status['name'] ); ?></strong>
                                <p class="description"><?php echo esc_html( self::get_product_description( $key ) ); ?></p>
                            </td>
                            <td>
                                <?php if ( $status['exists'] ) : ?>
                                    <span style="color: #00a32a;"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Configured', 'starter-shelter' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #dba617;"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Not Set', 'starter-shelter' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="<?php echo esc_attr( $status['option_key'] ); ?>" style="width: 100%; max-width: 400px;">
                                    <option value="0"><?php esc_html_e( '— Select Product —', 'starter-shelter' ); ?></option>
                                    <?php foreach ( $all_products as $product ) : ?>
                                        <option value="<?php echo esc_attr( $product['id'] ); ?>" <?php selected( $status['product_id'], $product['id'] ); ?>>
                                            <?php echo esc_html( $product['name'] . ' (' . ( $product['sku'] ?: 'No SKU' ) . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ( $status['exists'] && $status['edit_url'] ) : ?>
                                    <a href="<?php echo esc_url( $status['edit_url'] ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Edit', 'starter-shelter' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 1.8;"></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" name="sd_save_product_mappings" class="button button-primary"><?php esc_html_e( 'Save Product Settings', 'starter-shelter' ); ?></button>
            </p>
        </form>

        <div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #c3c4c7;">
            <h3 style="margin-top: 0;"><?php esc_html_e( 'Product Requirements', 'starter-shelter' ); ?></h3>
            <p><?php esc_html_e( 'Each product type requires a Variable Product with specific attributes:', 'starter-shelter' ); ?></p>
            
            <table class="widefat striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Product', 'starter-shelter' ); ?></th>
                        <th><?php esc_html_e( 'SKU', 'starter-shelter' ); ?></th>
                        <th><?php esc_html_e( 'Attribute', 'starter-shelter' ); ?></th>
                        <th><?php esc_html_e( 'Variations', 'starter-shelter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Shelter Donations</td><td><code>shelter-donations</code></td><td>Preferred Allocation</td><td>General Fund, Medical Care, etc.</td></tr>
                    <tr><td>Individual Memberships</td><td><code>shelter-memberships</code></td><td>Membership Level</td><td>Single ($10) - Benefactor ($1000)</td></tr>
                    <tr><td>Business Memberships</td><td><code>shelter-memberships-business</code></td><td>Membership Level</td><td>Contributing ($50) - Benefactor ($1000)</td></tr>
                    <tr><td>Memorial Donations</td><td><code>shelter-donations-in-memoriam</code></td><td>In Memoriam Type</td><td>Person, Pet</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function get_product_description( string $key ): string {
        return [
            'shelter-donations'             => __( 'General donations with allocation options.', 'starter-shelter' ),
            'shelter-memberships'           => __( 'Individual membership tiers.', 'starter-shelter' ),
            'shelter-memberships-business'  => __( 'Business/corporate membership tiers.', 'starter-shelter' ),
            'shelter-donations-in-memoriam' => __( 'Memorial donations for people or pets.', 'starter-shelter' ),
        ][ $key ] ?? '';
    }

    private static function get_all_wc_products(): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $result = [];
        foreach ( wc_get_products( [ 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ] ) as $product ) {
            $result[] = [ 'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku() ];
        }
        return $result;
    }

    public static function get( string $key, $default = null ) {
        $options  = get_option( self::OPTION_NAME, [] );
        $defaults = self::get_defaults();
        return $options[ $key ] ?? $defaults[ $key ] ?? $default;
    }

    public static function is_feature_enabled( string $feature ): bool {
        return (bool) self::get( 'feature_' . $feature, true );
    }
}
