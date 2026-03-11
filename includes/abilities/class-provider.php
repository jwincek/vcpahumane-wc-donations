<?php
/**
 * Abilities Provider for registering abilities from config.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Helpers;

/**
 * Registers all abilities defined in config/abilities.json.
 *
 * @since 1.0.0
 */
class Provider {

    /**
     * Callback file paths by namespace.
     *
     * @var array<string, string>
     */
    private static array $callback_files = [
        'shelter-donations'   => 'donations.php',
        'shelter-memberships' => 'memberships.php',
        'shelter-memorials'   => 'memorials.php',
        'shelter-donors'      => 'donors.php',
        'shelter-reports'     => 'reports.php',
    ];

    /**
     * Register all abilities from config.
     *
     * @since 1.0.0
     */
    public static function register_abilities(): void {
        $abilities = Config::get_item( 'abilities', 'abilities', [] );

        // Load callback files.
        self::load_callback_files();

        foreach ( $abilities as $name => $config ) {
            self::register_ability( $name, $config );
        }
    }

    /**
     * Load all callback files.
     *
     * @since 1.0.0
     */
    private static function load_callback_files(): void {
        $base_path = STARTER_SHELTER_PATH . 'includes/abilities/';

        foreach ( self::$callback_files as $namespace => $file ) {
            $file_path = $base_path . $file;
            if ( file_exists( $file_path ) ) {
                require_once $file_path;
            }
        }
    }

    /**
     * Register a single ability.
     *
     * @since 1.0.0
     *
     * @param string $name   The ability name (e.g., 'shelter-donations/create').
     * @param array  $config The ability configuration.
     */
    private static function register_ability( string $name, array $config ): void {
        $execute_callback = self::resolve_callback( $name, $config );
        if ( ! $execute_callback ) {
            return;
        }

        // Resolve permission from string-based permission or callback.
        $permission_callback = self::resolve_permission_callback( $config['permission'] ?? $config['permission_callback'] ?? 'logged_in' );

        $args = [
            'label'               => __( $config['label'] ?? $name, 'starter-shelter' ),
            'description'         => __( $config['description'] ?? '', 'starter-shelter' ),
            'category'            => $config['category'] ?? 'shelter-donations',
            'execute_callback'    => $execute_callback,
            'permission_callback' => $permission_callback,
        ];

        // Add input schema if defined.
        if ( ! empty( $config['input_schema'] ) ) {
            $args['input_schema'] = $config['input_schema'];
        }

        // Add output schema if defined.
        if ( ! empty( $config['output_schema'] ) ) {
            $args['output_schema'] = $config['output_schema'];
        }

        // Add meta if defined.
        if ( ! empty( $config['meta'] ) ) {
            $args['meta'] = $config['meta'];
        } elseif ( ! empty( $config['annotations'] ) || isset( $config['show_in_rest'] ) ) {
            $args['meta'] = [
                'annotations'  => $config['annotations'] ?? [],
                'show_in_rest' => $config['show_in_rest'] ?? false,
            ];
        }

        wp_register_ability( $name, $args );
    }

    /**
     * Resolve the execute callback for an ability.
     *
     * @since 1.0.0
     *
     * @param string $name   The ability name.
     * @param array  $config The ability configuration.
     * @return callable|null The callback or null if not found.
     */
    private static function resolve_callback( string $name, array $config ): ?callable {
        // Check for explicit callback in config.
        if ( ! empty( $config['callback'] ) ) {
            // If it's already callable, use it.
            if ( is_callable( $config['callback'] ) ) {
                return $config['callback'];
            }

            // Try to resolve as function name in Abilities namespace.
            if ( is_string( $config['callback'] ) ) {
                // Handle class::method format (e.g., "Starter_Shelter\\Abilities\\Donations::create")
                if ( str_contains( $config['callback'], '::' ) ) {
                    // Convert class::method to namespace\function format
                    // e.g., "Starter_Shelter\\Abilities\\Donations::create" -> "Starter_Shelter\\Abilities\\Donations\\create"
                    $converted = str_replace( '::', '\\', $config['callback'] );
                    if ( function_exists( $converted ) ) {
                        return $converted;
                    }
                }

                // Try the callback as-is if it's a function.
                if ( function_exists( $config['callback'] ) ) {
                    return $config['callback'];
                }
            }
        }

        // Derive callback from ability name.
        // Format: 'shelter-donations/create' -> 'Starter_Shelter\Abilities\Donations\create'
        $parts     = explode( '/', $name );
        $namespace = str_replace( 'shelter-', '', $parts[0] ?? '' );
        $function  = str_replace( '-', '_', $parts[1] ?? '' );

        // Convert namespace to proper case (e.g., "donations" -> "Donations").
        $namespace = ucfirst( $namespace );
        $callable  = "Starter_Shelter\\Abilities\\{$namespace}\\{$function}";

        if ( function_exists( $callable ) ) {
            return $callable;
        }

        // Log warning for missing callback.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( 'Starter Shelter: Missing callback for ability "%s" (tried: %s)', $name, $callable ) );
        }

        return null;
    }

    /**
     * Resolve permission callback from config value.
     *
     * @since 1.0.0
     *
     * @param string|array|callable $permission The permission configuration.
     * @return callable The permission callback.
     */
    private static function resolve_permission_callback( $permission ): callable {
        // Direct callable.
        if ( is_callable( $permission ) ) {
            return $permission;
        }

        // String-based permission types.
        if ( is_string( $permission ) ) {
            return match ( $permission ) {
                'public' => fn() => true,
                
                'logged_in' => fn() => is_user_logged_in(),
                
                'admin', 'manage_options' => fn() => current_user_can( 'manage_options' ),
                
                'edit_posts' => fn() => current_user_can( 'edit_posts' ),
                
                'edit_others_posts' => fn() => current_user_can( 'edit_others_posts' ),
                
                'owner_or_admin' => function( $input = [] ) {
                    if ( current_user_can( 'manage_options' ) ) {
                        return true;
                    }
                    
                    $user_id = get_current_user_id();
                    if ( ! $user_id ) {
                        return false;
                    }
                    
                    // Check for donor_id in input.
                    if ( isset( $input['donor_id'] ) ) {
                        $donor_user = get_post_meta( $input['donor_id'], '_sd_user_id', true );
                        return (int) $donor_user === $user_id;
                    }
                    
                    // Check for user_id in input.
                    if ( isset( $input['user_id'] ) ) {
                        return (int) $input['user_id'] === $user_id;
                    }
                    
                    return false;
                },
                
                'internal' => fn() => doing_action( 'woocommerce_order_status_completed' ) ||
                    doing_action( 'woocommerce_order_status_processing' ) ||
                    Helpers\is_internal_processing() ||
                    current_user_can( 'manage_options' ),
                
                default => fn() => current_user_can( $permission ),
            };
        }

        // Array-based permission with capability.
        if ( is_array( $permission ) && isset( $permission['capability'] ) ) {
            return fn() => current_user_can( $permission['capability'] );
        }

        // Default: logged in users.
        return fn() => is_user_logged_in();
    }

    /**
     * Get all registered ability names.
     *
     * @since 1.0.0
     *
     * @return array Array of ability names.
     */
    public static function get_ability_names(): array {
        return array_keys( Config::get_item( 'abilities', 'abilities', [] ) );
    }
}
